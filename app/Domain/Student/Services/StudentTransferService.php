<?php

namespace App\Domain\Student\Services;

use App\Domain\Student\Actions\GenerateTcNumber;
use App\Models\College;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\StudentPromotion;
use App\Models\StudentTransfer;
use App\Services\Audit\AuditLogService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Student transfer / Transfer Certificate (TC) workflow.
 *
 * Active student → transfer request → approved → statuses updated → TC issued.
 *
 * The defining rule of this module: NOTHING IS DELETED. Issuing a TC only
 * changes statuses (the linked enrollment and the student become `withdrawn`)
 * and records the certificate. The Student row and its enrollments, academic
 * records and documents remain, because a transferred student is still part of
 * the institution's history and may return.
 *
 * The TC number is minted server-side by GenerateTcNumber and the optional TC
 * file is stored on the private disk under a tenant-scoped, server-generated
 * path — never a client-supplied one.
 *
 * PROMOTION INTERLOCK: issue() is the single implementation behind BOTH TC
 * entry points — the standalone route and CertificateWorkflow::issue() — so one
 * guard covers them both. It refuses to issue while the student has a PENDING
 * StudentPromotion, because withdrawing the student here would strand that
 * request (PromoteStudent's shared guard could then never approve it) while it
 * stayed listed as actionable. The operator resolves it explicitly; this never
 * cancels a promotion automatically, and only `pending` blocks — an approved
 * promotion has already executed and a later transfer out is a normal sequence.
 * The mirror-image rule (an issued TC blocks a new promotion) lives in
 * PromoteStudent::assertPromotable().
 *
 * Every transition is transactional, tenant-verified and audited.
 */
class StudentTransferService
{
    public const AUDITED = [
        'id', 'student_id', 'enrollment_id', 'transfer_date', 'reason', 'destination_institution',
        'status', 'tc_number', 'tc_issue_date', 'tc_status', 'tc_file_path', 'tc_original_filename',
        'tc_file_size', 'remarks', 'requested_by', 'approved_by', 'approved_at', 'cancelled_at',
    ];

    /** Extensions accepted for an uploaded TC scan/reference. */
    private const ALLOWED_TC_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png'];

    public function __construct(
        private readonly StudentService $students,
        private readonly GenerateTcNumber $tcNumbers,
        private readonly AuditLogService $audit,
    ) {}

    public function create(array $data, int $collegeId, ?int $userId = null): StudentTransfer
    {
        return DB::transaction(function () use ($data, $collegeId, $userId): StudentTransfer {
            $student = Student::withoutGlobalScopes()
                ->where('college_id', $collegeId)
                ->whereKey((int) $data['student_id'])
                ->lockForUpdate()
                ->first();

            if (! $student) {
                abort(404, 'Student not found in this college context.');
            }

            $enrollmentId = $data['enrollment_id'] ?? null;
            if ($enrollmentId) {
                $enrollment = StudentEnrollment::query()->where('student_id', $student->id)->find($enrollmentId);

                if (! $enrollment) {
                    throw ValidationException::withMessages([
                        'enrollment_id' => 'The selected enrollment does not belong to this student in this college.',
                    ]);
                }
            }

            $transfer = StudentTransfer::create([
                'college_id' => $collegeId,
                'student_id' => $student->id,
                'enrollment_id' => $enrollmentId,
                'transfer_date' => $data['transfer_date'],
                'reason' => $data['reason'],
                'destination_institution' => $data['destination_institution'] ?? null,
                'status' => 'pending',
                'tc_status' => 'pending',
                'remarks' => $data['remarks'] ?? null,
                'requested_by' => $userId,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);

            $this->audit->record('student_transfer.requested', $transfer, [], $transfer->only(self::AUDITED));

            return $transfer;
        });
    }

    /**
     * Amend a request. Only allowed while it is still pending: an approved or
     * closed request is a record of what was decided and must not drift.
     */
    public function update(StudentTransfer $transfer, array $data, int $collegeId, ?int $userId = null): StudentTransfer
    {
        return DB::transaction(function () use ($transfer, $data, $collegeId, $userId): StudentTransfer {
            $this->assertOwnedByCollege($transfer, $collegeId);

            if (! $transfer->isPending()) {
                throw ValidationException::withMessages([
                    'status' => 'Only a pending transfer request can be edited. Current status: '.$transfer->status.'.',
                ]);
            }

            $old = $transfer->only(self::AUDITED);

            $transfer->update(array_filter([
                'transfer_date' => $data['transfer_date'] ?? null,
                'reason' => $data['reason'] ?? null,
                'destination_institution' => $data['destination_institution'] ?? null,
            ], fn ($value) => $value !== null && $value !== '') + [
                'remarks' => array_key_exists('remarks', $data) ? $data['remarks'] : $transfer->remarks,
                'updated_by' => $userId,
            ]);

            $this->audit->record('student_transfer.updated', $transfer, $old, $transfer->only(self::AUDITED));

            return $transfer;
        });
    }

    public function approve(StudentTransfer $transfer, int $collegeId, ?int $userId = null): StudentTransfer
    {
        return DB::transaction(function () use ($transfer, $collegeId, $userId): StudentTransfer {
            $this->assertOwnedByCollege($transfer, $collegeId);

            if (! $transfer->isPending()) {
                throw ValidationException::withMessages([
                    'status' => 'Only a pending transfer request can be approved. Current status: '.$transfer->status.'.',
                ]);
            }

            $old = $transfer->only(self::AUDITED);

            $transfer->update([
                'status' => 'approved',
                'approved_by' => $userId,
                'approved_at' => now(),
                'updated_by' => $userId,
            ]);

            $this->audit->record('student_transfer.approved', $transfer, $old, $transfer->only(self::AUDITED));

            return $transfer;
        });
    }

    public function reject(StudentTransfer $transfer, int $collegeId, ?int $userId = null, ?string $remarks = null): StudentTransfer
    {
        return DB::transaction(function () use ($transfer, $collegeId, $userId, $remarks): StudentTransfer {
            $this->assertOwnedByCollege($transfer, $collegeId);

            if (! $transfer->isPending()) {
                throw ValidationException::withMessages([
                    'status' => 'Only a pending transfer request can be rejected. Current status: '.$transfer->status.'.',
                ]);
            }

            $old = $transfer->only(self::AUDITED);

            $transfer->update([
                'status' => 'rejected',
                'approved_by' => $userId,
                'approved_at' => now(),
                'remarks' => $remarks ?? $transfer->remarks,
                'updated_by' => $userId,
            ]);

            $this->audit->record('student_transfer.rejected', $transfer, $old, $transfer->only(self::AUDITED));

            return $transfer;
        });
    }

    /**
     * Issue the TC: mints the number, optionally attaches the scanned TC, and
     * marks the student + linked enrollment as withdrawn. Nothing is deleted.
     */
    public function issue(
        StudentTransfer $transfer,
        int $collegeId,
        ?int $userId = null,
        ?string $issueDate = null,
        ?UploadedFile $file = null,
        bool $collegeLockHeld = false,
    ): StudentTransfer {
        return DB::transaction(function () use ($transfer, $collegeId, $userId, $issueDate, $file, $collegeLockHeld): StudentTransfer {
            $this->assertOwnedByCollege($transfer, $collegeId);

            // CertificateWorkflow already holds College before Certificate →
            // Type → Transfer. The standalone TC route acquires the same root
            // lock here; neither path acquires College after Student/Enrollment.
            if (! $collegeLockHeld) {
                College::query()->whereKey($collegeId)->lockForUpdate()->firstOrFail();
            }

            $transfer = StudentTransfer::where('college_id', $collegeId)->lockForUpdate()->findOrFail($transfer->id);

            if (! $transfer->isApproved()) {
                throw ValidationException::withMessages([
                    'status' => 'Only an approved transfer request can be issued. Current status: '.$transfer->status.'.',
                ]);
            }

            if ($transfer->isTcIssued()) {
                throw ValidationException::withMessages([
                    'tc_status' => 'The transfer certificate has already been issued ('.$transfer->tc_number.').',
                ]);
            }

            $student = Student::withoutGlobalScopes()
                ->where('college_id', $collegeId)
                ->whereKey((int) $transfer->student_id)
                ->lockForUpdate()
                ->first();

            if (! $student) {
                abort(404, 'Student not found in this college context.');
            }

            // Promotion / TC interlock: a PENDING promotion is an unfinished
            // decision about this student's next enrollment. Issuing a TC
            // withdraws the student and the linked enrollment, which would
            // strand that request — it could never be approved again (the
            // shared eligibility guard in PromoteStudent refuses a withdrawn
            // student) yet would stay listed as actionable forever. The
            // operator must resolve it explicitly; nothing is cancelled here.
            //
            // An unlocked read is sufficient and deliberate: this transaction
            // already holds the Student row lock, and every promotion writer
            // takes that same lock first — PromoteStudent::request() locks
            // Student before inserting the pending row, and
            // PromoteStudent::approve() must first acquire the College lock
            // this transaction already holds. No promotion row for this
            // student can therefore appear or change during this check, and no
            // extra lock (or lock-order inversion) is introduced.
            $pendingPromotions = StudentPromotion::withoutGlobalScopes()
                ->where('college_id', $collegeId)
                ->where('student_id', $student->id)
                ->where('status', 'pending')
                ->whereNull('deleted_at')
                ->count();

            if ($pendingPromotions > 0) {
                throw ValidationException::withMessages([
                    'student_id' => $pendingPromotions === 1
                        ? 'This student has a pending promotion request. Approve or cancel it before issuing a transfer certificate; no records were changed.'
                        : 'This student has '.$pendingPromotions.' pending promotion requests. Approve or cancel them before issuing a transfer certificate; no records were changed.',
                ]);
            }

            // Lock every live enrollment for this tenant + student in a stable
            // order. The Student row lock also serializes central enrollment
            // creation, so an active row cannot appear during this check.
            $studentEnrollments = StudentEnrollment::withoutGlobalScopes()
                ->where('college_id', $collegeId)
                ->where('student_id', $student->id)
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $activeEnrollments = $studentEnrollments->where('status', 'active')->values();

            if ($activeEnrollments->count() > 1) {
                throw ValidationException::withMessages([
                    'enrollment_id' => 'This student has multiple active enrollments in this college. Reconcile them before issuing a transfer certificate; no records were changed.',
                ]);
            }

            $onlyActiveEnrollment = $activeEnrollments->first();
            if ($onlyActiveEnrollment && (int) $onlyActiveEnrollment->id !== (int) $transfer->enrollment_id) {
                throw ValidationException::withMessages([
                    'enrollment_id' => 'The student\'s only active enrollment is not linked to this transfer request. Reconcile the request before issuing a transfer certificate; no records were changed.',
                ]);
            }

            $tcIssueDate = $issueDate ?: now()->toDateString();
            $tcNumber = $this->tcNumbers->executeWithCollegeLock($collegeId, substr($tcIssueDate, 0, 4));

            $old = $transfer->only(self::AUDITED);

            $changes = [
                'tc_number' => $tcNumber,
                'tc_issue_date' => $tcIssueDate,
                'tc_status' => 'issued',
                'updated_by' => $userId,
            ];

            if ($file) {
                $changes['tc_file_path'] = $this->storeTcFile($file, $collegeId, (int) $student->id);
                $changes['tc_original_filename'] = $file->getClientOriginalName();
                $changes['tc_file_size'] = $file->getSize();
            }

            $transfer->update($changes);

            // Statuses only — history is preserved.
            if ($transfer->enrollment_id) {
                $enrollment = StudentEnrollment::query()
                    ->where('student_id', $student->id)
                    ->find($transfer->enrollment_id);

                if ($enrollment && $enrollment->status !== 'withdrawn') {
                    $this->students->updateEnrollment($enrollment, ['status' => 'withdrawn'], $collegeId);
                }
            }

            if ($student->status !== 'withdrawn') {
                $this->students->updateStudent($student, ['status' => 'withdrawn']);
            }

            $this->audit->record('student_transfer.issued', $transfer, $old, $transfer->only(self::AUDITED));

            return $transfer->refresh()->load('student');
        });
    }

    /**
     * Cancel a request that has not been issued. The student is untouched.
     */
    public function cancel(StudentTransfer $transfer, int $collegeId, ?int $userId = null, ?string $remarks = null): StudentTransfer
    {
        return DB::transaction(function () use ($transfer, $collegeId, $userId, $remarks): StudentTransfer {
            $this->assertOwnedByCollege($transfer, $collegeId);

            if ($transfer->isTcIssued()) {
                throw ValidationException::withMessages([
                    'status' => 'An issued transfer certificate cannot be cancelled; it is part of the student\'s permanent record.',
                ]);
            }

            if ($transfer->isClosed()) {
                throw ValidationException::withMessages([
                    'status' => 'This transfer request is already '.$transfer->status.'.',
                ]);
            }

            $old = $transfer->only(self::AUDITED);

            $transfer->update([
                'status' => 'cancelled',
                'tc_status' => 'cancelled',
                'cancelled_at' => now(),
                'remarks' => $remarks ?? $transfer->remarks,
                'updated_by' => $userId,
            ]);

            $this->audit->record('student_transfer.cancelled', $transfer, $old, $transfer->only(self::AUDITED));

            return $transfer;
        });
    }

    /**
     * Soft-delete the request row. The student, enrollments and any issued TC
     * are never removed by this.
     */
    public function delete(StudentTransfer $transfer, int $collegeId, ?int $userId = null): void
    {
        $this->assertOwnedByCollege($transfer, $collegeId);

        if ($transfer->isTcIssued()) {
            throw ValidationException::withMessages([
                'status' => 'An issued transfer certificate cannot be deleted; it is part of the student\'s permanent record.',
            ]);
        }

        $snapshot = $transfer->only(self::AUDITED);

        $transfer->update(['updated_by' => $userId]);
        $transfer->delete();

        $this->audit->record('student_transfer.deleted', $transfer, $snapshot, []);
    }

    private function assertOwnedByCollege(StudentTransfer $transfer, int $collegeId): void
    {
        if ((int) $transfer->college_id !== (int) $collegeId) {
            abort(404, 'Transfer request not found in this college context.');
        }
    }

    /**
     * Store an uploaded TC scan under a tenant-scoped private path with a
     * generated name; the client never controls the stored location.
     */
    private function storeTcFile(UploadedFile $file, int $collegeId, int $studentId): string
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());
        if ($extension === '') {
            $extension = strtolower((string) $file->extension());
        }
        $extension = (string) preg_replace('/[^a-z0-9]/', '', $extension);

        if (! in_array($extension, self::ALLOWED_TC_EXTENSIONS, true)) {
            throw ValidationException::withMessages([
                'tc_file' => 'The TC file must be a PDF, JPG or PNG.',
            ]);
        }

        if ((int) $file->getSize() > 5120 * 1024) {
            throw ValidationException::withMessages([
                'tc_file' => 'The TC file exceeds the maximum allowed size of 5120 KB.',
            ]);
        }

        $directory = sprintf('students/%d/%d/tc', $collegeId, $studentId);

        $storedPath = $file->storeAs($directory, Str::uuid()->toString().'.'.$extension, 'private');

        if ($storedPath === false) {
            throw ValidationException::withMessages([
                'tc_file' => 'The TC file could not be stored. Please try again.',
            ]);
        }

        return $storedPath;
    }
}
