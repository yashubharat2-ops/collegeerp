<?php

namespace App\Policies;

use App\Models\User;

/**
 * Library Dashboard authorization (Library Management).
 *
 * The dashboard aggregates the existing library masters and has no per-row
 * resource of its own, so the policy is a single screen-level permission:
 * library_dashboard.view. Nothing on the screen writes.
 */
class LibraryDashboardPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('library_dashboard.view');
    }
}
