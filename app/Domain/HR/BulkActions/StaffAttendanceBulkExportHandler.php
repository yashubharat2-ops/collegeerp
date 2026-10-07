<?php

namespace App\Domain\HR\BulkActions;

use App\Models\StaffAttendance;
use App\Support\BulkAction\BulkExportHandler;

/**
 * Bulk export of selected staff attendance records (HR — Staff Attendance).
 *
 * Each ticked attendance row is re-queried inside the active college and re-authorized through
 * {@see \App\Policies\StaffAttendancePolicy} (`staff_attendance.view`). Correcting attendance
 * stays a single-record workflow with its own controller action and audit trail, so the bulk
 * selection is export only.
 * Read-only by construction: the export endpoint re-queries the ticked ids inside the active college and the CSV carries the same columns the listing shows. Nothing is written back — no payroll, salary, attendance, leave-approval or employee-status change exists on this route.
 */
class StaffAttendanceBulkExportHandler extends BulkExportHandler
{
    public function modelClass(): string
    {
        return StaffAttendance::class;
    }

    public function requiredPermission(): ?string
    {
        return 'staff_attendance.view';
    }

    public function policyAbility(): ?string
    {
        return 'view';
    }

    protected function exportRouteName(): string
    {
        return 'staff-attendance.export';
    }

    protected function recordNoun(int $count): string
    {
        return $count === 1 ? 'attendance record' : 'attendance records';
    }
}
