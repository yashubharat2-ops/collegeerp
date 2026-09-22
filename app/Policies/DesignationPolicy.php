<?php

namespace App\Policies;

use App\Models\Designation;
use App\Models\User;

class DesignationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('designations.view');
    }

    public function view(User $user, Designation $designation): bool
    {
        return $user->hasPermission('designations.view', $designation->college_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('designations.create');
    }

    public function update(User $user, Designation $designation): bool
    {
        return $user->hasPermission('designations.update', $designation->college_id);
    }

    public function delete(User $user, Designation $designation): bool
    {
        return $user->hasPermission('designations.delete', $designation->college_id);
    }
}
