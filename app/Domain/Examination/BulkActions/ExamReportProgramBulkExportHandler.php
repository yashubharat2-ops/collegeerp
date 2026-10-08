<?php

namespace App\Domain\Examination\BulkActions;

use App\Models\College;
use App\Models\Program;
use App\Models\User;
use App\Support\BulkAction\BulkExportHandler;
use Illuminate\Database\Eloquent\Collection;

/**
 * Bulk export of selected PROGRAM-WISE report lines (Exam Reports screen).
 *
 * The report screen has no rows of its own: every figure is a live COUNT over
 * published results, grouped by program (and, on the sibling table, by
 * subject). A program-wise line therefore belongs to a real, college-scoped
 * Program row, which is what the selection posts, and this handler is what
 * authorizes it — re-queried inside the active college, so a foreign college's
 * program id is dropped instead of counted.
 *
 * The export endpoint re-aggregates each selected program server-side with the
 * same filters the screen used (the examination scope is carried as an explicit
 * parameter and re-validated), so the CSV always equals the line the user saw.
 *
 * Authorization is the screen's own `exam_reports.view` permission; the report
 * remains read-only — nothing here writes a result, a mark or a grade.
 */
class ExamReportProgramBulkExportHandler extends BulkExportHandler
{
    public function modelClass(): string
    {
        return Program::class;
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
     * The report group plus the screen's own filters, so the endpoint can
     * reproduce exactly the lines that were on screen.
     *
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    protected function exportParameters(Collection $records, User $user, College $college, array $parameters): array
    {
        return [
            'group' => 'programs',
            'examination_id' => $this->intOrNull($parameters['examination_id'] ?? null),
            'program_id' => $this->intOrNull($parameters['program_id'] ?? null),
        ];
    }

    protected function recordNoun(int $count): string
    {
        return $count === 1 ? 'program report line' : 'program report lines';
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
