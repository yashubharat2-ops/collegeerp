<?php

namespace App\Domain\Student\Actions;

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
    ) {}

    /**
     * @param  array<string, mixed>  $profile  Validated Student-form payload
     */
    public function execute(int $admissionId, int $collegeId, array $profile): Student
    {
        return DB::transaction(function () use ($admissionId, $collegeId, $profile): Student {
            $admission = Admission::withoutGlobalScopes()
                ->where('college_id', $collegeId)
                ->lockForUpdate()
                ->findOrFail($admissionId);

            if ($admission->status === 'cancelled') {
                throw ValidationException::withMessages([
                    'admission' => 'A cancelled admission cannot be converted to a student.',
                ]);
            }

            $existing = Student::withoutGlobalScopes()
                ->where('college_id', $collegeId)
                ->where('admission_application_id', $admission->application_id)
                ->first();

            if ($existing) {
                throw ValidationException::withMessages([
                    'admission' => 'This admission has already been converted to a student.',
                ]);
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
