<?php

namespace App\Domain\Inventory\BulkActions;

use App\Models\InventoryStockMovement;
use App\Support\BulkAction\BulkExportHandler;
use Illuminate\Database\Eloquent\Model;

/**
 * Bulk export of selected goods receipts / stock-in movements (Inventory —
 * Goods Receipt / Stock In).
 *
 * The listing shows only incoming movements of type `purchase_receipt` or
 * `stock_in`, so this handler accepts exactly those records: a hand-picked
 * adjustment or stock-out id can never ride along in a goods-receipt export.
 * Each ticked movement is re-queried inside the active college and
 * re-authorized through {@see \App\Policies\InventoryStockMovementPolicy}
 * (`inventory_goods_receipts.view`). The CSV carries the date, item, type,
 * quantity, balance after, reference / PO and the recording user — the
 * columns the listing shows. The ledger is immutable: an export never books,
 * corrects or reverses a movement.
 */
class InventoryGoodsReceiptBulkExportHandler extends BulkExportHandler
{
    public function modelClass(): string
    {
        return InventoryStockMovement::class;
    }

    public function requiredPermission(): ?string
    {
        return 'inventory_goods_receipts.view';
    }

    public function policyAbility(): ?string
    {
        return 'view';
    }

    protected function acceptsRecord(Model $record): bool
    {
        return $record instanceof InventoryStockMovement
            && in_array($record->type, [
                InventoryStockMovement::TYPE_PURCHASE_RECEIPT,
                InventoryStockMovement::TYPE_STOCK_IN,
            ], true);
    }

    protected function exportRouteName(): string
    {
        return 'inventory-goods-receipts.export';
    }

    protected function recordNoun(int $count): string
    {
        return $count === 1 ? 'goods receipt' : 'goods receipts';
    }
}
