<?php

namespace App\Models;

/**
 * StudentResultHistory — a derived, read-only examination timeline for one
 * student (Examinations Phase 4).
 *
 * This is deliberately NOT an Eloquent model: there is no history table and
 * nothing is duplicated into one. The timeline is assembled live from the
 * student's published ExamResult rows (plus their ExamResultItems) across
 * academic years and terms.
 *
 * The wrapper exists so the read-only timeline has its own authorization
 * boundary: StudentResultHistoryPolicy is registered against this class,
 * keeping the `student_result_history.view` permission separate from the
 * student master-data policy.
 */
final class StudentResultHistory
{
    public function __construct(public readonly Student $student)
    {
    }

    public static function forStudent(Student $student): self
    {
        return new self($student);
    }

    public function getKey(): mixed
    {
        return $this->student->getKey();
    }

    public function getRouteKey(): mixed
    {
        return $this->student->getRouteKey();
    }
}
