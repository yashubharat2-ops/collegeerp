<?php

namespace App\Domain\Admission\Services;

use App\Domain\Admission\Actions\GenerateAdmissionNumber;
use App\Models\AcademicYear;
use App\Models\Admission;
use App\Models\AdmissionApplication;
use App\Models\Program;
use App\Models\Student;
use App\Services\Audit\AuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Service for final admission / enrollment creation.
 *
 * - Duplicate prevention: one admission per application per college (DB unique + check)
 * - Admission number generated server-side
 * - Tenant-scoped validation
 * - Audit trail
 * - Integration boundary: keeps applicant_id as single source of truth for future Student module
 */
class AdmissionService
{
    public function __construct(
        private readonly GenerateAdmissionNumber $numberGenerator,
        private readonly AuditLogService $audit,
        private readonly AdmissionConversionLock $conversionLock,
    ) {}

    /**
     * @param array $data validated data
     * @param int $collegeId from TenantContext
     */
    public function createFromApplication(array $data, int $collegeId): Admission
    {
        return DB::transaction(function () use ($data, $collegeId): Admission {
            // Re-resolve application within tenant to prevent cross-college forged id
            $application = AdmissionApplication::query()->findOrFail($data['application_id']);

            // Application must belong to same college (scope ensures, but double-check)
            if ((int) $application->college_id !== $collegeId) {
                abort(404);
            }

            // Prevent duplicate admission for same application
            $existing = Admission::withoutGlobalScopes()
                ->where('college_id', $collegeId)
                ->where('application_id', $application->id)
                ->first();

            if ($existing) {
                throw ValidationException::withMessages([
                    'application_id' => 'This application already has an admission record: '.$existing->admission_number,
                ]);
            }

            // Only approved/selected applications should become admissions, but allow configurable:
            // If application is not in approved/admitted, still allow if explicitly approved? We enforce approved or admitted or selected via merit.
            // For flexibility, we check status is in allowed set: approved, admitted, submitted, under_review.
            // However we block draft, rejected, cancelled.
            if (in_array($application->status, ['draft', 'rejected', 'cancelled'], true)) {
                throw ValidationException::withMessages([
                    'application_id' => 'Only approved/submitted/under_review applications can be admitted. Current status: '.$application->status,
                ]);
            }

            $academicYearId = $data['academic_year_id'] ?? $application->academic_year_id;
            $programId = $data['program_id'] ?? $application->program_id;

            $year = $academicYearId ? AcademicYear::query()->findOrFail($academicYearId) : null;
            $program = $programId ? Program::query()->findOrFail($programId) : null;

            $yearCode = $year?->code ?? date('Y');
            $admissionNumber = $this->numberGenerator->execute($collegeId, $yearCode);

            $admission = Admission::create([
                'college_id' => $collegeId,
                'academic_year_id' => $academicYearId,
                'program_id' => $programId,
                'application_id' => $application->id,
                'applicant_id' => $application->applicant_id,
                'admission_number' => $admissionNumber,
                'admission_date' => $data['admission_date'] ?? now()->toDateString(),
                'status' => $data['status'] ?? 'active',
                'remarks' => $data['remarks'] ?? null,
            ]);

            // Transition application to admitted if not already
            if ($application->status !== 'admitted') {
                $oldStatus = $application->status;
                $application->update(['status' => 'admitted']);
                $this->audit->record('admission_application.status_changed', $application, ['status' => $oldStatus], ['status' => 'admitted']);
            }

            $this->audit->record('admission.created', $admission, [], $admission->only(['id','admission_number','application_id','applicant_id','academic_year_id','program_id','status']));

            return $admission->load(['applicant','application','academicYear','program']);
        });
    }

    public function updateAdmission(Admission $admission, array $data): Admission
    {
        $old = $admission->only(['status','remarks','admission_date','program_id','academic_year_id']);

        $admission->update($data);

        $this->audit->record('admission.updated', $admission, $old, $admission->only(['status','remarks','admission_date','program_id','academic_year_id']));

        return $admission;
    }

    /**
     * Whether a live (not soft-deleted) Student exists for this admission's
     * application, in the admission's own college. Soft-deleted students do not
     * count: they are already gone from the student records.
     */
    public function hasLiveStudent(Admission $admission): bool
    {
        if (! $admission->application_id) {
            return false;
        }

        return Student::withoutGlobalScopes()
            ->where('college_id', $admission->college_id)
            ->where('admission_application_id', $admission->application_id)
            ->whereNull('deleted_at')
            ->lockForUpdate()
            ->first(['id']) !== null;
    }

    /**
     * Cancel an admission.
     *
     * Blocked while a live student exists for its application: cancelling would
     * leave an active student pointing at a cancelled admission. The student is
     * never auto-cancelled or deleted; the operator must resolve it first.
     *
     * The check runs under the application row lock shared with the conversion
     * routes, so a concurrent conversion cannot slip in between the check and the
     * cancel.
     *
     * @throws ValidationException when a live student exists
     */
    public function cancelAdmission(Admission $admission, ?string $remarks = null): Admission
    {
        return DB::transaction(function () use ($admission, $remarks): Admission {
            if ($admission->application_id) {
                $this->conversionLock->lockApplication(
                    (int) $admission->application_id,
                    (int) $admission->college_id,
                );
            }

            if ($this->hasLiveStudent($admission)) {
                throw ValidationException::withMessages([
                    'admission' => 'This admission cannot be cancelled because a student record already exists for its application. The student was not changed.',
                ]);
            }

            $old = $admission->only(['status']);

            $admission->update([
                'status' => 'cancelled',
                'remarks' => $remarks ?? $admission->remarks,
            ]);

            $this->audit->record('admission.cancelled', $admission, $old, $admission->only(['status']));

            return $admission;
        });
    }
}
