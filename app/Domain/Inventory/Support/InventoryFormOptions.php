<?php

namespace App\Domain\Inventory\Support;

use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\InventoryVendor;
use Illuminate\Support\Collection;

/**
 * Shared, tenant-scoped option lists for inventory forms and filters.
 *
 * Every query goes through the CollegeScope, so a foreign college's categories
 * can never appear in a dropdown.
 */
class InventoryFormOptions
{
    /**
     * @return Collection<int, InventoryCategory>
     */
    public static function categories(): Collection
    {
        return InventoryCategory::query()
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'code', 'status']);
    }

    /**
     * Vendors for the purchase order forms and filters.
     *
     * @return Collection<int, InventoryVendor>
     */
    public static function vendors(): Collection
    {
        return InventoryVendor::query()
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'code', 'status']);
    }

    /**
     * Items / assets for the purchase order and stock screens.
     *
     * Inactive items are still listed (a dormant catalogue entry may have to be
     * received or written off); the forms flag them the way the category
     * dropdown does.
     *
     * @return Collection<int, InventoryItem>
     */
    public static function items(): Collection
    {
        return InventoryItem::query()
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'code', 'unit', 'item_type', 'quantity', 'status']);
    }
}
