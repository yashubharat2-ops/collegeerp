<?php

namespace App\Domain\Inventory\BulkActions;

use App\Models\InventoryPurchaseOrder;
use App\Support\BulkAction\BulkExportHandler;

/**
 * Bulk export of selected purchase orders (Inventory — Purchase Orders).
 *
 * Each ticked order is re-queried inside the active college and re-authorized
 * through {@see \App\Policies\InventoryPurchaseOrderPolicy}
 * (`inventory_purchase_orders.view`). The CSV carries the order number, date,
 * vendor, status and the stored total — the columns the listing shows. An
 * export never submits, receives or cancels an order; those lifecycle actions
 * stay single-record with their own permissions.
 */
class InventoryPurchaseOrderBulkExportHandler extends BulkExportHandler
{
    public function modelClass(): string
    {
        return InventoryPurchaseOrder::class;
    }

    public function requiredPermission(): ?string
    {
        return 'inventory_purchase_orders.view';
    }

    public function policyAbility(): ?string
    {
        return 'view';
    }

    protected function exportRouteName(): string
    {
        return 'inventory-purchase-orders.export';
    }

    protected function recordNoun(int $count): string
    {
        return $count === 1 ? 'purchase order' : 'purchase orders';
    }
}
