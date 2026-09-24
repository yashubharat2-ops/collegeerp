<?php

namespace App\Policies;

use App\Models\HostelBuilding;
use App\Models\User;

/**
 * HostelBuilding authorization (Hostel Management).
 *
 * Permission slugs are checked through User::hasPermission (tenant-aware), so
 * a permission granted in another college never authorises an action in the
 * active one.
 */
class HostelBuildingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('hostel_buildings.view');
    }

    public function view(User $user, HostelBuilding $building): bool
    {
        return $user->hasPermission('hostel_buildings.view', $building->college_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('hostel_buildings.create');
    }

    public function update(User $user, HostelBuilding $building): bool
    {
        return $user->hasPermission('hostel_buildings.update', $building->college_id);
    }

    public function delete(User $user, HostelBuilding $building): bool
    {
        return $user->hasPermission('hostel_buildings.delete', $building->college_id);
    }
}
