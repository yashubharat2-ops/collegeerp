<?php

namespace App\Policies;

use App\Models\User;

/**
 * Inventory Dashboard authorization (Inventory / Asset Management).
 *
 * The dashboard aggregates the existing inventory masters and has no per-row
 * resource of its own, so the policy is a single screen-level permission:
 * inventory_dashboard.view. Nothing on the screen writes.
 */
class InventoryDashboardPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('inventory_dashboard.view');
    }
}
