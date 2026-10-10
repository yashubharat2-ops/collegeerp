<?php

namespace App\Domain\Inventory\BulkActions;

use App\Models\InventoryItem;
use App\Support\BulkAction\BulkExportHandler;

/**
 * Bulk export of selected items / assets (Inventory — Items / Assets).
 *
 * Each ticked item is re-queried inside the active college and re-authorized
 * through {@see \App\Policies\InventoryItemPolicy}
 * (`inventory_items.view`). The CSV carries the name, code, category, type,
 * brand / model, serial, on-hand quantity with its unit and the status — the
 * columns the listing shows. Quantities are exported as stored; an export
 * never adjusts stock or retires an item.
 */
class InventoryItemBulkExportHandler extends BulkExportHandler
{
    public function modelClass(): string
    {
        return InventoryItem::class;
    }

    public function requiredPermission(): ?string
    {
        return 'inventory_items.view';
    }

    public function policyAbility(): ?string
    {
        return 'view';
    }

    protected function exportRouteName(): string
    {
        return 'inventory-items.export';
    }

    protected function recordNoun(int $count): string
    {
        return $count === 1 ? 'item' : 'items';
    }
}
