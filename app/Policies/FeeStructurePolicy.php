<?php

namespace App\Policies;

use App\Models\FeeStructure;
use App\Models\User;

/**
 * Fee Structure authorization (Finance / Fees foundation).
 *
 * Follows the existing RBAC conventions: permission slugs are checked via
 * User::hasPermission (tenant-aware), so a permission granted in another
 * college never authorises an action in the active one.
 */
class FeeStructurePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('fee_structures.view');
    }

    public function view(User $user, FeeStructure $feeStructure): bool
    {
        return $user->hasPermission('fee_structures.view', $feeStructure->college_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('fee_structures.create');
    }

    public function update(User $user, FeeStructure $feeStructure): bool
    {
        return $user->hasPermission('fee_structures.update', $feeStructure->college_id);
    }

    public function delete(User $user, FeeStructure $feeStructure): bool
    {
        return $user->hasPermission('fee_structures.delete', $feeStructure->college_id);
    }
}
