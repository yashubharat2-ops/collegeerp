<?php

namespace App\Domain\Examination\BulkActions;

use App\Models\ExamResult;
use App\Support\BulkAction\BulkExportHandler;

/**
 * Bulk export of the results inside one Result Calculation scope.
 *
 * The calculation screen is a worklist: it drives ResultCalculationService and
 * never edits marks. The rows it lists (once a user may also read results —
 * `results.view`) are ExamResult rows in the selected scope, and this action
 * exports exactly the ticked ones.
 *
 * Authorization is deliberately the Results policy's `view` ability plus the
 * `results.view` permission: the export contains per-student result data, which
 * the `result_calculation.*` permissions alone do not grant. Unpublished rows
 * therefore follow the same rule as everywhere else — visible only to holders
 * of `results.view_unpublished`.
 *
 * No calculation or recalculation is triggered from here: calculate/recalculate
 * stay on their existing, scope-validated endpoints.
 */
class ResultCalculationBulkExportHandler extends BulkExportHandler
{
    public function modelClass(): string
    {
        return ExamResult::class;
    }

    public function requiredPermission(): ?string
    {
        return 'results.view';
    }

    public function policyAbility(): ?string
    {
        return 'view';
    }

    protected function exportRouteName(): string
    {
        return 'result-calculation.export';
    }

    protected function recordNoun(int $count): string
    {
        return $count === 1 ? 'calculation result' : 'calculation results';
    }
}
