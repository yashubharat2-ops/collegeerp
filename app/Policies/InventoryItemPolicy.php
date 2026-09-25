<?php

namespace App\Policies;

use App\Models\InventoryItem;
use App\Models\User;

/**
 * Item / asset authorization (Inventory / Asset Management).
 *
 * One policy covers both consumables and assets — they are the same master.
 * Permission slugs are checked through User::hasPermission (tenant-aware).
 */
class InventoryItemPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('inventory_items.view');
    }

    public function view(User $user, InventoryItem $item): bool
    {
        return $user->hasPermission('inventory_items.view', $item->college_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('inventory_items.create');
    }

    public function update(User $user, InventoryItem $item): bool
    {
        return $user->hasPermission('inventory_items.update', $item->college_id);
    }

    public function delete(User $user, InventoryItem $item): bool
    {
        return $user->hasPermission('inventory_items.delete', $item->college_id);
    }
}
