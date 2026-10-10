<?php

namespace App\Domain\Transport\BulkActions;

use App\Models\Vehicle;
use App\Support\BulkAction\BulkExportHandler;

/**
 * Bulk export of selected vehicles (Transport — Vehicles).
 *
 * Each ticked vehicle is re-queried inside the active college and
 * re-authorized through {@see \App\Policies\VehiclePolicy}
 * (`vehicles.view`). The CSV carries the columns the listing shows —
 * registration number, type, make, model, seating capacity, the three expiry
 * dates and the status. Read-only by construction: an export never changes a
 * vehicle's status or archives it.
 */
class VehicleBulkExportHandler extends BulkExportHandler
{
    public function modelClass(): string
    {
        return Vehicle::class;
    }

    public function requiredPermission(): ?string
    {
        return 'vehicles.view';
    }

    public function policyAbility(): ?string
    {
        return 'view';
    }

    protected function exportRouteName(): string
    {
        return 'vehicles.export';
    }

    protected function recordNoun(int $count): string
    {
        return $count === 1 ? 'vehicle' : 'vehicles';
    }
}
