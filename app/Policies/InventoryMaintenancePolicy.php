<?php

namespace App\Policies;

use App\Models\InventoryMaintenance;
use App\Models\User;

/**
 * Asset Maintenance authorization (Inventory / Asset Management, Phase 3).
 *
 * Each module is gated by its OWN permission: this policy answers only with
 * `inventory_maintenance.*` slugs. A maintenance record is a live work order,
 * so `update` (the status walk and cost entry) is granted separately from
 * `create`. There is no delete ability — records are corrected, not removed.
 */
class InventoryMaintenancePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('inventory_maintenance.view');
    }

    public function view(User $user, InventoryMaintenance $maintenance): bool
    {
        return $user->hasPermission('inventory_maintenance.view', $maintenance->college_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('inventory_maintenance.create');
    }

    public function update(User $user, InventoryMaintenance $maintenance): bool
    {
        return $user->hasPermission('inventory_maintenance.update', $maintenance->college_id);
    }
}
