<?php

namespace App\Domain\Examination\BulkActions;

use App\Models\College;
use App\Models\Subject;
use App\Models\User;
use App\Support\BulkAction\BulkExportHandler;
use Illuminate\Database\Eloquent\Collection;

/**
 * Bulk export of selected SUBJECT-WISE report lines (Exam Reports screen).
 *
 * Same shape as the program-wise sibling: the subject summary is a live
 * aggregate over published result items, so each line is keyed by the real,
 * college-scoped Subject row it summarises. The ids are re-queried inside the
 * active college before anything is exported, and the endpoint re-aggregates
 * each selected subject with the screen's own filters (examination scope
 * included, re-validated server-side).
 *
 * Authorization is the screen's own `exam_reports.view` permission. Read-only.
 */
class ExamReportSubjectBulkExportHandler extends BulkExportHandler
{
    public function modelClass(): string
    {
        return Subject::class;
    }

    public function requiredPermission(): ?string
    {
        return 'exam_reports.view';
    }

    protected function exportRouteName(): string
    {
        return 'exam-reports.export';
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    protected function exportParameters(Collection $records, User $user, College $college, array $parameters): array
    {
        return [
            'group' => 'subjects',
            'examination_id' => $this->intOrNull($parameters['examination_id'] ?? null),
            'program_id' => $this->intOrNull($parameters['program_id'] ?? null),
        ];
    }

    protected function recordNoun(int $count): string
    {
        return $count === 1 ? 'subject report line' : 'subject report lines';
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
