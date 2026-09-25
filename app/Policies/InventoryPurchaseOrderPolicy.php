<?php

namespace App\Policies;

use App\Models\InventoryPurchaseOrder;
use App\Models\User;

/**
 * Purchase order authorization (Inventory / Asset Management, Phase 2).
 *
 * `receive` is its own ability: recording a goods receipt changes stock, so it
 * is granted separately from editing an order. Permission slugs are checked
 * through User::hasPermission (tenant-aware).
 */
class InventoryPurchaseOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('inventory_purchase_orders.view');
    }

    public function view(User $user, InventoryPurchaseOrder $order): bool
    {
        return $user->hasPermission('inventory_purchase_orders.view', $order->college_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('inventory_purchase_orders.create');
    }

    public function update(User $user, InventoryPurchaseOrder $order): bool
    {
        return $user->hasPermission('inventory_purchase_orders.update', $order->college_id);
    }

    public function delete(User $user, InventoryPurchaseOrder $order): bool
    {
        return $user->hasPermission('inventory_purchase_orders.delete', $order->college_id);
    }

    public function receive(User $user, InventoryPurchaseOrder $order): bool
    {
        return $user->hasPermission('inventory_purchase_orders.receive', $order->college_id);
    }
}
