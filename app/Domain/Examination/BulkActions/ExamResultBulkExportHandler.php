<?php

namespace App\Domain\Examination\BulkActions;

use App\Models\ExamResult;
use App\Support\BulkAction\BulkExportHandler;

/**
 * Bulk export of selected results (Results screen).
 *
 * ExamResult carries the CollegeScope, so the framework's re-query can only
 * ever return rows of the active college. Per-record authorization is the
 * Results policy's own `view` ability, which is also where the unpublished
 * rule lives: a user without `results.view_unpublished` has every unpublished
 * row skipped and reported as skipped instead of exported.
 *
 * Read-only: results are produced by ResultCalculationService and never edited
 * by hand — no bulk result mutation exists.
 */
class ExamResultBulkExportHandler extends BulkExportHandler
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
        return 'results.export';
    }

    protected function recordNoun(int $count): string
    {
        return $count === 1 ? 'result' : 'results';
    }
}
