<?php

namespace App\Domain\Inventory\BulkActions;

use App\Models\InventoryStockMovement;
use App\Support\BulkAction\BulkExportHandler;
use Illuminate\Database\Eloquent\Model;

/**
 * Bulk export of selected stock adjustments / stock-out movements (Inventory
 * — Stock Adjustment).
 *
 * The listing shows only movements of type `adjustment` or `stock_out`, so
 * this handler accepts exactly those records: a goods-receipt id can never
 * ride along in an adjustment export. Each ticked movement is re-queried
 * inside the active college and re-authorized through
 * {@see \App\Policies\InventoryStockMovementPolicy}
 * (`inventory_stock_adjustments.view`). The CSV carries the date, item, type,
 * direction, quantity, balance after, reason / reference and the recording
 * user — the columns the listing shows. The ledger is immutable: an export
 * never books or reverses an adjustment.
 */
class InventoryStockAdjustmentBulkExportHandler extends BulkExportHandler
{
    public function modelClass(): string
    {
        return InventoryStockMovement::class;
    }

    public function requiredPermission(): ?string
    {
        return 'inventory_stock_adjustments.view';
    }

    public function policyAbility(): ?string
    {
        return 'view';
    }

    protected function acceptsRecord(Model $record): bool
    {
        return $record instanceof InventoryStockMovement
            && in_array($record->type, [
                InventoryStockMovement::TYPE_ADJUSTMENT,
                InventoryStockMovement::TYPE_STOCK_OUT,
            ], true);
    }

    protected function exportRouteName(): string
    {
        return 'inventory-stock-adjustments.export';
    }

    protected function recordNoun(int $count): string
    {
        return $count === 1 ? 'stock adjustment' : 'stock adjustments';
    }
}
