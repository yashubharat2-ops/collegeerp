<?php

namespace App\Domain\Student\Services;

use App\Domain\Student\Actions\GenerateStudentNumber;
use App\Domain\Student\Actions\GenerateEnrollmentNumber;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Services\Audit\AuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Service for tenant-safe student and enrollment management.
 *
 * Controllers stay thin: they authorize, then delegate to this service for
 * transactional persistence, server-side number generation (via the college-
 * row-locked generators), and audit recording. Nothing here trusts
 * college_id/student_number/enrollment_number from the client.
 */
class StudentService
{
    public const AUDITED = ['id', 'student_number', 'admission_application_id', 'first_name', 'middle_name', 'last_name', 'email', 'phone', 'alternate_phone', 'gender', 'date_of_birth', 'admission_date', 'address_line_1', 'address_line_2', 'city', 'state', 'postal_code', 'country', 'status'];

    public const AUDITED_ENROLLMENT = ['id', 'enrollment_number', 'student_id', 'academic_year_id', 'program_id', 'enrollment_date', 'status', 'remarks'];

    public function __construct(
        private readonly GenerateStudentNumber $studentNumbers,
        private readonly GenerateEnrollmentNumber $enrollmentNumbers,
        private readonly AuditLogService $audit,
    ) {}

    /**
     * Create a student under a college. The student number is always generated
     * server-side. When $audit is true a "student.created" audit entry is
     * written; conversion flows pass audit:false and record "student.converted"
     * instead, so the two creation paths remain distinguishable.
     */
    public function createStudent(array $data, int $collegeId, ?string $admissionDate = null, ?string $academicYearCode = null, bool $audit = true): Student
    {
        return DB::transaction(function () use ($data, $collegeId, $admissionDate, $academicYearCode, $audit): Student {
            $data['college_id'] = $collegeId;
            $data['student_number'] = $this->studentNumbers->execute($collegeId, $academicYearCode);
            $data['admission_date'] = $admissionDate ?? ($data['admission_date'] ?? now()->toDateString());

            $student = Student::create($data);

            if ($audit) {
                $this->audit->record('student.created', $student, [], $student->only(self::AUDITED));
            }

            return $student;
        });
    }

    public function updateStudent(Student $student, array $data): Student
    {
        $old = $student->only(self::AUDITED);

        $student->update($data);

        $this->audit->record('student.updated', $student, $old, $student->only(self::AUDITED));

        return $student;
    }

    public function deleteStudent(Student $student): void
    {
        $snapshot = $student->only(self::AUDITED);

        $student->delete();

        $this->audit->record('student.deleted', $student, $snapshot, []);
    }

    /**
     * Create an enrollment for a tenant-owned, tenant-scoped student.
     *
     * Tenant-aware validation with transactional duplicate-active protection:
     * the student row is locked for update, the academic year/program are
     * re-resolved through their CollegeScope (foreign-college ids resolve to
     * null), and the enrollment number is generated server-side.
     */
    public function createEnrollment(array $data, int $collegeId, ?string $academicYearCode = null, bool $audit = true): StudentEnrollment
    {
        return DB::transaction(function () use ($data, $collegeId, $academicYearCode, $audit): StudentEnrollment {
            $studentId = $data['student_id'];
            $yearId = $data['academic_year_id'];
            $programId = $data['program_id'] ?? null;

            // Lock the student row to serialize active-duplicate detection, and
            // verify same-college ownership.
            $student = Student::withoutGlobalScopes()
                ->where('college_id', $collegeId)
                ->whereKey($studentId)
                ->lockForUpdate()
                ->first();

            if (! $student) {
                abort(404, 'Student not found in this college context.');
            }

            $year = \App\Models\AcademicYear::query()->find($yearId);
            if (! $year) {
                abort(404, 'Academic year not found in this college context.');
            }

            $program = $programId ? \App\Models\Program::query()->find($programId) : null;
            if ($programId && ! $program) {
                abort(404, 'Program not found in this college context.');
            }

            // No duplicate ACTIVE enrollment for the same student/year/program.
            $duplicate = StudentEnrollment::withoutGlobalScopes()
                ->where('college_id', $collegeId)
                ->where('student_id', $studentId)
                ->where('academic_year_id', $yearId)
                ->where('program_id', $programId)
                ->whereNull('deleted_at')
                ->exists();

            if ($duplicate) {
                throw ValidationException::withMessages([
                    'student_id' => 'An active enrollment already exists for this student, academic year and program.',
                ]);
            }

            $enrollment = StudentEnrollment::create([
                'college_id' => $collegeId,
                'student_id' => $studentId,
                'academic_year_id' => $yearId,
                'program_id' => $programId,
                'enrollment_number' => $this->enrollmentNumbers->execute($collegeId, $academicYearCode ?? $year->code),
                'enrollment_date' => $data['enrollment_date'] ?? now()->toDateString(),
                'status' => $data['status'] ?? 'active',
                'remarks' => $data['remarks'] ?? null,
            ]);

            if ($audit) {
                $this->audit->record('student_enrollment.created', $enrollment, [], $enrollment->only(self::AUDITED_ENROLLMENT));
            }

            return $enrollment;
        });
    }

    /**
     * Update an enrollment. FKs stay tenant-scoped and can never be re-pointed
     * at another college's academic year/program.
     */
    public function updateEnrollment(StudentEnrollment $enrollment, array $data, int $collegeId): StudentEnrollment
    {
        return DB::transaction(function () use ($enrollment, $data, $collegeId): StudentEnrollment {
            if ($enrollment->college_id !== $collegeId) {
                abort(404);
            }

            $year = \App\Models\AcademicYear::query()->find($enrollment->academic_year_id);
            if (! $year) {
                abort(404, 'Academic year not found in this college context.');
            }

            if ($enrollment->program_id) {
                $program = \App\Models\Program::query()->find($enrollment->program_id);
                if (! $program) {
                    abort(404, 'Program not found in this college context.');
                }
            }

            // Reject a status reactivation that would collide with another live
            // enrollment of the same student/year/program.
            $newStatus = $data['status'] ?? $enrollment->status;
            if ($newStatus === 'active' && $enrollment->status !== 'active') {
                $duplicate = StudentEnrollment::withoutGlobalScopes()
                    ->where('college_id', $collegeId)
                    ->where('student_id', $enrollment->student_id)
                    ->where('academic_year_id', $enrollment->academic_year_id)
                    ->where('program_id', $enrollment->program_id)
                    ->whereNull('deleted_at')
                    ->whereKeyNot($enrollment->id)
                    ->exists();

                if ($duplicate) {
                    throw ValidationException::withMessages([
                        'status' => 'Another active enrollment already exists for this student, academic year and program.',
                    ]);
                }
            }

            $old = $enrollment->only(self::AUDITED_ENROLLMENT);
            $enrollment->update($data);
            $this->audit->record('student_enrollment.updated', $enrollment, $old, $enrollment->only(self::AUDITED_ENROLLMENT));

            return $enrollment;
        });
    }

    public function deleteEnrollment(StudentEnrollment $enrollment): void
    {
        $snapshot = $enrollment->only(self::AUDITED_ENROLLMENT);

        $enrollment->delete();

        $this->audit->record('student_enrollment.deleted', $enrollment, $snapshot, []);
    }
}
