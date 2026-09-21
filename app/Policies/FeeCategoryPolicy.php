<?php

namespace App\Policies;

use App\Models\FeeCategory;
use App\Models\User;

/**
 * Fee Category authorization (Finance / Fees).
 *
 * Follows the existing RBAC conventions: permission slugs are checked through
 * User::hasPermission (tenant-aware), so a permission granted in another college
 * never authorises an action in the active one.
 */
class FeeCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('fee_categories.view');
    }

    public function view(User $user, FeeCategory $feeCategory): bool
    {
        return $user->hasPermission('fee_categories.view', $feeCategory->college_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('fee_categories.create');
    }

    public function update(User $user, FeeCategory $feeCategory): bool
    {
        return $user->hasPermission('fee_categories.update', $feeCategory->college_id);
    }

    public function delete(User $user, FeeCategory $feeCategory): bool
    {
        return $user->hasPermission('fee_categories.delete', $feeCategory->college_id);
    }
}
