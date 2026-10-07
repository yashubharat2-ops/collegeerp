<?php

namespace App\Domain\Academic\BulkActions;

use App\Models\AcademicAttendance;
use App\Support\BulkAction\BulkExportHandler;

/**
 * Bulk export of selected academic attendance rows.
 *
 * `AcademicAttendance` is authorized by the Academics module's own permission
 * (`academic_attendance.view`) — the screen has no policy of its own, exactly
 * like the controller that renders it — so the per-record gate that applies
 * here is the college-scoped re-query inside
 * {@see \App\Support\BulkAction\BulkActionHandler::execute()} plus that
 * permission on the active college.
 *
 * Attendance is deliberately export-only: marking and correcting attendance
 * already has its own authorized path (`academic_attendance.create` /
 * `bulkAttendance`), so no bulk mutation is introduced for it.
 */
class AcademicAttendanceBulkExportHandler extends BulkExportHandler
{
    public function modelClass(): string
    {
        return AcademicAttendance::class;
    }

    public function requiredPermission(): ?string
    {
        return 'academic_attendance.view';
    }

    protected function exportRouteName(): string
    {
        return 'academic-attendance.export';
    }

    protected function recordNoun(int $count): string
    {
        return $count === 1 ? 'attendance record' : 'attendance records';
    }
}
