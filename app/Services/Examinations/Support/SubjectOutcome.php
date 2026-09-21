<?php

namespace App\Services\Examinations\Support;

use App\Models\ExamResult;
use App\Models\ExamSchedule;

/**
 * Immutable per-subject outcome produced by the result rule engine.
 *
 * Pure value object: it carries no persistence concerns and no reference to
 * ExamMark beyond the numbers the engine decided on. Keeping it separate makes
 * the pass/fail + grade rules unit-testable without a database.
 */
final class SubjectOutcome
{
    private function __construct(
        public readonly int $examScheduleId,
        public readonly int $subjectId,
        public readonly float $maxMarks,
        public readonly float $passingMarks,
        public readonly ?float $obtainedMarks,
        public readonly string $status,
        public readonly ?string $grade,
        public readonly ?string $remarks,
        public readonly bool $valid,
    ) {
    }

    public static function make(
        ExamSchedule $schedule,
        ?float $obtainedMarks,
        string $status,
        ?string $grade,
        ?string $remarks,
        bool $valid = true,
    ): self {
        return new self(
            examScheduleId: (int) $schedule->getKey(),
            subjectId: (int) $schedule->subject_id,
            maxMarks: (float) $schedule->max_marks,
            passingMarks: (float) $schedule->passing_marks,
            obtainedMarks: $obtainedMarks,
            status: $status,
            grade: $grade,
            remarks: $remarks,
            valid: $valid,
        );
    }

    public function passed(): bool
    {
        return $this->status === ExamResult::RESULT_PASS;
    }

    /**
     * Whether a numeric score should be counted towards the result totals.
     *
     * Absent, withheld, incomplete and invalid rows never contribute a score —
     * they must not be silently coerced into a passing 0 or any other value.
     */
    public function countsTowardsScore(): bool
    {
        return $this->valid
            && in_array($this->status, [ExamResult::RESULT_PASS, ExamResult::RESULT_FAIL], true);
    }

    public function toArray(): array
    {
        return [
            'exam_schedule_id' => $this->examScheduleId,
            'subject_id' => $this->subjectId,
            'max_marks' => $this->maxMarks,
            'passing_marks' => $this->passingMarks,
            'obtained_marks' => $this->obtainedMarks,
            'grade' => $this->grade,
            'status' => $this->status,
            'remarks' => $this->remarks,
        ];
    }
}
