<?php

namespace App\Policies;

use App\Models\HostelRoom;
use App\Models\User;

/**
 * HostelRoom authorization (Hostel Management).
 *
 * Permission slugs are checked through User::hasPermission (tenant-aware), so
 * a permission granted in another college never authorises an action in the
 * active one.
 */
class HostelRoomPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('hostel_rooms.view');
    }

    public function view(User $user, HostelRoom $room): bool
    {
        return $user->hasPermission('hostel_rooms.view', $room->college_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('hostel_rooms.create');
    }

    public function update(User $user, HostelRoom $room): bool
    {
        return $user->hasPermission('hostel_rooms.update', $room->college_id);
    }

    public function delete(User $user, HostelRoom $room): bool
    {
        return $user->hasPermission('hostel_rooms.delete', $room->college_id);
    }
}
