<?php

namespace App\Domain\Transport\BulkActions;

use App\Models\StudentTransportFeeAssignment;
use App\Support\BulkAction\BulkExportHandler;

/**
 * Bulk export of selected transport fee assignments (Transport — Transport
 * Fees).
 *
 * Each ticked fee assignment is re-queried inside the active college and
 * re-authorized through {@see \App\Policies\StudentTransportFeeAssignmentPolicy}
 * (`transport_fees.view`). The CSV carries the student, route / stop,
 * structure, period, assigned amount, the LIVE collected / outstanding
 * figures (the same ledger the listing derives from Finance fee_payments) and
 * the status. Amounts are exported as stored — an export never collects,
 * cancels or re-assigns a transport fee.
 */
class StudentTransportFeeAssignmentBulkExportHandler extends BulkExportHandler
{
    public function modelClass(): string
    {
        return StudentTransportFeeAssignment::class;
    }

    public function requiredPermission(): ?string
    {
        return 'transport_fees.view';
    }

    public function policyAbility(): ?string
    {
        return 'view';
    }

    protected function exportRouteName(): string
    {
        return 'transport-fees.export';
    }

    protected function recordNoun(int $count): string
    {
        return $count === 1 ? 'transport fee assignment' : 'transport fee assignments';
    }
}
