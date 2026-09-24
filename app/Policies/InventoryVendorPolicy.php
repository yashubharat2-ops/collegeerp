<?php

namespace App\Policies;

use App\Models\InventoryVendor;
use App\Models\User;

/**
 * Vendor authorization (Inventory / Asset Management).
 *
 * Follows the existing RBAC conventions: permission slugs are checked through
 * User::hasPermission (tenant-aware), so a permission granted in another college
 * never authorises an action in the active one.
 */
class InventoryVendorPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('inventory_vendors.view');
    }

    public function view(User $user, InventoryVendor $vendor): bool
    {
        return $user->hasPermission('inventory_vendors.view', $vendor->college_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('inventory_vendors.create');
    }

    public function update(User $user, InventoryVendor $vendor): bool
    {
        return $user->hasPermission('inventory_vendors.update', $vendor->college_id);
    }

    public function delete(User $user, InventoryVendor $vendor): bool
    {
        return $user->hasPermission('inventory_vendors.delete', $vendor->college_id);
    }
}
