<?php

namespace App\Domain\Examination\BulkActions;

use App\Models\ExamResult;
use App\Support\BulkAction\BulkExportHandler;

/**
 * Bulk export of the publishing worklist (Result Publishing screen).
 *
 * The screen lists calculated results with their calculation / result /
 * publishing state, unpublished first — that is what an operator acts on — and
 * this action exports exactly the ticked rows as CSV.
 *
 * Authorization is the screen's own `result_publishing.view` permission on the
 * active college (the screen shows unpublished rows to its viewers by design,
 * so the export may contain them too — no wider than the screen). Publishing
 * itself is NOT re-implemented here: the page's existing bulk form posts the
 * same selection to ResultPublishingService through PublishResultRequest, and
 * that one service keeps the eligibility rules and the audit trail.
 */
class ResultPublishingBulkExportHandler extends BulkExportHandler
{
    public function modelClass(): string
    {
        return ExamResult::class;
    }

    public function requiredPermission(): ?string
    {
        return 'result_publishing.view';
    }

    protected function exportRouteName(): string
    {
        return 'result-publishing.export';
    }

    protected function recordNoun(int $count): string
    {
        return $count === 1 ? 'result' : 'results';
    }
}
