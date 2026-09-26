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
        return $user->hasPermission('inventory_stock.view')
            || $user->hasPermission('inventory_transactions.view')
            || $user->hasPermission('inventory_goods_receipts.view')
            || $user->hasPermission('inventory_stock_adjustments.view');
    }

    public function view(User $user, InventoryStockMovement $movement): bool
    {
        return $user->hasPermission('inventory_stock.view', $movement->college_id)
            || $user->hasPermission('inventory_transactions.view', $movement->college_id)
            || $user->hasPermission('inventory_goods_receipts.view', $movement->college_id)
            || $user->hasPermission('inventory_stock_adjustments.view', $movement->college_id);
    }

    public function in(User $user): bool
    {
        return $user->hasPermission('inventory_stock.in')
            || $user->hasPermission('inventory_goods_receipts.create');
    }

    public function out(User $user): bool
    {
        return $user->hasPermission('inventory_stock.out')
            || $user->hasPermission('inventory_stock_adjustments.create');
    }

    public function adjust(User $user): bool
    {
        return $user->hasPermission('inventory_stock.adjust')
            || $user->hasPermission('inventory_stock_adjustments.create');
    }

    public function viewTransactions(User $user): bool
    {
        return $user->hasPermission('inventory_transactions.view')
            || $user->hasPermission('inventory_stock.view');
    }

    public function viewGoodsReceipts(User $user): bool
    {
        return $user->hasPermission('inventory_goods_receipts.view')
            || $user->hasPermission('inventory_stock.view')
            || $user->hasPermission('inventory_transactions.view');
    }

    public function createGoodsReceipt(User $user): bool
    {
        return $user->hasPermission('inventory_goods_receipts.create')
            || $user->hasPermission('inventory_stock.in');
    }

    public function viewAdjustments(User $user): bool
    {
        return $user->hasPermission('inventory_stock_adjustments.view')
            || $user->hasPermission('inventory_stock.view')
            || $user->hasPermission('inventory_transactions.view');
    }

    public function createAdjustment(User $user): bool
    {
        return $user->hasPermission('inventory_stock_adjustments.create')
            || $user->hasPermission('inventory_stock.adjust')
            || $user->hasPermission('inventory_stock.out');
    }
}
