<?php

namespace App\Policies;

use App\Models\User;

/**
 * Communication Dashboard authorization.
 *
 * The dashboard aggregates the communication records and has no per-row
 * resource of its own, so the policy is a single screen-level permission:
 * `communication_dashboard.view`. Nothing on the screen writes. Recent-record
 * panels are additionally gated on each module's own `*.view` permission in
 * the view.
 */
class CommunicationDashboardPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('communication_dashboard.view');
    }
}
