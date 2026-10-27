<?php

namespace App\Domain\Student\Services;

use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\StudentRegistrationDraft;

/**
 * The three identifiers of a student, as the registration wizard shows them.
 *
 * | Identifier       | Stored in                          | Allocated when              |
 * | ---------------- | ---------------------------------- | --------------------------- |
 * | Student ID       | students.student_number            | the Student row is created  |
 * | Admission Number | admissions.admission_number        | the admission is finalised  |
 * | Roll Number      | student_enrollments.roll_number    | the enrollment+section is   |
 * |                  |                                    | created                     |
 *
 * Nothing here WRITES an identifier and nothing here invents one: the service
 * only reads what the existing workflows allocated. A value that does not exist
 * yet is reported as `pending` with the reason, so the wizard can show
 * "Pending" instead of a fabricated number or an empty box.
 *
 * The wizard never accepts these values as input (no validation rule carries
 * them, and RegistrationStepRules strips anything outside its field lists), so
 * a returned Student ID can never be edited into a different one.
 */
final class StudentIdentifierService
{
    public const STATE_ASSIGNED = 'assigned';
    public const STATE_PENDING = 'pending';

    /**
     * Identifiers for a draft that is still being filled in.
     *
     * A draft normally has no Student yet (the row is created on submit), so
     * the Student ID is pending — but a rejected registration that is being
     * resubmitted already has one, and it is shown rather than hidden.
     *
     * @return array<string, array{label: string, value: ?string, state: string, note: string}>
     */
    public function forDraft(StudentRegistrationDraft $draft, int $collegeId): array
    {
        $student = null;

        if ($draft->student_id) {
            $student = Student::withoutGlobalScopes()
                ->where('college_id', $collegeId)
                ->whereKey($draft->student_id)
                ->first();
        }

        return $this->forStudent($student, $collegeId);
    }

    /**
     * @return array<string, array{label: string, value: ?string, state: string, note: string}>
     */
    public function forStudent(?Student $student, int $collegeId): array
    {
        $studentNumber = $student?->student_number;

        return [
            'student_id' => [
                'label' => 'Student ID',
                'value' => filled($studentNumber) ? (string) $studentNumber : null,
                'state' => filled($studentNumber) ? self::STATE_ASSIGNED : self::STATE_PENDING,
                'note' => filled($studentNumber)
                    ? 'Permanent identity. It never changes when the student moves to another year, programme or section.'
                    : 'Pending — generated when the registration is submitted.',
            ],
            'admission_number' => [
                'label' => 'Admission Number',
                'value' => $this->admissionNumber($student, $collegeId),
                'state' => $this->admissionNumber($student, $collegeId) !== null ? self::STATE_ASSIGNED : self::STATE_PENDING,
                'note' => $this->admissionNumber($student, $collegeId) !== null
                    ? 'Identity of the admission record this student was admitted through.'
                    : 'Pending — assigned when the admission is finalised. Registration does not mint one.',
            ],
            'roll_number' => [
                'label' => 'Roll Number',
                'value' => $this->rollNumber($student, $collegeId),
                'state' => $this->rollNumber($student, $collegeId) !== null ? self::STATE_ASSIGNED : self::STATE_PENDING,
                'note' => $this->rollNumber($student, $collegeId) !== null
                    ? 'Class / section roll number. Unique within its academic year, programme and section.'
                    : 'Pending — assigned with the class/section enrollment, after approval.',
            ],
        ];
    }

    /**
     * The admission number of the admission this student came through.
     *
     * Students record the application they were converted from; the admission
     * number lives on the admissions row of that application. A student created
     * directly (import, transfer, manual entry) has no admission and therefore
     * no admission number — which is reported as pending, never invented.
     */
    private function admissionNumber(?Student $student, int $collegeId): ?string
    {
        if ($student === null || ! $student->admission_application_id) {
            return null;
        }

        $application = $student->admissionApplication;

        if ($application === null || (int) $application->college_id !== $collegeId) {
            return null;
        }

        $number = $application->admission?->admission_number;

        return filled($number) ? (string) $number : null;
    }

    /**
     * The roll number of the student's current (latest, live) enrollment.
     *
     * Roll numbers are per enrollment because they are per class/section; the
     * most recent one is what the student is known by today.
     */
    private function rollNumber(?Student $student, int $collegeId): ?string
    {
        if ($student === null) {
            return null;
        }

        $enrollment = StudentEnrollment::withoutGlobalScopes()
            ->where('college_id', $collegeId)
            ->where('student_id', $student->id)
            ->whereNull('deleted_at')
            ->whereNotNull('roll_number')
            ->orderByDesc('academic_year_id')
            ->orderByDesc('id')
            ->first();

        return filled($enrollment?->roll_number) ? (string) $enrollment->roll_number : null;
    }
}
