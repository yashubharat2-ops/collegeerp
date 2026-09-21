<?php

namespace App\Policies;

use App\Models\User;

/**
 * Due / Outstanding Fees authorization (Finance / Fees).
 *
 * The screen has no per-row resource: outstanding balances are derived, never
 * stored, and never editable. The policy is therefore a single screen-level
 * permission — fee_dues.view — and there is no update/delete ability to grant.
 */
class FeeDuePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('fee_dues.view');
    }
}
