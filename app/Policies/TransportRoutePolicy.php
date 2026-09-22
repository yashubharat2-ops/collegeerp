<?php

namespace App\Policies;

use App\Models\TransportRoute;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

class TransportRoutePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('transport_routes.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('transport_routes.create');
    }

    public function view(User $user, TransportRoute $record): bool
    {
        return $this->allowed($user, $record, 'view');
    }

    public function update(User $user, TransportRoute $record): bool
    {
        return $this->allowed($user, $record, 'update');
    }

    public function delete(User $user, TransportRoute $record): bool
    {
        return $this->allowed($user, $record, 'delete');
    }

    private function allowed(User $user, TransportRoute $record, string $action): bool
    {
        return (int) $record->college_id === app(TenantContext::class)->id()
            && $user->hasPermission('transport_routes.'.$action, $record->college_id);
    }
}
