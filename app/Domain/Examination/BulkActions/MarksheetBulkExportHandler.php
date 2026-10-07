<?php

namespace App\Domain\Examination\BulkActions;

use App\Models\ExamResult;
use App\Support\BulkAction\BulkExportHandler;
use App\Support\BulkAction\BulkActionHandler;
use Illuminate\Database\Eloquent\Model;

/**
 * Bulk export of the marksheet list.
 *
 * A marksheet is a derived document — there is no `marksheets` table and no
 * model row: the record behind a marksheet line is the PUBLISHED ExamResult it
 * is rendered from, and that is what the selection posts and what this handler
 * authorizes. MarksheetPolicy's published-only rule is applied per record by
 * {@see self::acceptsRecord()}, so an unpublished (or later unpublished) result
 * can never reach the export even if its id is sent by hand.
 *
 * Authorization is the screen's own `marksheets.view` permission on the active
 * college, checked on top of the college-scoped re-query the framework does in
 * {@see BulkActionHandler::execute()}.
 *
 * Export only: a marksheet is printed from its own view (one result at a time),
 * and nothing writes through this screen.
 */
class MarksheetBulkExportHandler extends BulkExportHandler
{
    public function modelClass(): string
    {
        return ExamResult::class;
    }

    public function requiredPermission(): ?string
    {
        return 'marksheets.view';
    }

    protected function acceptsRecord(Model $record): bool
    {
        return $record instanceof ExamResult && $record->isPublished();
    }

    protected function exportRouteName(): string
    {
        return 'marksheets.export';
    }

    protected function recordNoun(int $count): string
    {
        return $count === 1 ? 'marksheet' : 'marksheets';
    }
}
