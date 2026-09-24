<?php

namespace App\Domain\Inventory\Support;

use App\Models\InventoryCategory;
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
}
