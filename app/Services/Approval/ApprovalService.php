<?php

namespace App\Services\Approval;

use App\Domain\Student\Services\StudentService;
use App\Domain\Student\Support\RegistrationIdentityGuard;
use App\Models\AcademicYear;
use App\Models\ApprovalRequest;
use App\Models\ApprovalSetting;
use App\Models\FeePayment;
use App\Models\Program;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Services\Audit\AuditLogService;

class ApprovalService
{
    public function __construct(
        private readonly AuditLogService $audit,
        private readonly StudentService $students,
    ) {}

    /**
     * Determine if approval is required for this module/action in this college.
     * Admission actions and student registration default to required when no
     * setting exists, matching the Approval Settings screen; every other module
     * defaults to not required. An explicit disabled setting always wins.
     */
    public function isApprovalRequired(int $collegeId, string $module, string $action): bool
    {
        if (! ApprovalRegistry::isSupported($module, $action)) {
            return false;
        }

        $setting = ApprovalSetting::withoutGlobalScopes()
            ->where('college_id', $collegeId)
            ->where('module', $module)
            ->where('action', $action)
            ->first();

        // Default behavior: admission actions and the unified student
        // registration action require approval unless explicitly disabled; other
        // modules default to not required.
        if (! $setting) {
            return $module === 'admission'
                || ($module === 'student' && $action === 'registration_approval');
        }

        return (bool) $setting->is_required;
    }

    /**
     * Check if the given user can approve this module/action (has permission).
     */
    public function canApprove(User $user, string $module, string $action, ?int $collegeId = null): bool
    {
        $perm = ApprovalRegistry::permissionFor($module, $action);
        if (! $perm) {
            return false;
        }
        return $user->hasPermission($perm, $collegeId);
    }

    /**
     * Whether a user is a trusted authority (Super Admin, or the College Admin
     * of the college that owns the record).
     *
     * Kept here as well as on the User model so the approval framework states
     * the rule in one place: the actor's role is evaluated against the RECORD's
     * college, never the ambient tenant, so a cross-college decision can never
     * be legitimised by the wrong context.
     */
    public function isTrustedAuthority(User $user, int $collegeId): bool
    {
        return $user->isTrustedAuthority($collegeId);
    }

    /**
     * Refuse self-approval/self-rejection for everyone except a trusted
     * authority of the request's own college.
     */
    private function assertMayDecideOwn(ApprovalRequest $request, User $decider, string $verb): void
    {
        if ((int) $request->requested_by !== (int) $decider->getKey()) {
            return;
        }

        if ($this->isTrustedAuthority($decider, (int) $request->college_id)) {
            return;
        }

        throw ValidationException::withMessages([
            'decided_by' => 'You cannot '.$verb.' your own request.',
        ]);
    }

    /**
     * Submit a new approval request. Validates module/action allowlist, prevents arbitrary model injection.
     */
    public function submitRequest(array $data, int $collegeId, int $requestedById): ApprovalRequest
    {
        $module = $data['module'];
        $action = $data['action'];

        if (! ApprovalRegistry::isSupported($module, $action)) {
            throw ValidationException::withMessages(['module' => 'Unsupported approval module/action.']);
        }

        // Validate target if provided
        $targetType = $data['target_type'] ?? $module . '.' . $action;
        $targetId = $data['target_id'] ?? null;

        if ($targetId !== null) {
            $modelClass = ApprovalRegistry::modelClassFor($targetType) ?? ApprovalRegistry::modelClassFor($module . '.' . $action);
            if (! $modelClass) {
                throw ValidationException::withMessages(['target_type' => 'Invalid target type.']);
            }
            // Verify target exists in this college
            $exists = $modelClass::withoutGlobalScopes()
                ->where('college_id', $collegeId)
                ->where('id', $targetId)
                ->exists();
            if (! $exists) {
                abort(404, 'Target record not found in this college.');
            }
        }

        // Prevent duplicate pending request for same target/action/requester
        if ($targetId) {
            $dup = ApprovalRequest::withoutGlobalScopes()
                ->where('college_id', $collegeId)
                ->where('module', $module)
                ->where('action', $action)
                ->where('target_type', $targetType)
                ->where('target_id', $targetId)
                ->where('status', ApprovalRequest::STATUS_PENDING)
                ->exists();
            if ($dup) {
                throw ValidationException::withMessages(['target' => 'A pending approval request already exists for this record.']);
            }
        }

        return DB::transaction(function () use ($collegeId, $module, $action, $targetType, $targetId, $requestedById, $data) {
            $ref = $this->generateReference($collegeId);

            $request = ApprovalRequest::create([
                'college_id' => $collegeId,
                'module' => $module,
                'action' => $action,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'reference_number' => $ref,
                'title' => $data['title'] ?? ApprovalRegistry::labelFor($module, $action),
                'description' => $data['description'] ?? null,
                'payload' => $data['payload'] ?? null,
                'previous_data' => $data['previous_data'] ?? null,
                'status' => ApprovalRequest::STATUS_PENDING,
                'requested_by' => $requestedById,
                'request_reason' => $data['request_reason'] ?? null,
            ]);

            $this->audit->record('approval.requested', $request, [], [
                'module' => $module,
                'action' => $action,
                'target_id' => $targetId,
            ]);

            // Fire notification if available
            $this->notifyApprovers($request);

            return $request;
        });
    }

    public function approve(int $requestId, int $collegeId, int $deciderId, ?string $reason = null): ApprovalRequest
    {
        return DB::transaction(function () use ($requestId, $collegeId, $deciderId, $reason) {
            $req = ApprovalRequest::withoutGlobalScopes()
                ->where('college_id', $collegeId)
                ->whereKey($requestId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($req->status !== ApprovalRequest::STATUS_PENDING) {
                throw ValidationException::withMessages(['status' => 'This request has already been decided.']);
            }

            $decider = User::findOrFail($deciderId);
            if (! $this->canApprove($decider, $req->module, $req->action, $collegeId)) {
                abort(403, 'You are not authorized to approve this request.');
            }

            // Self-approval is refused EXCEPT for a trusted authority — the
            // platform Super Admin or this college's College Admin. Those two
            // reserved system roles are accountable for the action themselves,
            // so requiring a second one of them would deadlock a single-admin
            // college. Every other user must have their own request decided by
            // someone else, whatever permission they hold.
            $this->assertMayDecideOwn($req, $decider, 'approve');

            // Apply the approved change to target
            $this->applyApproval($req, $deciderId);

            $oldStatus = $req->status;
            $req->update([
                'status' => ApprovalRequest::STATUS_APPROVED,
                'decided_by' => $deciderId,
                'decided_at' => now(),
                'decision_reason' => $reason,
            ]);

            // A trusted authority deciding their own request is recorded
            // explicitly, so the audit trail never hides a self-decision.
            $this->audit->record('approval.approved', $req, ['status' => $oldStatus], [
                'status' => $req->status,
                'self_decision' => (int) $req->requested_by === (int) $deciderId,
            ]);

            return $req->fresh();
        });
    }

    public function reject(int $requestId, int $collegeId, int $deciderId, string $reason): ApprovalRequest
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['decision_reason' => 'Rejection requires a reason.']);
        }

        return DB::transaction(function () use ($requestId, $collegeId, $deciderId, $reason) {
            $req = ApprovalRequest::withoutGlobalScopes()
                ->where('college_id', $collegeId)
                ->whereKey($requestId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($req->status !== ApprovalRequest::STATUS_PENDING) {
                throw ValidationException::withMessages(['status' => 'This request has already been decided.']);
            }

            $decider = User::findOrFail($deciderId);
            if (! $this->canApprove($decider, $req->module, $req->action, $collegeId)) {
                abort(403, 'You are not authorized to reject this request.');
            }

            $this->assertMayDecideOwn($req, $decider, 'reject');

            // On rejection, preserve approved value unchanged - do not apply payload
            // For admission application, set target status to rejected via service?
            $this->handleRejectionSideEffect($req, $reason, $deciderId);

            $oldStatus = $req->status;
            $req->update([
                'status' => ApprovalRequest::STATUS_REJECTED,
                'decided_by' => $deciderId,
                'decided_at' => now(),
                'decision_reason' => $reason,
            ]);

            $this->audit->record('approval.rejected', $req, ['status' => $oldStatus], [
                'status' => $req->status,
                'reason' => $reason,
                'self_decision' => (int) $req->requested_by === (int) $deciderId,
            ]);

            return $req->fresh();
        });
    }

    public function cancel(int $requestId, int $collegeId, int $userId): ApprovalRequest
    {
        return DB::transaction(function () use ($requestId, $collegeId, $userId) {
            $req = ApprovalRequest::withoutGlobalScopes()
                ->where('college_id', $collegeId)
                ->whereKey($requestId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($req->status !== ApprovalRequest::STATUS_PENDING) {
                throw ValidationException::withMessages(['status' => 'Only pending requests can be cancelled.']);
            }

            if ((int) $req->requested_by !== (int) $userId) {
                // Allow admin with approve permission to cancel others? For now only owner can cancel
                $user = User::findOrFail($userId);
                if (! $this->canApprove($user, $req->module, $req->action, $collegeId) && $req->requested_by !== $userId) {
                    abort(403);
                }
            }

            // A module-specific withdrawal, so the target never stays stranded
            // in "pending approval" with no request left to decide it.
            $this->handleCancellationSideEffect($req, $userId);

            $oldStatus = $req->status;
            $req->update([
                'status' => ApprovalRequest::STATUS_CANCELLED,
                'decided_by' => $userId,
                'decided_at' => now(),
                'decision_reason' => 'Cancelled by requester',
            ]);

            $this->audit->record('approval.cancelled', $req, ['status' => $oldStatus], ['status' => $req->status]);

            return $req->fresh();
        });
    }

    private function applyApproval(ApprovalRequest $request, int $deciderId): void
    {
        // Module-specific side effects. The former empty `admission_create`
        // branch is gone with its registry entry — see the note in
        // ApprovalRegistry::MODULES['admission'].
        if ($request->module === 'admission' && $request->action === 'application_submit') {
            $this->approveAdmissionApplication($request, $deciderId);
        } elseif ($request->module === 'admission' && $request->action === 'application_status_change') {
            $this->approveAdmissionApplicationStatusChange($request, $deciderId);
        } elseif ($request->module === 'student' && $request->action === 'registration_approval') {
            $this->approveStudentRegistration($request, $deciderId);
        } elseif ($request->module === 'student' && $request->action === 'enrollment_approval') {
            $this->approveStudentEnrollment($request, $deciderId);
        } elseif ($request->module === 'fees' && $request->action === 'fee_collection') {
            $this->approveFeeCollection($request, $deciderId);
        }
        // For student sensitive updates, payload would be applied here
        if ($request->payload && is_array($request->payload) && $request->target_type && $request->target_id) {
            $modelClass = ApprovalRegistry::modelClassFor($request->target_type) ?? ApprovalRegistry::modelClassFor($request->module . '.' . $request->action);
            if ($modelClass && $request->module === 'student' && $request->action === 'student_data_change') {
                $target = $modelClass::withoutGlobalScopes()->where('college_id', $request->college_id)->whereKey($request->target_id)->first();
                if ($target) {
                    $target->update($request->payload);
                    $this->audit->record('approval.applied_student_change', $target, $request->previous_data ?? [], $request->payload);
                }
            }
        }
    }

    private function handleRejectionSideEffect(ApprovalRequest $request, string $reason, int $deciderId): void
    {
        if ($request->module === 'student' && $request->action === 'registration_approval') {
            $this->rejectStudentRegistration($request, $reason);
            return;
        }

        if ($request->module === 'student' && $request->action === 'enrollment_approval') {
            $this->rejectStudentEnrollment($request, $reason);
            return;
        }

        if ($request->module === 'fees' && $request->action === 'fee_collection') {
            $this->rejectFeeCollection($request, $reason, $deciderId);
            return;
        }

        if ($request->module === 'admission' && $request->action === 'application_submit') {
            $modelClass = ApprovalRegistry::modelClassFor($request->module . '.' . $request->action);
            if ($modelClass && $request->target_id) {
                $target = $modelClass::withoutGlobalScopes()->where('college_id', $request->college_id)->whereKey($request->target_id)->first();
                if ($target && method_exists($target, 'update')) {
                    // Set to rejected rather than leaving as submitted pending
                    if (in_array($target->status, ['submitted', 'under_review'], true)) {
                        $old = $target->status;
                        $target->update(['status' => 'rejected', 'remarks' => trim(($target->remarks ?? '') . "\nRejection: " . $reason)]);
                        $this->audit->record('admission_application.status_changed', $target, ['status' => $old], ['status' => 'rejected', 'reason' => $reason]);
                    }
                }
            }
        } elseif ($request->module === 'admission' && $request->action === 'application_status_change') {
            $modelClass = ApprovalRegistry::modelClassFor($request->module . '.' . $request->action);
            if ($modelClass && $request->target_id) {
                $target = $modelClass::withoutGlobalScopes()->where('college_id', $request->college_id)->whereKey($request->target_id)->first();
                if ($target) {
                    // Preserve previous value: do not change status, just log rejection
                    // Could also set remarks
                }
            }
        }
    }

    private function approveAdmissionApplication(ApprovalRequest $request, int $deciderId): void
    {
        $modelClass = ApprovalRegistry::modelClassFor($request->module . '.' . $request->action);
        if (! $modelClass || ! $request->target_id) {
            return;
        }
        $app = $modelClass::withoutGlobalScopes()
            ->where('college_id', $request->college_id)
            ->whereKey($request->target_id)
            ->lockForUpdate()
            ->first();
        if (! $app) {
            return;
        }
        // Advance to next valid stage: if submitted -> under_review or approved based on payload
        $newStatus = $request->payload['status'] ?? 'approved';
        // Validate transition using workflow
        if (! \App\Domain\Admission\Services\AdmissionApplicationWorkflow::canTransition($app->status, $newStatus)) {
            // Default to approved if requested status invalid
            $newStatus = 'approved';
            if (! \App\Domain\Admission\Services\AdmissionApplicationWorkflow::canTransition($app->status, $newStatus)) {
                // If still invalid, try stepwise: pending->under_review->approved chain
                if ($app->status === 'submitted') {
                    $newStatus = 'under_review';
                } else {
                    return;
                }
            }
        }

        $old = $app->status;
        $app->update(['status' => $newStatus, 'updated_by' => $deciderId]);
        $this->audit->record('admission_application.status_changed', $app, ['status' => $old], ['status' => $newStatus, 'via_approval' => $request->id]);
    }

    private function approveAdmissionApplicationStatusChange(ApprovalRequest $request, int $deciderId): void
    {
        $modelClass = ApprovalRegistry::modelClassFor($request->module . '.' . $request->action);
        if (! $modelClass || ! $request->target_id) {
            return;
        }
        $app = $modelClass::withoutGlobalScopes()
            ->where('college_id', $request->college_id)
            ->whereKey($request->target_id)
            ->lockForUpdate()
            ->first();
        if (! $app || empty($request->payload)) {
            return;
        }
        $old = $app->toArray();
        $app->update(array_merge($request->payload, ['updated_by' => $deciderId]));
        $this->audit->record('admission_application.status_changed', $app, $old, $request->payload);
    }

    /**
     * Approve a unified student registration.
     *
     * The Student row already exists (created pending by
     * StudentRegistrationService); approval only changes its workflow state and,
     * when a valid academic context was captured, creates the first existing
     * StudentEnrollment through StudentService. No new Enrollment module,
     * route or screen is introduced.
     */
    private function approveStudentRegistration(ApprovalRequest $request, int $deciderId): void
    {
        if (! $request->target_id) {
            return;
        }

        $student = Student::withoutGlobalScopes()
            ->where('college_id', $request->college_id)
            ->whereKey($request->target_id)
            ->first();

        if (! $student) {
            return;
        }

        // Idempotent: an already approved/legacy student needs no second side
        // effect. A rejected registration must be resubmitted, not approved.
        if ($student->isRegistrationApproved()) {
            return;
        }

        // Final duplicate check, at the moment the student becomes active: a
        // verified identifier (phone, Aadhaar, APAAR) may have been taken by
        // another registration while this request was waiting. The student is
        // excluded from its own check, and nothing is written if it fails.
        try {
            RegistrationIdentityGuard::assertAvailable([
                'phone' => $student->phone,
                'apaar_id' => $student->apaar_id,
                // Decrypted in memory by the model accessor; never logged.
                'aadhaar_number' => $student->aadhaar_number,
            ], (int) $request->college_id, (int) $student->id);
        } catch (\Illuminate\Validation\ValidationException $exception) {
            throw ValidationException::withMessages([
                'registration' => 'This registration duplicates an existing student: '
                    .implode(' ', array_merge(...array_values($exception->errors()))),
            ]);
        }
        if ($student->isRegistrationRejected()) {
            throw ValidationException::withMessages([
                'registration_status' => 'A rejected registration must be resubmitted before it can be approved.',
            ]);
        }

        $payload = is_array($request->payload) ? $request->payload : [];
        $yearId = $payload['academic_year_id'] ?? null;
        $programId = $payload['program_id'] ?? null;
        $sectionId = $payload['section_id'] ?? null;
        $enrollmentCreated = false;
        $enrollmentNote = null;

        // Approve the student FIRST, then create the enrollment. The enrollment
        // write path refuses a student whose registration is not approved (that
        // gate is what keeps pending/rejected registrations out of the academic
        // record), and the approval decision is exactly what makes this student
        // eligible. Both writes stay in this one transaction, so a failure while
        // creating the enrollment rolls the approval back as well.
        $old = $student->only(['status', 'registration_status', 'registration_submitted_at']);

        $student->update([
            'registration_status' => Student::REGISTRATION_APPROVED,
            'status' => 'active',
            'updated_by' => $deciderId,
        ]);

        if ($yearId) {
            $year = AcademicYear::query()->find($yearId);
            if (! $year) {
                throw ValidationException::withMessages([
                    'academic_year_id' => 'The academic year attached to this registration is no longer available in this college.',
                ]);
            }

            if ($programId && ! Program::query()->find($programId)) {
                throw ValidationException::withMessages([
                    'program_id' => 'The program attached to this registration is no longer available in this college.',
                ]);
            }

            if ($sectionId && ! Section::query()->find($sectionId)) {
                throw ValidationException::withMessages([
                    'section_id' => 'The section attached to this registration is no longer available in this college.',
                ]);
            }

            if (! $this->students->hasNonDeletedEnrollmentForTuple(
                (int) $request->college_id,
                (int) $student->id,
                (int) $yearId,
                $programId !== null ? (int) $programId : null,
            )) {
                $this->students->createEnrollment([
                    'student_id' => $student->id,
                    'academic_year_id' => $yearId,
                    // The configured semester / year of study captured at
                    // registration. StudentService re-validates that it belongs
                    // to this year and college and derives the stored label from
                    // it, so the enrollment and the request can never disagree.
                    'academic_term_id' => $payload['academic_term_id'] ?? null,
                    'program_id' => $programId,
                    'section_id' => $sectionId,
                    'semester' => $payload['semester'] ?? null,
                    'enrollment_date' => $payload['enrollment_date'] ?? now()->toDateString(),
                    'status' => 'active',
                ], (int) $request->college_id, $year->code, audit: true);

                $enrollmentCreated = true;
            }
        } else {
            $enrollmentNote = 'Approved without an enrollment: no academic year was captured at registration. Use Students → Enrollments to add one before operational use.';
        }

        $this->audit->record('student.registration_approved', $student, $old, [
            'status' => $student->status,
            'registration_status' => $student->registration_status,
            'enrollment_created' => $enrollmentCreated,
            'note' => $enrollmentNote,
            'approval_request_id' => $request->id,
        ]);
    }

    /**
     * Approve a pending enrollment: `pending_approval` -> `active`.
     *
     * The ApprovalService::approve() transaction has already checked the
     * permission (`student_enrollments.approve`), refused a self-decision unless
     * the decider is a trusted authority, and locked the request row. This method
     * locks the enrollment itself, so two concurrent approvals cannot both apply
     * and an approval can never resurrect a rejected/completed/withdrawn row.
     *
     * Idempotent: an already active enrollment is left alone (the request status
     * guard refuses the second decision earlier), and a terminal row is not
     * reactivated — the approval fails with a field error instead, leaving the
     * request pending for a human to handle.
     */
    private function approveStudentEnrollment(ApprovalRequest $request, int $deciderId): void
    {
        if (! $request->target_id) {
            throw ValidationException::withMessages([
                'target' => 'This approval request has no enrollment attached.',
            ]);
        }

        $enrollment = StudentEnrollment::withoutGlobalScopes()
            ->where('college_id', $request->college_id)
            ->whereKey($request->target_id)
            ->lockForUpdate()
            ->first();

        if (! $enrollment) {
            throw ValidationException::withMessages([
                'target' => 'The enrollment attached to this request no longer exists.',
            ]);
        }

        if ($enrollment->isActive()) {
            return;
        }

        if (! $enrollment->isPendingApproval()) {
            throw ValidationException::withMessages([
                'status' => 'Only an enrollment awaiting approval can be approved. This one is '.$enrollment->statusLabel().'.',
            ]);
        }

        $old = $enrollment->only(['status']);

        $enrollment->update([
            'status' => StudentEnrollment::STATUS_ACTIVE,
            'updated_by' => $deciderId,
        ]);

        $this->audit->record('student_enrollment.approved', $enrollment, $old, [
            'status' => $enrollment->status,
            'approval_request_id' => $request->id,
            'decided_by' => $deciderId,
        ]);
    }

    /**
     * Reject a pending enrollment: `pending_approval` -> `rejected`.
     *
     * A rejection is terminal: the row and its numbers are preserved as history
     * (the Enrollment Number is never reissued), and re-applying is a fresh
     * enrollment rather than a resurrection of this one.
     */
    private function rejectStudentEnrollment(ApprovalRequest $request, string $reason): void
    {
        if (! $request->target_id) {
            return;
        }

        $enrollment = StudentEnrollment::withoutGlobalScopes()
            ->where('college_id', $request->college_id)
            ->whereKey($request->target_id)
            ->lockForUpdate()
            ->first();

        if (! $enrollment || ! $enrollment->isPendingApproval()) {
            // Already decided (active) or never pending: nothing to roll back.
            return;
        }

        $old = $enrollment->only(['status']);

        $enrollment->update([
            'status' => StudentEnrollment::STATUS_REJECTED,
            'remarks' => trim((string) $enrollment->remarks."\nRejection: ".$reason),
        ]);

        $this->audit->record('student_enrollment.rejected', $enrollment, $old, [
            'status' => $enrollment->status,
            'reason' => $reason,
            'approval_request_id' => $request->id,
        ]);
    }

    private function rejectStudentRegistration(ApprovalRequest $request, string $reason): void
    {
        if (! $request->target_id) {
            return;
        }

        $student = Student::withoutGlobalScopes()
            ->where('college_id', $request->college_id)
            ->whereKey($request->target_id)
            ->first();

        if (! $student || $student->isRegistrationApproved()) {
            return;
        }

        $old = $student->only(['status', 'registration_status', 'registration_submitted_at']);
        $student->update([
            'registration_status' => Student::REGISTRATION_REJECTED,
            'status' => 'inactive',
        ]);

        $this->audit->record('student.registration_rejected', $student, $old, [
            'status' => $student->status,
            'registration_status' => $student->registration_status,
            'reason' => $reason,
            'approval_request_id' => $request->id,
        ]);
    }

    /**
     * Finalize a fee collection that was queued as `pending_approval`.
     *
     * Approving is the ONLY transition that turns a pending collection into
     * money. It runs inside ApprovalService::approve()'s transaction, after the
     * self-approval and permission checks, so it can never be reached by the
     * submitter or by a user without `fee_collections.approve`.
     *
     * Idempotent by construction: an already completed collection is left alone
     * (a second approval of the same request is refused earlier, on the request
     * status, and re-finalizing is a no-op), while a rejected or cancelled one
     * is refused outright instead of being resurrected.
     */
    private function approveFeeCollection(ApprovalRequest $request, int $deciderId): void
    {
        $payment = $this->feeCollectionTarget($request);

        if (! $payment || $payment->countsTowardsPaid()) {
            return;
        }

        if (! $payment->isFinalizable()) {
            throw ValidationException::withMessages([
                'status' => 'This collection is '.str_replace('_', ' ', (string) $payment->status).' and can no longer be approved.',
            ]);
        }

        $old = $payment->only(['status', 'collected_at', 'collected_by']);

        $payment->status = FeePayment::STATUS_COMPLETED;
        $payment->collected_by = $payment->collected_by ?: $request->requested_by;
        // The collection becomes money at the moment it is approved.
        $payment->collected_at = now();
        $payment->updated_by = $deciderId;
        $payment->save();

        $this->audit->record('fee_payments.approved', $payment, $old, $payment->only([
            'status', 'collected_at', 'collected_by', 'amount', 'payment_number',
        ]) + ['approval_request_id' => $request->id]);
    }

    /**
     * A rejected collection is kept for the audit trail and never counted as
     * paid: the row keeps its payment number, amount and submitter, and the
     * approver's reason is appended to the remarks.
     */
    private function rejectFeeCollection(ApprovalRequest $request, string $reason, int $deciderId): void
    {
        $payment = $this->feeCollectionTarget($request);

        // Nothing to reject if it already became money (approved elsewhere) or
        // was withdrawn by its submitter.
        if (! $payment || ! $payment->isFinalizable()) {
            return;
        }

        $old = $payment->only(['status', 'remarks']);

        $payment->status = FeePayment::STATUS_REJECTED;
        $payment->remarks = trim(((string) $payment->remarks)."\nRejected: ".$reason);
        $payment->updated_by = $deciderId;
        $payment->save();

        $this->audit->record('fee_payments.rejected', $payment, $old, [
            'status' => $payment->status,
            'reason' => $reason,
            'approval_request_id' => $request->id,
        ]);
    }

    /**
     * The requester withdrew the request before anyone decided it.
     *
     * The collection must not stay in `pending_approval` with no request able to
     * decide it, so it is reversed through the same cancelled state a manual
     * reversal uses. It never counted as paid, so balances are unaffected.
     */
    private function withdrawFeeCollection(ApprovalRequest $request, int $userId): void
    {
        $payment = $this->feeCollectionTarget($request);

        if (! $payment || ! $payment->isFinalizable()) {
            return;
        }

        $old = $payment->only(['status', 'cancelled_by', 'cancelled_at', 'cancellation_reason']);

        $payment->status = FeePayment::STATUS_CANCELLED;
        $payment->cancelled_by = $userId;
        $payment->cancelled_at = now();
        $payment->cancellation_reason = 'Approval request withdrawn before a decision';
        $payment->updated_by = $userId;
        $payment->save();

        $this->audit->record('fee_payments.approval_withdrawn', $payment, $old, [
            'status' => $payment->status,
            'approval_request_id' => $request->id,
        ]);
    }

    /**
     * The payment row a fee-collection approval request points at, inside the
     * request's own college. Soft-deleted rows are invisible, so a deleted
     * collection can never be finalized by approving its stale request.
     */
    private function feeCollectionTarget(ApprovalRequest $request): ?FeePayment
    {
        if (! $request->target_id || $request->target_type !== 'fees.fee_collection') {
            return null;
        }

        return FeePayment::withoutGlobalScopes()
            ->where('college_id', $request->college_id)
            ->whereNull('deleted_at')
            ->whereKey($request->target_id)
            ->first();
    }

    private function handleCancellationSideEffect(ApprovalRequest $request, int $userId): void
    {
        if ($request->module === 'fees' && $request->action === 'fee_collection') {
            $this->withdrawFeeCollection($request, $userId);
        }
    }

    private function generateReference(int $collegeId): string
    {
        return 'APR-' . $collegeId . '-' . now()->format('Ymd') . '-' . str_pad((string) (ApprovalRequest::withoutGlobalScopes()->where('college_id', $collegeId)->count() + 1), 4, '0', STR_PAD_LEFT);
    }

    private function notifyApprovers(ApprovalRequest $request): void
    {
        // Use existing notification infrastructure if available - best effort
        // For now, no-op but hook for future.
    }
}
