<?php

namespace App\Domain\Inventory\Services;

use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\InventoryMaintenance;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;

/** Live category-level catalogue, stock, custody and maintenance rollup. */
class InventoryReportService
{
    public function __construct(private readonly InventoryStockBalanceService $stock)
    {
    }

    /**
     * All counts are per category; no quantities from unlike units are added.
     * Relationships and the ledger subquery remain tenant-scoped throughout.
     *
     * @return LengthAwarePaginator<InventoryCategory>
     */
    public function categories(string $threshold, ?int $categoryId = null): LengthAwarePaginator
    {
        $lowStock = $this->stock->lowStock($threshold)
            ->select('inventory_items.category_id')
            ->selectRaw('COUNT(*) AS low_count')
            ->groupBy('inventory_items.category_id');

        $query = InventoryCategory::query()
            ->select('inventory_categories.*')
            ->withCount([
                'items',
                'items as assets_count' => fn (Builder $q) => $q->where('item_type', InventoryItem::TYPE_ASSET),
                'items as assigned_assets_count' => fn (Builder $q) => $q->where('item_type', InventoryItem::TYPE_ASSET)
                    ->whereHas('assignments', fn (Builder $a) => $a->active()),
                'items as open_maintenance_assets_count' => fn (Builder $q) => $q->where('item_type', InventoryItem::TYPE_ASSET)
                    ->whereHas('maintenances', fn (Builder $m) => $m->where('status', '!=', InventoryMaintenance::STATUS_COMPLETED)),
            ])
            ->leftJoinSub($lowStock, 'low_stock', function (JoinClause $join): void {
                $join->on('low_stock.category_id', '=', 'inventory_categories.id');
            })
            ->selectRaw('COALESCE(low_stock.low_count, 0) AS low_count')
            ->orderBy('inventory_categories.name')
            ->orderBy('inventory_categories.id');

        if ($categoryId !== null) {
            $query->where('inventory_categories.id', $categoryId);
        }

        return $query->paginate(20)->withQueryString();
    }
}
