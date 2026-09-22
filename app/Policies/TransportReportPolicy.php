<?php

namespace App\Policies;

use App\Models\User;

/**
 * Transport Reports authorization — the screen aggregates existing records and
 * has no per-row resource of its own, so the policy is a single screen-level
 * permission: transport_reports.view. Nothing on the screen writes.
 */
class TransportReportPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('transport_reports.view');
    }
}
