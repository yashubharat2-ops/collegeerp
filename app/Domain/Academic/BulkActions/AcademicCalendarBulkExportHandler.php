<?php

namespace App\Domain\Academic\BulkActions;

use App\Models\AcademicCalendarEvent;
use App\Support\BulkAction\BulkExportHandler;

/**
 * Bulk export of selected academic calendar events.
 *
 * Authorized by the calendar screen's own permission
 * (`academic_calendar.view`) on the active college; the ids are re-queried
 * inside that college, so a foreign college's event can never be exported.
 *
 * Export only — creating, editing and removing events keeps its existing
 * single-record path (`academic_calendar.create|update|delete`).
 */
class AcademicCalendarBulkExportHandler extends BulkExportHandler
{
    public function modelClass(): string
    {
        return AcademicCalendarEvent::class;
    }

    public function requiredPermission(): ?string
    {
        return 'academic_calendar.view';
    }

    protected function exportRouteName(): string
    {
        return 'academic-calendar.export';
    }

    protected function recordNoun(int $count): string
    {
        return $count === 1 ? 'calendar event' : 'calendar events';
    }
}
