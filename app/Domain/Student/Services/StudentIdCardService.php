<?php

namespace App\Domain\Student\Services;

use App\Models\College;
use App\Models\Student;
use App\Models\StudentEnrollment;
use Illuminate\Support\Collection;

/**
 * Student ID cards as a GENERATED VIEW of existing Student + StudentEnrollment
 * data.
 *
 * Nothing is persisted here and there is no id-card table: a card that stored
 * its own copy of the name/enrollment would drift from the student record it is
 * supposed to represent. This service is the single source of the card's
 * contents, so the single-card view and the bulk "generate ID cards" action can
 * never disagree about which enrollment is used or what the verification payload
 * says.
 */
class StudentIdCardService
{
    /**
     * The enrollment a card prints: the student's current (active) enrollment,
     * else the most recent one — the same deterministic rule the module always
     * used, so a card issued for a completed year still identifies the student.
     */
    public function enrollmentFor(Student $student): ?StudentEnrollment
    {
        return $student->currentEnrollment()
            ?? $student->enrollments
                ->sortByDesc(fn (StudentEnrollment $enrollment) => sprintf(
                    '%011d%011d',
                    $enrollment->academicYear?->starts_on?->timestamp ?? 0,
                    $enrollment->id
                ))
                ->first();
    }

    /**
     * Compact machine-readable payload for the card.
     *
     * Contains only non-sensitive identifiers a verifier can look up: college
     * code, student number and enrollment number.
     */
    public function verificationPayload(Student $student, ?string $enrollmentNumber, ?College $college): string
    {
        return strtoupper(implode('|', array_filter([
            $college?->code ?? 'COLLEGE',
            $student->student_number,
            $enrollmentNumber,
        ])));
    }

    /**
     * Everything one card needs, for a single student.
     *
     * @return array{student: Student, enrollment: ?StudentEnrollment, campus: ?\App\Models\Campus, validUntil: mixed, payload: string}
     */
    public function cardFor(Student $student, ?College $college): array
    {
        $enrollment = $this->enrollmentFor($student);

        return [
            'student' => $student,
            'enrollment' => $enrollment,
            'campus' => $enrollment?->section?->campus,
            'validUntil' => $enrollment?->academicYear?->ends_on,
            'payload' => $this->verificationPayload($student, $enrollment?->enrollment_number, $college),
        ];
    }

    /**
     * Cards for an already authorized, tenant-scoped student collection.
     *
     * @param Collection<int, Student> $students
     * @return array<int, array{student: Student, enrollment: ?StudentEnrollment, campus: ?\App\Models\Campus, validUntil: mixed, payload: string}>
     */
    public function cardsFor(Collection $students, ?College $college): array
    {
        return $students->map(fn (Student $student) => $this->cardFor($student, $college))->all();
    }

    /**
     * The audit entry a generation writes. One entry per student, exactly like
     * the single-card screen, so "who printed what" is answerable from the
     * append-only audit log.
     *
     * @return array<string, mixed>
     */
    public function auditContext(Student $student, ?StudentEnrollment $enrollment): array
    {
        return [
            'id' => $student->id,
            'student_number' => $student->student_number,
            'enrollment_number' => $enrollment?->enrollment_number,
            'academic_year_id' => $enrollment?->academic_year_id,
        ];
    }
}
