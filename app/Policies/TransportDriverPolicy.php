<?php

namespace App\Policies;

use App\Models\TransportDriver;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

class TransportDriverPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('transport_drivers.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('transport_drivers.create');
    }

    public function view(User $user, TransportDriver $record): bool
    {
        return $this->allowed($user, $record, 'view');
    }

    public function update(User $user, TransportDriver $record): bool
    {
        return $this->allowed($user, $record, 'update');
    }

    public function delete(User $user, TransportDriver $record): bool
    {
        return $this->allowed($user, $record, 'delete');
    }

    private function allowed(User $user, TransportDriver $record, string $action): bool
    {
        return (int) $record->college_id === app(TenantContext::class)->id()
            && $user->hasPermission('transport_drivers.'.$action, $record->college_id);
    }
}
