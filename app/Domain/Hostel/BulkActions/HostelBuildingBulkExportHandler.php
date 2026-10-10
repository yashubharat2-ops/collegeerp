<?php

namespace App\Domain\Hostel\BulkActions;

use App\Models\HostelBuilding;
use App\Support\BulkAction\BulkExportHandler;

/**
 * Bulk export of selected buildings / blocks (Hostel — Buildings / Blocks).
 *
 * Each ticked building is re-queried inside the active college and
 * re-authorized through {@see \App\Policies\HostelBuildingPolicy}
 * (`hostel_buildings.view`). The CSV carries the building name, code, parent
 * hostel, floor count, derived room / bed counts and status — the columns the
 * listing shows. An export never renames or deactivates a building.
 */
class HostelBuildingBulkExportHandler extends BulkExportHandler
{
    public function modelClass(): string
    {
        return HostelBuilding::class;
    }

    public function requiredPermission(): ?string
    {
        return 'hostel_buildings.view';
    }

    public function policyAbility(): ?string
    {
        return 'view';
    }

    protected function exportRouteName(): string
    {
        return 'hostel-buildings.export';
    }

    protected function recordNoun(int $count): string
    {
        return $count === 1 ? 'building' : 'buildings';
    }
}
