<?php

namespace App\Policies;

use App\Models\InventoryStockMovement;
use App\Models\User;

/**
 * Stock movement authorization (Inventory / Asset Management, Phase 2).
 *
 * Movements are immutable, so there is no update or delete ability — a
 * correction is a new movement. Stock in, stock out and corrections are
 * separately grantable: receiving stock and writing stock off are different
 * responsibilities.
 */
class InventoryStockMovementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('inventory_stock.view');
    }

    public function view(User $user, InventoryStockMovement $movement): bool
    {
        return $user->hasPermission('inventory_stock.view', $movement->college_id);
    }

    public function in(User $user): bool
    {
        return $user->hasPermission('inventory_stock.in');
    }

    public function out(User $user): bool
    {
        return $user->hasPermission('inventory_stock.out');
    }

    public function adjust(User $user): bool
    {
        return $user->hasPermission('inventory_stock.adjust');
    }
}
