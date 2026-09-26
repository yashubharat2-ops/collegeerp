<?php

namespace App\Policies;

use App\Models\InventoryIssue;
use App\Models\User;

/**
 * Item Issue / Allocation authorization (Inventory / Asset Management,
 * Phase 3).
 *
 * Each module is gated by its OWN permission: this policy answers only with
 * `inventory_issues.*` slugs, so a user who may assign assets or see stock
 * transactions gets no issue screen unless `inventory_issues.view` is
 * granted. Issues are append-only — there is no update or delete ability.
 */
class InventoryIssuePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('inventory_issues.view');
    }

    public function view(User $user, InventoryIssue $issue): bool
    {
        return $user->hasPermission('inventory_issues.view', $issue->college_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('inventory_issues.create');
    }
}
