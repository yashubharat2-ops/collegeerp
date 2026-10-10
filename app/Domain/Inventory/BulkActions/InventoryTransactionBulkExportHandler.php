<?php

namespace App\Domain\Inventory\BulkActions;

use App\Models\InventoryStockMovement;
use App\Support\BulkAction\BulkExportHandler;

/**
 * Bulk export of selected inventory transactions (Inventory — Inventory
 * Transactions).
 *
 * The transaction listing is the whole immutable ledger (every movement type),
 * so this handler accepts every authorized movement. Each ticked movement is
 * re-queried inside the active college and re-authorized through
 * {@see \App\Policies\InventoryStockMovementPolicy}
 * (`inventory_transactions.view`). The CSV carries the date, item, type,
 * direction, quantity, balance after, reference / reason / PO and the
 * recording user — the columns the listing shows. An export never writes to
 * the ledger.
 */
class InventoryTransactionBulkExportHandler extends BulkExportHandler
{
    public function modelClass(): string
    {
        return InventoryStockMovement::class;
    }

    public function requiredPermission(): ?string
    {
        return 'inventory_transactions.view';
    }

    public function policyAbility(): ?string
    {
        return 'view';
    }

    protected function exportRouteName(): string
    {
        return 'inventory-transactions.export';
    }

    protected function recordNoun(int $count): string
    {
        return $count === 1 ? 'transaction' : 'transactions';
    }
}
