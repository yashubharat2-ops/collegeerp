<?php

namespace App\Domain\Inventory\Services;

use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\InventoryVendor;
use Illuminate\Support\Collection;

/**
 * InventoryDashboardService — read-only, live aggregations for the Inventory
 * Dashboard.
 *
 * Deliberately creates NO dashboard or summary tables: every figure is
 * computed from the Phase 1 masters (categories, items/assets, vendors)
 * through the tenant-scoped models, so the numbers can never drift from the
 * records and never leak across colleges.
 */
class InventoryDashboardService
{
    private const RECENT = 8;

    /**
     * Headline counters.
     *
     * @return array<string, int>
     */
    public function totals(): array
    {
        return [
            'categories' => InventoryCategory::query()->count(),
            'active_categories' => InventoryCategory::query()->where('status', InventoryCategory::STATUS_ACTIVE)->count(),
            'items' => InventoryItem::query()->count(),
            'active_items' => InventoryItem::query()->where('status', InventoryItem::STATUS_ACTIVE)->count(),
            'consumable_items' => InventoryItem::query()->where('item_type', InventoryItem::TYPE_CONSUMABLE)->count(),
            'asset_items' => InventoryItem::query()->where('item_type', InventoryItem::TYPE_ASSET)->count(),
            'vendors' => InventoryVendor::query()->count(),
            'active_vendors' => InventoryVendor::query()->where('status', InventoryVendor::STATUS_ACTIVE)->count(),
        ];
    }

    /**
     * Item counts per category (every category, including empty ones).
     *
     * @return Collection<int, InventoryCategory>
     */
    public function itemsPerCategory(): Collection
    {
        return InventoryCategory::query()
            ->withCount('items')
            ->orderByDesc('items_count')
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'code', 'status']);
    }

    /**
     * The most recently recorded items / assets.
     *
     * @return Collection<int, InventoryItem>
     */
    public function recentItems(): Collection
    {
        return InventoryItem::query()
            ->with('category:id,name,code')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::RECENT)
            ->get();
    }
}
