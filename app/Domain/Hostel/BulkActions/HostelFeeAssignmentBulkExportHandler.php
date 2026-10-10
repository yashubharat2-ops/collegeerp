<?php

namespace App\Domain\Hostel\BulkActions;

use App\Models\HostelFeeAssignment;
use App\Support\BulkAction\BulkExportHandler;

/**
 * Bulk export of selected hostel fee assignments (Hostel — Hostel Fees).
 *
 * Each ticked fee assignment is re-queried inside the active college and
 * re-authorized through {@see \App\Policies\HostelFeeAssignmentPolicy}
 * (`hostel_fees.view`). The CSV carries the student, hostel / bed,
 * structure, period, assigned amount, the LIVE collected / outstanding figures
 * (the same ledger the listing derives from Finance fee_payments) and the
 * status. Amounts are exported as stored — an export never collects, cancels
 * or re-assigns a hostel fee.
 */
class HostelFeeAssignmentBulkExportHandler extends BulkExportHandler
{
    public function modelClass(): string
    {
        return HostelFeeAssignment::class;
    }

    public function requiredPermission(): ?string
    {
        return 'hostel_fees.view';
    }

    public function policyAbility(): ?string
    {
        return 'view';
    }

    protected function exportRouteName(): string
    {
        return 'hostel-fees.export';
    }

    protected function recordNoun(int $count): string
    {
        return $count === 1 ? 'hostel fee assignment' : 'hostel fee assignments';
    }
}
