<?php

namespace App\Policies;

use App\Models\FeeConcession;
use App\Models\User;

/**
 * Fee Discounts / Concessions authorization (Finance / Fees).
 *
 * Approval is a separate ability from editing: a user who may raise a concession
 * is not automatically allowed to approve it. The approval metadata
 * (approved_by / approved_at) is written only by the approve action.
 */
class FeeConcessionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('fee_concessions.view');
    }

    public function view(User $user, FeeConcession $concession): bool
    {
        return $user->hasPermission('fee_concessions.view', $concession->college_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('fee_concessions.create');
    }

    public function update(User $user, FeeConcession $concession): bool
    {
        return $user->hasPermission('fee_concessions.update', $concession->college_id);
    }

    public function delete(User $user, FeeConcession $concession): bool
    {
        return $user->hasPermission('fee_concessions.delete', $concession->college_id);
    }

    public function approve(User $user, FeeConcession $concession): bool
    {
        return $user->hasPermission('fee_concessions.approve', $concession->college_id);
    }
}
