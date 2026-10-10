<?php

namespace App\Domain\Hostel\BulkActions;

use App\Models\HostelAttendance;
use App\Support\BulkAction\BulkExportHandler;

/**
 * Bulk export of selected hostel attendance records (Hostel — Hostel
 * Attendance).
 *
 * Each ticked attendance record is re-queried inside the active college and
 * re-authorized through {@see \App\Policies\HostelAttendancePolicy}
 * (`hostel_attendance.view`). The CSV carries the date, student, enrollment,
 * hostel / building / room / bed, status, remarks and the marking audit — the
 * columns the listing shows. The listing's own "Bulk attendance" MARKING
 * screen is a mutation workflow and stays untouched: an export never marks,
 * corrects or deletes an attendance record.
 */
class HostelAttendanceBulkExportHandler extends BulkExportHandler
{
    public function modelClass(): string
    {
        return HostelAttendance::class;
    }

    public function requiredPermission(): ?string
    {
        return 'hostel_attendance.view';
    }

    public function policyAbility(): ?string
    {
        return 'view';
    }

    protected function exportRouteName(): string
    {
        return 'hostel-attendance.export';
    }

    protected function recordNoun(int $count): string
    {
        return $count === 1 ? 'attendance record' : 'attendance records';
    }
}
