<?php

namespace App\Domain\Student\Actions;

use App\Domain\Student\Services\StudentService;
use App\Models\AcademicYear;
use App\Models\AdmissionApplication;
use App\Models\Program;
use App\Models\Student;
use App\Services\Audit\AuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Converts an approved/admitted AdmissionApplication into a Student.
 *
 * Idempotent, tenant-verified, transactional foundation for admission →
 * student conversion. Every future module (guardians, documents, ID cards)
 * extends from this boundary without creating premature UI complexity.
 *
 * Guarantees:
 * - The owning application is re-resolved tenant-scoped (cross-college 404s),
 *   and must be in an appropriate admission state (draft/rejected/cancelled
 *   are refused) — admission workflow authorization is never bypassed.
 * - The student number and initial enrollment number are generated server-side
 *   via the college-row-locked number generators.
 * - Person data is snapshotted from the admission applicant, so the Student
 *   stays stable independently of the applicant record.
 * - Processing the same application twice returns the existing student (no
 *   duplicates). The application row is locked first to serialize concurrent
 *   double-submission.
 * - The initial StudentEnrollment is created only when the application carries
 *   an academic year (and program) for the active college.
 */
class ConvertApplicationToStudent
{
    private const CONVERTIBLE = ['approved', 'admitted'];

    public function __construct(
        private readonly StudentService $students,
        private readonly AuditLogService $audit,
    ) {}

    public function execute(int $applicationId, int $collegeId): Student
    {
        return DB::transaction(function () use ($applicationId, $collegeId): Student {
            // Tenant-safe re-resolution: a foreign-college id 404s here.
            $application = AdmissionApplication::withoutGlobalScopes()
                ->where('college_id', $collegeId)
                ->lockForUpdate()
                ->findOrFail($applicationId);

            // Idempotency: a prior conversion of this application returns the
            // existing live student instead of creating a duplicate.
            $existing = Student::withoutGlobalScopes()
                ->where('college_id', $collegeId)
                ->where('admission_application_id', $application->id)
                ->first();

            if ($existing) {
                return $existing;
            }

            // Appropriate admission state only: never convert draft/rejected/
            // cancelled applications.
            if (! in_array($application->status, self::CONVERTIBLE, true)) {
                throw ValidationException::withMessages([
                    'application_id' => 'Only approved or admitted applications can be converted to a student. Current status: '.$application->status,
                ]);
            }

            $yearId = $application->academic_year_id;
            $programId = $application->program_id;

            // Re-resolve (tenant-scoped) the academic year/program so a forged
            // or mutated link can never attach a foreign college's resources.
            $year = $yearId ? AcademicYear::query()->find($yearId) : null;
            $program = $programId ? Program::query()->find($programId) : null;

            if ($yearId && ! $year) {
                abort(404, 'Academic year not found in this college context.');
            }

            if ($programId && ! $program) {
                abort(404, 'Program not found in this college context.');
            }

            // Snapshot person data from the admission applicant (single source
            // of truth during admission) onto the newly enrolled student.
            $applicant = $application->applicant;

            $student = $this->students->createStudent(
                [
                    'admission_application_id' => $application->id,
                    'first_name' => $applicant?->first_name ?? '',
                    'middle_name' => $applicant?->middle_name,
                    'last_name' => $applicant?->last_name,
                    'email' => $applicant?->email,
                    'phone' => $applicant?->phone,
                    'alternate_phone' => $applicant?->alternate_phone,
                    'gender' => $applicant?->gender,
                    'date_of_birth' => $applicant?->date_of_birth?->format('Y-m-d'),
                    'address_line_1' => $applicant?->address,
                    'status' => 'active',
                ],
                $collegeId,
                now()->toDateString(),
                $year?->code,
                audit: false,
            );

            if ($year) {
                $this->students->createEnrollment(
                    [
                        'student_id' => $student->id,
                        'academic_year_id' => $year->id,
                        'program_id' => $program?->id,
                        'enrollment_date' => now()->toDateString(),
                        'status' => 'active',
                    ],
                    $collegeId,
                    $year->code,
                    audit: false,
                );
            }

            $student->load(['admissionApplication.applicant', 'enrollments.academicYear', 'enrollments.program']);

            $this->audit->record(
                'student.converted',
                $student,
                [],
                [
                    'id' => $student->id,
                    'student_number' => $student->student_number,
                    'admission_application_id' => $application->id,
                    'academic_year_id' => $year?->id,
                    'program_id' => $program?->id,
                    'first_name' => $student->first_name,
                    'last_name' => $student->last_name,
                ],
            );

            return $student;
        });
    }
}
