<?php

namespace App\Policies;

use App\Models\User;

/**
 * Hostel Dashboard authorization (Hostel Management).
 *
 * The dashboard aggregates the existing hostel masters and has no per-row
 * resource of its own, so the policy is a single screen-level permission:
 * hostel_dashboard.view. Nothing on the screen writes.
 */
class HostelDashboardPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('hostel_dashboard.view');
    }
}
