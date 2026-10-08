<?php

namespace App\Domain\Examination\BulkActions;

use App\Models\ExamMark;
use App\Support\BulkAction\BulkExportHandler;

/**
 * Bulk export of selected marks entries.
 *
 * Ids are re-queried inside the active college and each mark row is
 * re-authorized through {@see \App\Policies\ExamMarkPolicy} (`exam_marks.view`).
 *
 * Export only — and deliberately so. Marks are the single source of truth for
 * every calculated result: no bulk marks edit, bulk pass/fail override or bulk
 * status change is introduced anywhere, because none of them could be safe.
 * Result data is produced by the calculation engine from the stored marks.
 */
class ExamMarkBulkExportHandler extends BulkExportHandler
{
    public function modelClass(): string
    {
        return ExamMark::class;
    }

    public function requiredPermission(): ?string
    {
        return 'exam_marks.view';
    }

    public function policyAbility(): ?string
    {
        return 'view';
    }

    protected function exportRouteName(): string
    {
        return 'exam-marks.export';
    }

    protected function recordNoun(int $count): string
    {
        return $count === 1 ? 'marks entry' : 'marks entries';
    }
}
