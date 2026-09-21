<?php

namespace App\Policies;

use App\Models\User;

/**
 * Fee Reports authorization (Finance / Fees).
 *
 * The reporting screen aggregates the existing transactional records and has no
 * per-row resource of its own, so the policy is a single screen-level
 * permission: fee_reports.view. Nothing on the screen writes.
 */
class FeeReportPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('fee_reports.view');
    }
}
