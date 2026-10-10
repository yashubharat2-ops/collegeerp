<?php

namespace App\Domain\Student\Actions;

use App\Domain\Admission\Services\AdmissionConversionLock;
use App\Domain\Student\Services\StudentService;
use App\Models\AcademicYear;
use App\Models\Admission;
use App\Models\Program;
use App\Models\Section;
use App\Models\Student;
use App\Services\Audit\AuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Converts a final Admission into a Student + first Enrollment.
 *
 * The operator reviews (and may complete) the existing Student Create form
 * first; this action then writes through {@see StudentService::createStudent()}
 * so student numbers, the optional first enrollment, duplicate-active
 * protection and tenant checks stay on the existing path. The admission
 * provenance link is set server-side and is never taken from the browser.
 */
class ConvertAdmissionToStudent
{
    public function __construct(
        private readonly StudentService $students,
        private readonly AuditLogService $audit,
        private readonly AdmissionConversionLock $conversionLock,
    ) {}

    /**
     * Converts the admission and returns its student.
     *
     * Duplicate outcome (shared with the application route): if the application
     * already has a student, that student is returned and nothing is written. The
     * caller can tell the two cases apart with `Student::$wasRecentlyCreated`.
     *
     * @param  array<string, mixed>  $profile  Validated Student-form payload
     */
    public function execute(int $admissionId, int $collegeId, array $profile): Student
    {
        return DB::transaction(function () use ($admissionId, $collegeId, $profile): Student {
            // Learn the owning application (tenant-scoped, unlocked), then lock the
            // APPLICATION row first: the same serialization point the application
            // route uses, so the two conversion paths can never interleave.
            $linked = Admission::withoutGlobalScopes()
                ->where('college_id', $collegeId)
                ->whereKey($admissionId)
                ->firstOrFail();

            if (! $linked->application_id) {
                throw ValidationException::withMessages([
                    'admission' => 'This admission is not linked to an application and cannot be converted.',
                ]);
            }

            $this->conversionLock->lockApplication((int) $linked->application_id, $collegeId);

            // Re-read the admission under the application lock (status may have
            // changed while we waited).
            $admission = Admission::withoutGlobalScopes()
                ->where('college_id', $collegeId)
                ->whereKey($admissionId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($admission->status === 'cancelled') {
                throw ValidationException::withMessages([
                    'admission' => 'A cancelled admission cannot be converted to a student.',
                ]);
            }

            $existing = $this->conversionLock->existingStudentFor((int) $admission->application_id, $collegeId);

            if ($existing) {
                return $existing->load(['enrollments']);
            }

            $yearId = $profile['academic_year_id'] ?? $admission->academic_year_id;
            $programId = $profile['program_id'] ?? $admission->program_id;
            $sectionId = $profile['section_id'] ?? null;

            $year = $yearId ? AcademicYear::query()->find($yearId) : null;
            $program = $programId ? Program::query()->find($programId) : null;

            if ($yearId && ! $year) {
                abort(404, 'Academic year not found in this college context.');
            }

            if ($programId && ! $program) {
                abort(404, 'Program not found in this college context.');
            }

            if ($sectionId) {
                $section = Section::query()->find($sectionId);
                if (! $section) {
                    abort(404, 'Section not found in this college context.');
                }
            }

            $payload = $profile;
            $payload['admission_application_id'] = $admission->application_id;
            $payload['status'] = $payload['status'] ?? 'active';
            $payload['admission_date'] = $payload['admission_date']
                ?? $admission->admission_date?->format('Y-m-d')
                ?? now()->toDateString();

            if ($year) {
                $payload['academic_year_id'] = $year->id;
                if ($program) {
                    $payload['program_id'] = $program->id;
                }
                if ($sectionId) {
                    $payload['section_id'] = $sectionId;
                }
                $payload['enrollment_date'] = $payload['enrollment_date']
                    ?? $payload['admission_date'];
            }

            $student = $this->students->createStudent(
                $payload,
                $collegeId,
                $payload['admission_date'],
                $year?->code,
                audit: false,
            );

            $this->audit->record(
                'student.converted',
                $student,
                [],
                [
                    'id' => $student->id,
                    'student_number' => $student->student_number,
                    'admission_id' => $admission->id,
                    'admission_application_id' => $admission->application_id,
                    'academic_year_id' => $year?->id,
                    'program_id' => $program?->id,
                    'first_name' => $student->first_name,
                    'last_name' => $student->last_name,
                ],
            );

            return $student->load(['enrollments']);
        });
    }
}
