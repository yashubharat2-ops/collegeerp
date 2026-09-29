<?php

namespace App\Domain\Inventory\Services;

use App\Models\InventoryAssignment;
use App\Models\InventoryCategory;
use App\Models\InventoryIssue;
use App\Models\InventoryItem;
use App\Models\InventoryMaintenance;
use App\Models\InventoryPurchaseOrder;
use App\Models\InventoryPurchaseOrderItem;
use App\Models\InventoryStockMovement;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/** Read-only, tenant-scoped reports over the existing Inventory data. */
class InventoryReportService
{
    private const PER_PAGE = 20;

    public function __construct(private readonly InventoryStockBalanceService $stock)
    {
    }

    /**
     * Current stock is always projected by InventoryStockBalanceService from
     * the immutable movement ledger; the cached item quantity is not used.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<InventoryItem>
     */
    public function currentStock(array $filters): LengthAwarePaginator
    {
        $query = $this->stock->itemsWithBalance()
            ->with('category:id,name,code');

        $this->applyItemFilters($query, $filters);

        if ($filters['item_type'] ?? null) {
            $query->where('inventory_items.item_type', $filters['item_type']);
        }
        if ($filters['item_status'] ?? null) {
            $query->where('inventory_items.status', $filters['item_status']);
        }

        return $query
            ->orderBy('inventory_items.name')
            ->orderBy('inventory_items.id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    /**
     * Low stock uses the same authoritative ledger balance as Current Stock
     * and the existing lowStock query (active consumables at/below threshold).
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<InventoryItem>
     */
    public function lowStock(array $filters): LengthAwarePaginator
    {
        $query = $this->stock->lowStock($filters['threshold'])
            ->with('category:id,name,code');

        $this->applyItemFilters($query, $filters);

        return $query
            ->orderBy('inventory_items.name')
            ->orderBy('inventory_items.id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    /**
     * Immutable stock ledger rows, including archived item labels for history.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<InventoryStockMovement>
     */
    public function transactions(array $filters): LengthAwarePaginator
    {
        $query = InventoryStockMovement::query()->with($this->movementRelations());
        $this->applyMovementFilters($query, $filters);

        if ($filters['transaction_type'] ?? null) {
            $query->where('inventory_stock_movements.type', $filters['transaction_type']);
        }
        if ($filters['direction'] ?? null) {
            $query->where('inventory_stock_movements.direction', $filters['direction']);
        }
        $this->applyDateRange($query, $filters, 'inventory_stock_movements.movement_date');

        return $query
            ->orderByDesc('inventory_stock_movements.movement_date')
            ->orderByDesc('inventory_stock_movements.id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    /**
     * The existing Stock Adjustment screen's ledger entries: adjustments and
     * manually recorded stock-outs. No separate adjustment records are made.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<InventoryStockMovement>
     */
    public function adjustments(array $filters): LengthAwarePaginator
    {
        $query = InventoryStockMovement::query()
            ->whereIn('inventory_stock_movements.type', [
                InventoryStockMovement::TYPE_ADJUSTMENT,
                InventoryStockMovement::TYPE_STOCK_OUT,
            ])
            ->with($this->movementRelations());
        $this->applyMovementFilters($query, $filters);

        if ($filters['adjustment_type'] ?? null) {
            $query->where('inventory_stock_movements.type', $filters['adjustment_type']);
        }
        if ($filters['direction'] ?? null) {
            $query->where('inventory_stock_movements.direction', $filters['direction']);
        }
        $this->applyDateRange($query, $filters, 'inventory_stock_movements.movement_date');

        return $query
            ->orderByDesc('inventory_stock_movements.movement_date')
            ->orderByDesc('inventory_stock_movements.id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    /**
     * Ordered item lines and actual goods-receipt ledger rows are returned
     * separately because the schema records each receipt as a movement.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<InventoryPurchaseOrderItem>
     */
    public function purchaseOrderLines(array $filters): LengthAwarePaginator
    {
        $query = InventoryPurchaseOrderItem::query()
            ->with([
                'purchaseOrder.vendor',
                'item' => fn (Builder $item) => $item->withTrashed()->with([
                    'category' => fn (Builder $category) => $category->withTrashed(),
                ]),
            ])
            ->whereHas('purchaseOrder', function (Builder $order) use ($filters): void {
                $this->applyPurchaseOrderFilters($order, $filters);
            });

        $this->applyPurchaseItemFilters($query, $filters);

        return $query
            ->orderByDesc('inventory_purchase_order_items.created_at')
            ->orderByDesc('inventory_purchase_order_items.id')
            ->paginate(self::PER_PAGE, ['*'], 'orders_page')
            ->withQueryString();
    }

    /**
     * Goods receipts are the existing purchase_receipt and manual stock_in
     * movements; quantities are not recalculated or duplicated.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<InventoryStockMovement>
     */
    public function goodsReceipts(array $filters): LengthAwarePaginator
    {
        $query = InventoryStockMovement::query()
            ->whereIn('inventory_stock_movements.type', [
                InventoryStockMovement::TYPE_PURCHASE_RECEIPT,
                InventoryStockMovement::TYPE_STOCK_IN,
            ])
            ->with([
                ...$this->movementRelations(),
                'purchaseOrder.lines',
            ]);
        $this->applyMovementFilters($query, $filters);

        if ($filters['purchase_status'] ?? null) {
            $query->whereHas('purchaseOrder', fn (Builder $order) => $order->where('status', $filters['purchase_status']));
        }
        $this->applyDateRange($query, $filters, 'inventory_stock_movements.movement_date');

        return $query
            ->orderByDesc('inventory_stock_movements.movement_date')
            ->orderByDesc('inventory_stock_movements.id')
            ->paginate(self::PER_PAGE, ['*'], 'receipts_page')
            ->withQueryString();
    }

    /**
     * Consumable issues, including their existing student/staff recipient.
     * InventoryIssue has no status column; no status is invented here.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<InventoryIssue>
     */
    public function issues(array $filters): LengthAwarePaginator
    {
        $query = InventoryIssue::query()->with([
            'item' => fn (Builder $item) => $item->withTrashed()->with([
                'category' => fn (Builder $category) => $category->withTrashed(),
            ]),
            'recipient',
            'creator:id,name',
        ]);

        if ($filters['item_id'] ?? null) {
            $query->where('item_id', $filters['item_id']);
        }
        if ($filters['category_id'] ?? null) {
            $this->whereItemCategory($query, (int) $filters['category_id']);
        }
        if ($filters['issued_to_type'] ?? null) {
            $query->where('issued_to_type', $filters['issued_to_type']);
        }
        $this->applyDateRange($query, $filters, 'movement_date');

        return $query
            ->orderByDesc('movement_date')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    /**
     * Asset register over the shared item master, with its current custodian
     * and read-only custody/maintenance aggregates.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<InventoryItem>
     */
    public function assets(array $filters): LengthAwarePaginator
    {
        $query = InventoryItem::query()
            ->where('item_type', InventoryItem::TYPE_ASSET)
            ->with(['category:id,name,code', 'activeAssignment.assignee'])
            ->withMax('assignments as last_returned_on', 'returned_on')
            ->withMax([
                'maintenances as last_service_on' => fn (Builder $maintenance) => $maintenance->where('status', InventoryMaintenance::STATUS_COMPLETED),
            ], 'completed_on')
            ->withCount([
                'maintenances as open_maintenance_count' => fn (Builder $maintenance) => $maintenance->where('status', '!=', InventoryMaintenance::STATUS_COMPLETED),
            ]);
        $this->applyItemFilters($query, $filters);

        if ($filters['asset_status'] ?? null) {
            $query->where('inventory_items.status', $filters['asset_status']);
        }
        if (($filters['custody'] ?? null) === 'assigned') {
            $query->whereHas('assignments', fn (Builder $assignment) => $assignment->active());
        } elseif (($filters['custody'] ?? null) === 'unassigned') {
            $query->whereDoesntHave('assignments', fn (Builder $assignment) => $assignment->active());
        }

        return $query
            ->orderBy('inventory_items.name')
            ->orderBy('inventory_items.id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    /**
     * Asset assignment history; these rows have no soft-delete lifecycle.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<InventoryAssignment>
     */
    public function assignments(array $filters): LengthAwarePaginator
    {
        $query = InventoryAssignment::query()
            ->with([
                'item' => fn (Builder $item) => $item->withTrashed()->with([
                    'category' => fn (Builder $category) => $category->withTrashed(),
                ]),
                'assignee',
                'returner:id,name',
                'creator:id,name',
            ])
            ->whereHas('item', fn (Builder $item) => $item->withTrashed()->where('item_type', InventoryItem::TYPE_ASSET));

        if ($filters['item_id'] ?? null) {
            $query->where('item_id', $filters['item_id']);
        }
        if ($filters['category_id'] ?? null) {
            $this->whereItemCategory($query, (int) $filters['category_id']);
        }
        if ($filters['assignment_status'] ?? null) {
            $query->where('status', $filters['assignment_status']);
        }
        if ($filters['assigned_to_type'] ?? null) {
            $query->where('assigned_to_type', $filters['assigned_to_type']);
        }
        $this->applyDateRange($query, $filters, 'assigned_on');

        return $query
            ->orderByDesc('assigned_on')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    /**
     * Returns are the existing returned assignment rows, not a separate model.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<InventoryAssignment>
     */
    public function returns(array $filters): LengthAwarePaginator
    {
        $query = InventoryAssignment::query()
            ->returned()
            ->with([
                'item' => fn (Builder $item) => $item->withTrashed()->with([
                    'category' => fn (Builder $category) => $category->withTrashed(),
                ]),
                'assignee',
                'returner:id,name',
            ])
            ->whereHas('item', fn (Builder $item) => $item->withTrashed()->where('item_type', InventoryItem::TYPE_ASSET));

        if ($filters['item_id'] ?? null) {
            $query->where('item_id', $filters['item_id']);
        }
        if ($filters['category_id'] ?? null) {
            $this->whereItemCategory($query, (int) $filters['category_id']);
        }
        $this->applyDateRange($query, $filters, 'returned_on');

        return $query
            ->orderByDesc('returned_on')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    /**
     * Existing maintenance work orders for assets, including their stored cost
     * and optional vendor; soft-deleted maintenance records remain excluded.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<InventoryMaintenance>
     */
    public function maintenance(array $filters): LengthAwarePaginator
    {
        $query = InventoryMaintenance::query()
            ->with([
                'item' => fn (Builder $item) => $item->withTrashed()->with([
                    'category' => fn (Builder $category) => $category->withTrashed(),
                ]),
                'vendor' => fn (Builder $vendor) => $vendor->withTrashed(),
                'creator:id,name',
            ])
            ->whereHas('item', fn (Builder $item) => $item->withTrashed()->where('item_type', InventoryItem::TYPE_ASSET));

        if ($filters['item_id'] ?? null) {
            $query->where('item_id', $filters['item_id']);
        }
        if ($filters['category_id'] ?? null) {
            $this->whereItemCategory($query, (int) $filters['category_id']);
        }
        if ($filters['vendor_id'] ?? null) {
            $query->where('vendor_id', $filters['vendor_id']);
        }
        if ($filters['maintenance_status'] ?? null) {
            $query->where('status', $filters['maintenance_status']);
        }
        if ($filters['maintenance_type'] ?? null) {
            $query->where('maintenance_type', $filters['maintenance_type']);
        }
        $this->applyDateRange($query, $filters, 'scheduled_on');

        return $query
            ->orderByDesc('scheduled_on')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    /**
     * Category-level roll-up retained as the category breakdown within
     * Inventory Summary; quantities from unlike units are never combined.
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
                'items as assets_count' => fn (Builder $item) => $item->where('item_type', InventoryItem::TYPE_ASSET),
                'items as assigned_assets_count' => fn (Builder $item) => $item->where('item_type', InventoryItem::TYPE_ASSET)
                    ->whereHas('assignments', fn (Builder $assignment) => $assignment->active()),
                'items as open_maintenance_assets_count' => fn (Builder $item) => $item->where('item_type', InventoryItem::TYPE_ASSET)
                    ->whereHas('maintenances', fn (Builder $maintenance) => $maintenance->where('status', '!=', InventoryMaintenance::STATUS_COMPLETED)),
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

        return $query->paginate(self::PER_PAGE)->withQueryString();
    }

    /**
     * Existing summary metrics plus a safe stock roll-up grouped by unit.
     * Purchase totals use the stored server-computed PO value; no amount is
     * recomputed and no stock quantities from unlike units are added.
     *
     * @return array{metrics: array<string, int|float|string>, stock_by_unit: \Illuminate\Support\Collection<int, object>}
     */
    public function summary(string $threshold): array
    {
        $stockItems = $this->stock->itemsWithBalance()->toBase();
        $stockByUnit = DB::query()
            ->fromSub($stockItems, 'ledger_items')
            ->select('unit')
            ->selectRaw('SUM(CAST(on_hand AS DECIMAL(12, 2))) AS stock_quantity')
            ->groupBy('unit')
            ->orderBy('unit')
            ->get();

        return [
            'metrics' => [
                'total_items' => InventoryItem::query()->count(),
                'total_assets' => InventoryItem::query()->where('item_type', InventoryItem::TYPE_ASSET)->count(),
                'total_categories' => InventoryCategory::query()->count(),
                'low_stock_items' => $this->stock->lowStock($threshold)->count(),
                'stock_transactions' => InventoryStockMovement::query()->count(),
                'purchase_orders' => InventoryPurchaseOrder::query()->count(),
                'purchase_order_value' => (string) InventoryPurchaseOrder::query()->sum('total_amount'),
                'goods_receipts' => InventoryStockMovement::query()
                    ->whereIn('type', [InventoryStockMovement::TYPE_PURCHASE_RECEIPT, InventoryStockMovement::TYPE_STOCK_IN])
                    ->count(),
                'item_issues' => InventoryIssue::query()->count(),
                'assets_assigned' => InventoryAssignment::query()->active()->count(),
                'assets_returned' => InventoryAssignment::query()->returned()->count(),
                'assets_under_maintenance' => InventoryMaintenance::query()
                    ->where('status', '!=', InventoryMaintenance::STATUS_COMPLETED)
                    ->distinct()
                    ->count('item_id'),
            ],
            'stock_by_unit' => $stockByUnit,
        ];
    }

    /** @param  array<string, mixed>  $filters */
    private function applyItemFilters(Builder $query, array $filters): void
    {
        if ($filters['category_id'] ?? null) {
            $query->where('inventory_items.category_id', $filters['category_id']);
        }
        if ($filters['item_id'] ?? null) {
            $query->where('inventory_items.id', $filters['item_id']);
        }
        if ($filters['search'] ?? null) {
            $search = trim((string) $filters['search']);
            $query->where(function (Builder $inner) use ($search): void {
                $inner->where('inventory_items.name', 'like', "%{$search}%")
                    ->orWhere('inventory_items.code', 'like', "%{$search}%")
                    ->orWhere('inventory_items.serial_number', 'like', "%{$search}%");
            });
        }
    }

    /** @param  array<string, mixed>  $filters */
    private function applyMovementFilters(Builder $query, array $filters): void
    {
        if ($filters['item_id'] ?? null) {
            $query->where('inventory_stock_movements.item_id', $filters['item_id']);
        }
        if ($filters['category_id'] ?? null) {
            $this->whereItemCategory($query, (int) $filters['category_id']);
        }
        if ($filters['purchase_order_id'] ?? null) {
            $query->where('inventory_stock_movements.purchase_order_id', $filters['purchase_order_id']);
        }
        if ($filters['vendor_id'] ?? null) {
            $query->whereHas('purchaseOrder', fn (Builder $order) => $order->where('vendor_id', $filters['vendor_id']));
        }
    }

    /** @param  array<string, mixed>  $filters */
    private function applyPurchaseOrderFilters(Builder $query, array $filters): void
    {
        if ($filters['purchase_order_id'] ?? null) {
            $query->whereKey($filters['purchase_order_id']);
        }
        if ($filters['vendor_id'] ?? null) {
            $query->where('vendor_id', $filters['vendor_id']);
        }
        if ($filters['purchase_status'] ?? null) {
            $query->where('status', $filters['purchase_status']);
        }
        if ($filters['from'] ?? null) {
            $query->whereDate('po_date', '>=', $filters['from']);
        }
        if ($filters['to'] ?? null) {
            $query->whereDate('po_date', '<=', $filters['to']);
        }
    }

    /** @param  array<string, mixed>  $filters */
    private function applyPurchaseItemFilters(Builder $query, array $filters): void
    {
        if ($filters['item_id'] ?? null) {
            $query->where('inventory_purchase_order_items.item_id', $filters['item_id']);
        }
        if ($filters['category_id'] ?? null) {
            $categoryId = (int) $filters['category_id'];
            $query->whereHas('item', fn (Builder $item) => $item->withTrashed()->where('category_id', $categoryId));
        }
    }

    /**
     * Apply a report date range to a fixed, code-owned date column.
     *
     * @param  array<string, mixed>  $filters
     */
    private function applyDateRange(Builder $query, array $filters, string $column): void
    {
        if ($filters['from'] ?? null) {
            $query->whereDate($column, '>=', $filters['from']);
        }
        if ($filters['to'] ?? null) {
            $query->whereDate($column, '<=', $filters['to']);
        }
    }

    private function whereItemCategory(Builder $query, int $categoryId): void
    {
        $query->whereHas('item', fn (Builder $item) => $item->withTrashed()->where('category_id', $categoryId));
    }

    /** @return array<string, \Closure> */
    private function movementRelations(): array
    {
        return [
            'item' => fn (Builder $item) => $item->withTrashed()->with([
                'category' => fn (Builder $category) => $category->withTrashed(),
            ]),
            'purchaseOrder' => fn (Builder $order) => $order->withTrashed()->with([
                'vendor' => fn (Builder $vendor) => $vendor->withTrashed(),
            ]),
            'creator:id,name',
        ];
    }
}
