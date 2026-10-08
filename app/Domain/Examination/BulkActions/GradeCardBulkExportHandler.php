<?php

namespace App\Domain\Examination\BulkActions;

use App\Models\ExamResult;
use App\Support\BulkAction\BulkExportHandler;
use App\Support\BulkAction\BulkActionHandler;
use Illuminate\Database\Eloquent\Model;

/**
 * Bulk export of the grade card list.
 *
 * Like a marksheet, a grade card is a derived document rendered from a
 * PUBLISHED ExamResult — there is no `grade_cards` table. The selection posts
 * the underlying result ids, the framework re-queries them inside the active
 * college, and {@see self::acceptsRecord()} applies GradeCardPolicy's
 * published-only rule per record.
 *
 * Authorization is the screen's own `grade_cards.view` permission, checked
 * again by the streaming endpoint. Export only — nothing writes here.
 */
class GradeCardBulkExportHandler extends BulkExportHandler
{
    public function modelClass(): string
    {
        return ExamResult::class;
    }

    public function requiredPermission(): ?string
    {
        return 'grade_cards.view';
    }

    protected function acceptsRecord(Model $record): bool
    {
        return $record instanceof ExamResult && $record->isPublished();
    }

    protected function exportRouteName(): string
    {
        return 'grade-cards.export';
    }

    protected function recordNoun(int $count): string
    {
        return $count === 1 ? 'grade card' : 'grade cards';
    }
}
