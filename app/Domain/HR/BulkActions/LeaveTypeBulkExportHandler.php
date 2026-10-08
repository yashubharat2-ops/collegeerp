<?php

namespace App\Domain\HR\BulkActions;

use App\Models\LeaveType;
use App\Support\BulkAction\BulkExportHandler;

/**
 * Bulk export of selected leave types (HR — Leave Management).
 *
 * Each ticked leave type is re-queried inside the active college and re-authorized through
 * {@see \App\Policies\LeaveTypePolicy} (`leave_types.view`). The request count in the CSV is
 * the same live count the listing shows.
 * Read-only by construction: the export endpoint re-queries the ticked ids inside the active college and the CSV carries the same columns the listing shows. Nothing is written back — no payroll, salary, attendance, leave-approval or employee-status change exists on this route.
 */
class LeaveTypeBulkExportHandler extends BulkExportHandler
{
    public function modelClass(): string
    {
        return LeaveType::class;
    }

    public function requiredPermission(): ?string
    {
        return 'leave_types.view';
    }

    public function policyAbility(): ?string
    {
        return 'view';
    }

    protected function exportRouteName(): string
    {
        return 'leave-types.export';
    }

    protected function recordNoun(int $count): string
    {
        return $count === 1 ? 'leave type' : 'leave types';
    }
}
