<?php

namespace App\Domain\HR\BulkActions;

use App\Models\LeaveRequest;
use App\Support\BulkAction\BulkExportHandler;

/**
 * Bulk export of selected leave requests (HR — Leave Management).
 *
 * Each ticked request is re-queried inside the active college and re-authorized through
 * {@see \App\Policies\LeaveRequestPolicy} (`leave_requests.view`). Approving, rejecting and
 * cancelling leave change an employee’s balance and an overlap rule validated by
 * LeaveRequestService, so they stay single-record decisions — bulk here is export only.
 * Read-only by construction: the export endpoint re-queries the ticked ids inside the active college and the CSV carries the same columns the listing shows. Nothing is written back — no payroll, salary, attendance, leave-approval or employee-status change exists on this route.
 */
class LeaveRequestBulkExportHandler extends BulkExportHandler
{
    public function modelClass(): string
    {
        return LeaveRequest::class;
    }

    public function requiredPermission(): ?string
    {
        return 'leave_requests.view';
    }

    public function policyAbility(): ?string
    {
        return 'view';
    }

    protected function exportRouteName(): string
    {
        return 'leave-requests.export';
    }

    protected function recordNoun(int $count): string
    {
        return $count === 1 ? 'leave request' : 'leave requests';
    }
}
