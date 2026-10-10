<?php

namespace App\Domain\Hostel\BulkActions;

use App\Models\HostelBed;
use App\Support\BulkAction\BulkExportHandler;

/**
 * Bulk export of selected beds (Hostel — Beds).
 *
 * Each ticked bed is re-queried inside the active college and re-authorized
 * through {@see \App\Policies\HostelBedPolicy} (`hostel_beds.view`). The CSV
 * carries the bed number, room, building, hostel, description and status —
 * the columns the listing shows. Occupancy is derived from allocations and is
 * not recomputed here; an export never allocates or vacates a bed.
 */
class HostelBedBulkExportHandler extends BulkExportHandler
{
    public function modelClass(): string
    {
        return HostelBed::class;
    }

    public function requiredPermission(): ?string
    {
        return 'hostel_beds.view';
    }

    public function policyAbility(): ?string
    {
        return 'view';
    }

    protected function exportRouteName(): string
    {
        return 'hostel-beds.export';
    }

    protected function recordNoun(int $count): string
    {
        return $count === 1 ? 'bed' : 'beds';
    }
}
