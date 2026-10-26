<?php

namespace App\Services\Approval;

use App\Models\ApprovalRequest;
use App\Models\ApprovalSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Services\Audit\AuditLogService;

class ApprovalService
{
    public function __construct(
        private readonly AuditLogService $audit,
    ) {}

    /**
     * Determine if approval is required for this module/action in this college.
     * Admission actions default to required when no setting exists, matching the
     * Approval Settings screen; every other module defaults to not required.
     * An explicit disabled setting always wins.
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

        // Default behavior: admission actions require approval unless explicitly disabled; other modules default to not required
        if (! $setting) {
            if ($module === 'admission') {
                return true;
            }
            return false;
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

            if ((int) $req->requested_by === (int) $deciderId) {
                throw ValidationException::withMessages(['decided_by' => 'You cannot approve your own request.']);
            }

            $decider = User::findOrFail($deciderId);
            if (! $this->canApprove($decider, $req->module, $req->action, $collegeId)) {
                abort(403, 'You are not authorized to approve this request.');
            }

            // Apply the approved change to target
            $this->applyApproval($req, $deciderId);

            $oldStatus = $req->status;
            $req->update([
                'status' => ApprovalRequest::STATUS_APPROVED,
                'decided_by' => $deciderId,
                'decided_at' => now(),
                'decision_reason' => $reason,
            ]);

            $this->audit->record('approval.approved', $req, ['status' => $oldStatus], ['status' => $req->status]);

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

            if ((int) $req->requested_by === (int) $deciderId) {
                throw ValidationException::withMessages(['decided_by' => 'You cannot reject your own request.']);
            }

            $decider = User::findOrFail($deciderId);
            if (! $this->canApprove($decider, $req->module, $req->action, $collegeId)) {
                abort(403, 'You are not authorized to reject this request.');
            }

            // On rejection, preserve approved value unchanged - do not apply payload
            // For admission application, set target status to rejected via service?
            $this->handleRejectionSideEffect($req, $reason);

            $oldStatus = $req->status;
            $req->update([
                'status' => ApprovalRequest::STATUS_REJECTED,
                'decided_by' => $deciderId,
                'decided_at' => now(),
                'decision_reason' => $reason,
            ]);

            $this->audit->record('approval.rejected', $req, ['status' => $oldStatus], ['status' => $req->status, 'reason' => $reason]);

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

    private function handleRejectionSideEffect(ApprovalRequest $request, string $reason): void
    {
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
