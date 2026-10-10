<?php

namespace App\Domain\Inventory\BulkActions;

use App\Models\InventoryMaintenance;
use App\Support\BulkAction\BulkExportHandler;

/**
 * Bulk export of selected maintenance records (Inventory — Asset
 * Maintenance).
 *
 * Each ticked maintenance record is re-queried inside the active college and
 * re-authorized through {@see \App\Policies\InventoryMaintenancePolicy}
 * (`inventory_maintenance.view`). The CSV carries the title, asset, type,
 * status, scheduled / completed dates, cost and the vendor / performed-by —
 * the columns the listing shows. An export never schedules, starts or
 * completes a work order.
 */
class InventoryMaintenanceBulkExportHandler extends BulkExportHandler
{
    public function modelClass(): string
    {
        return InventoryMaintenance::class;
    }

    public function requiredPermission(): ?string
    {
        return 'inventory_maintenance.view';
    }

    public function policyAbility(): ?string
    {
        return 'view';
    }

    protected function exportRouteName(): string
    {
        return 'inventory-maintenances.export';
    }

    protected function recordNoun(int $count): string
    {
        return $count === 1 ? 'maintenance record' : 'maintenance records';
    }
}
