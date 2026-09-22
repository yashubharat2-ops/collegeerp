<?php

namespace App\Policies;

use App\Models\TransportFeeStructure;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

/**
 * RBAC for transport fee structures (transport fee categories).
 *
 * Gated on the module's `transport_fees.*` permission set — the pricing master
 * belongs to the Transport Fees module, it does not add a new permission group.
 */
class TransportFeeStructurePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('transport_fees.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('transport_fees.create');
    }

    public function view(User $user, TransportFeeStructure $record): bool
    {
        return $this->allowed($user, $record, 'view');
    }

    public function update(User $user, TransportFeeStructure $record): bool
    {
        return $this->allowed($user, $record, 'update');
    }

    public function delete(User $user, TransportFeeStructure $record): bool
    {
        return $this->allowed($user, $record, 'delete');
    }

    private function allowed(User $user, TransportFeeStructure $record, string $action): bool
    {
        return (int) $record->college_id === app(TenantContext::class)->id()
            && $user->hasPermission('transport_fees.'.$action, $record->college_id);
    }
}
