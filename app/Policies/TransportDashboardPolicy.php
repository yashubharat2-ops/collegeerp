<?php

namespace App\Policies;

use App\Models\User;

class TransportDashboardPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('transport_dashboard.view');
    }
}
