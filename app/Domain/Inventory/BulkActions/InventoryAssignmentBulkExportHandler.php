<?php

namespace App\Domain\Inventory\BulkActions;

use App\Models\InventoryAssignment;
use App\Support\BulkAction\BulkExportHandler;

/**
 * Bulk export of selected asset assignments (Inventory — Asset Assignment).
 *
 * Each ticked assignment is re-queried inside the active college and
 * re-authorized through {@see \App\Policies\InventoryAssignmentPolicy}
 * (`inventory_assignments.view`). The CSV carries the asset, serial,
 * assignee, purpose, assigned / returned dates and the status — the custody
 * history the listing shows. An export never assigns or returns an asset.
 */
class InventoryAssignmentBulkExportHandler extends BulkExportHandler
{
    public function modelClass(): string
    {
        return InventoryAssignment::class;
    }

    public function requiredPermission(): ?string
    {
        return 'inventory_assignments.view';
    }

    public function policyAbility(): ?string
    {
        return 'view';
    }

    protected function exportRouteName(): string
    {
        return 'inventory-assignments.export';
    }

    protected function recordNoun(int $count): string
    {
        return $count === 1 ? 'assignment' : 'assignments';
    }
}
