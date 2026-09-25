<?php

namespace App\Policies;

use App\Models\User;

/**
 * Communication Reports authorization (Communication Management, Phase 2).
 *
 * Read-only screen with no resource of its own: a single screen-level
 * permission, `communication_reports.view`. Nothing on the screen writes and
 * no reporting table exists.
 */
class CommunicationReportPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('communication_reports.view');
    }
}
