<?php

namespace App\Services\Examinations\Support;

use App\Models\Examination;
use App\Models\ExamResult;
use App\Models\GradeScale;

/**
 * Outcome of one (re)calculation run — a plain summary the controller and the
 * audit log can read without touching the database again.
 */
final class ResultCalculationReport
{
    /** @var list<int> */
    public array $resultIds = [];

    public int $processed = 0;

    public function __construct(
        public readonly Examination $examination,
        public readonly ?GradeScale $gradeScale,
        public readonly bool $recalculation,
    ) {
    }

    public function record(ExamResult $result): void
    {
        $this->resultIds[] = (int) $result->getKey();
        $this->processed++;
    }

    public function action(): string
    {
        return $this->recalculation ? 'results.recalculated' : 'results.calculated';
    }

    public function toArray(): array
    {
        return [
            'examination_id' => $this->examination->getKey(),
            'grade_scale_id' => $this->gradeScale?->getKey(),
            'processed' => $this->processed,
            'recalculation' => $this->recalculation,
        ];
    }
}
