<?php

namespace App\Domain\Hostel\BulkActions;

use App\Models\HostelAllocation;
use App\Support\BulkAction\BulkExportHandler;

/**
 * Bulk export of selected hostel allocations (Hostel — Hostel Allocation).
 *
 * Each ticked allocation is re-queried inside the active college and
 * re-authorized through {@see \App\Policies\HostelAllocationPolicy}
 * (`hostel_allocations.view`). The CSV carries the student, enrollment,
 * academic year, hostel / building / room / bed, allocation date, vacated date
 * and status — the columns the listing shows. No allocation is created,
 * vacated or cancelled by an export; those stay single-record workflows.
 */
class HostelAllocationBulkExportHandler extends BulkExportHandler
{
    public function modelClass(): string
    {
        return HostelAllocation::class;
    }

    public function requiredPermission(): ?string
    {
        return 'hostel_allocations.view';
    }

    public function policyAbility(): ?string
    {
        return 'view';
    }

    protected function exportRouteName(): string
    {
        return 'hostel-allocations.export';
    }

    protected function recordNoun(int $count): string
    {
        return $count === 1 ? 'allocation' : 'allocations';
    }
}
