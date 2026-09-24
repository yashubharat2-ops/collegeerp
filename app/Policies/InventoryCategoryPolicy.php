<?php

namespace App\Policies;

use App\Models\InventoryCategory;
use App\Models\User;

/**
 * Item category authorization (Inventory / Asset Management).
 *
 * Follows the existing RBAC conventions: permission slugs are checked through
 * User::hasPermission (tenant-aware), so a permission granted in another college
 * never authorises an action in the active one.
 */
class InventoryCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('inventory_categories.view');
    }

    public function view(User $user, InventoryCategory $category): bool
    {
        return $user->hasPermission('inventory_categories.view', $category->college_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('inventory_categories.create');
    }

    public function update(User $user, InventoryCategory $category): bool
    {
        return $user->hasPermission('inventory_categories.update', $category->college_id);
    }

    public function delete(User $user, InventoryCategory $category): bool
    {
        return $user->hasPermission('inventory_categories.delete', $category->college_id);
    }
}
