<?php

namespace App\Domain\Inventory\BulkActions;

use App\Models\InventoryCategory;
use App\Support\BulkAction\BulkExportHandler;

/**
 * Bulk export of selected item categories (Inventory — Item Categories).
 *
 * Each ticked category is re-queried inside the active college and
 * re-authorized through {@see \App\Policies\InventoryCategoryPolicy}
 * (`inventory_categories.view`). The CSV carries the name, code, description,
 * the derived item count and the status — the columns the listing shows. An
 * export never renames or deactivates a category.
 */
class InventoryCategoryBulkExportHandler extends BulkExportHandler
{
    public function modelClass(): string
    {
        return InventoryCategory::class;
    }

    public function requiredPermission(): ?string
    {
        return 'inventory_categories.view';
    }

    public function policyAbility(): ?string
    {
        return 'view';
    }

    protected function exportRouteName(): string
    {
        return 'inventory-categories.export';
    }

    protected function recordNoun(int $count): string
    {
        return $count === 1 ? 'item category' : 'item categories';
    }
}
