<?php

namespace App\Policies;

use App\Models\HostelFeeStructure;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

/**
 * RBAC for hostel fee structures (Hostel Fees module).
 *
 * Gated on the module's hostel_fees.* permission set.
 */
class HostelFeeStructurePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('hostel_fees.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('hostel_fees.create');
    }

    public function view(User $user, HostelFeeStructure $record): bool
    {
        return $this->allowed($user, $record, 'view');
    }

    public function update(User $user, HostelFeeStructure $record): bool
    {
        return $this->allowed($user, $record, 'update');
    }

    public function delete(User $user, HostelFeeStructure $record): bool
    {
        return $this->allowed($user, $record, 'delete');
    }

    private function allowed(User $user, HostelFeeStructure $record, string $action): bool
    {
        return (int) $record->college_id === app(TenantContext::class)->id()
            && $user->hasPermission('hostel_fees.'.$action, $record->college_id);
    }
}
