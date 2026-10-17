<?php

namespace App\Policies;

use App\Models\User;

class ApprovalSettingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('approvals.view') || $user->hasPermission('approvals.settings.manage');
    }

    public function update(User $user): bool
    {
        return $user->hasPermission('approvals.settings.manage');
    }
}
