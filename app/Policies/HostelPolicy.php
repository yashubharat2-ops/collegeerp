<?php

namespace App\Policies;

use App\Models\Hostel;
use App\Models\User;

/**
 * Hostel authorization (Hostel Management).
 *
 * Follows the existing RBAC conventions: permission slugs are checked through
 * User::hasPermission (tenant-aware), so a permission granted in another
 * college never authorises an action in the active one.
 */
class HostelPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('hostels.view');
    }

    public function view(User $user, Hostel $hostel): bool
    {
        return $user->hasPermission('hostels.view', $hostel->college_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('hostels.create');
    }

    public function update(User $user, Hostel $hostel): bool
    {
        return $user->hasPermission('hostels.update', $hostel->college_id);
    }

    public function delete(User $user, Hostel $hostel): bool
    {
        return $user->hasPermission('hostels.delete', $hostel->college_id);
    }
}
