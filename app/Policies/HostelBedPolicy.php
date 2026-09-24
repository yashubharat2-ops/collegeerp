<?php

namespace App\Policies;

use App\Models\HostelBed;
use App\Models\User;

/**
 * HostelBed authorization (Hostel Management).
 *
 * Permission slugs are checked through User::hasPermission (tenant-aware), so
 * a permission granted in another college never authorises an action in the
 * active one.
 */
class HostelBedPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('hostel_beds.view');
    }

    public function view(User $user, HostelBed $bed): bool
    {
        return $user->hasPermission('hostel_beds.view', $bed->college_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('hostel_beds.create');
    }

    public function update(User $user, HostelBed $bed): bool
    {
        return $user->hasPermission('hostel_beds.update', $bed->college_id);
    }

    public function delete(User $user, HostelBed $bed): bool
    {
        return $user->hasPermission('hostel_beds.delete', $bed->college_id);
    }
}
