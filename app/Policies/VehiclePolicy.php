<?php

namespace App\Policies;

use App\Models\Vehicle;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

class VehiclePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('vehicles.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('vehicles.create');
    }

    public function view(User $user, Vehicle $record): bool
    {
        return $this->allowed($user, $record, 'view');
    }

    public function update(User $user, Vehicle $record): bool
    {
        return $this->allowed($user, $record, 'update');
    }

    public function delete(User $user, Vehicle $record): bool
    {
        return $this->allowed($user, $record, 'delete');
    }

    private function allowed(User $user, Vehicle $record, string $action): bool
    {
        return (int) $record->college_id === app(TenantContext::class)->id()
            && $user->hasPermission('vehicles.'.$action, $record->college_id);
    }
}
