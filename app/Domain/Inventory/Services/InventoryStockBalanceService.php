<?php

namespace App\Domain\Inventory\Services;

use App\Models\InventoryItem;
use App\Models\InventoryStockMovement;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;

/** Read-only, tenant-scoped on-hand balances derived from the immutable ledger. */
class InventoryStockBalanceService
{
    public const DEFAULT_LOW_STOCK_THRESHOLD = '5.00';

    /**
     * Include catalogue items without movements as zero; never use the item's
     * cached quantity as the source of a stock/reporting figure.
     *
     * @return Builder<InventoryItem>
     */
    public function itemsWithBalance(): Builder
    {
        $collegeId = app(TenantContext::class)->require()->id;

        $balances = InventoryStockMovement::query()
            ->select('inventory_stock_movements.college_id', 'inventory_stock_movements.item_id')
            ->selectRaw("SUM(CASE WHEN direction = 'in' THEN quantity ELSE -quantity END) AS on_hand")
            ->where('inventory_stock_movements.college_id', $collegeId)
            ->groupBy('inventory_stock_movements.college_id', 'inventory_stock_movements.item_id');

        return InventoryItem::query()
            ->select('inventory_items.*')
            ->selectRaw('COALESCE(stock_balance.on_hand, 0) AS on_hand')
            ->leftJoinSub($balances, 'stock_balance', function (JoinClause $join): void {
                $join->on('stock_balance.item_id', '=', 'inventory_items.id')
                    ->on('stock_balance.college_id', '=', 'inventory_items.college_id');
            });
    }

    /** Active consumables at or below a caller-selected, non-persisted threshold. */
    public function lowStock(string $threshold): Builder
    {
        return $this->itemsWithBalance()
            ->where('inventory_items.item_type', InventoryItem::TYPE_CONSUMABLE)
            ->where('inventory_items.status', InventoryItem::STATUS_ACTIVE)
            // SQLite binds the threshold as text; cast explicitly so numeric
            // balances are compared numerically on every supported driver.
            ->whereRaw('COALESCE(stock_balance.on_hand, 0) <= CAST(? AS DECIMAL(12, 2))', [$threshold]);
    }
}
