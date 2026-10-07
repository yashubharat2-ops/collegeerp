<?php

namespace App\Domain\Academic\BulkActions;

use App\Models\AcademicTimetable;
use App\Support\BulkAction\BulkExportHandler;

/**
 * Bulk export of selected timetable entries.
 *
 * Ids are re-queried inside the active college and each entry is re-authorized
 * through {@see \App\Policies\AcademicTimetablePolicy} (`academic_timetables.view`).
 *
 * Read-only on purpose: timetable conflicts are validated by
 * `AcademicsController::ensureNoConflict()` on the single-record write path, so
 * no bulk mutation is offered here.
 */
class AcademicTimetableBulkExportHandler extends BulkExportHandler
{
    public function modelClass(): string
    {
        return AcademicTimetable::class;
    }

    public function requiredPermission(): ?string
    {
        return 'academic_timetables.view';
    }

    public function policyAbility(): ?string
    {
        return 'view';
    }

    protected function exportRouteName(): string
    {
        return 'academic-timetables.export';
    }

    protected function recordNoun(int $count): string
    {
        return $count === 1 ? 'timetable entry' : 'timetable entries';
    }
}
