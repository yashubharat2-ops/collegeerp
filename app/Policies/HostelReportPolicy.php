<?php

namespace App\Policies;

use App\Models\User;

/**
 * Hostel Reports authorization — the screen aggregates existing records and
 * has no per-row resource of its own, so the policy is a single screen-level
 * permission: hostel_reports.view. Nothing on the screen writes.
 */
class HostelReportPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('hostel_reports.view');
    }
}
