<?php

namespace App\Policies;

use App\Models\InventoryAssignment;
use App\Models\User;

/**
 * Asset Assignment authorization (Inventory / Asset Management, Phase 3).
 *
 * One policy answers for the assignment rows, but the Phase 3 modules are
 * gated by their OWN permission families, the same way the Phase 2 stock
 * abilities live on one policy:
 *
 *   - Asset Assignment menu / actions:  `inventory_assignments.*`
 *   - Asset Return menu / actions:      `inventory_asset_returns.*`
 *     (answered here by the `viewReturns` and `returnAsset` abilities)
 *
 * Assigning an asset (`create`) is separate from returning one
 * (`returnAsset`), so a storekeeper may hand assets out without the
 * authority to take them back — or vice versa.
 */
class InventoryAssignmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('inventory_assignments.view');
    }

    public function view(User $user, InventoryAssignment $assignment): bool
    {
        return $user->hasPermission('inventory_assignments.view', $assignment->college_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('inventory_assignments.create');
    }

    /** The Asset Return menu and its active-assignment list. */
    public function viewReturns(User $user): bool
    {
        return $user->hasPermission('inventory_asset_returns.view');
    }

    /** Record a return for an (active) assignment. */
    public function returnAsset(User $user, InventoryAssignment $assignment): bool
    {
        return $user->hasPermission('inventory_asset_returns.create', $assignment->college_id);
    }
}
