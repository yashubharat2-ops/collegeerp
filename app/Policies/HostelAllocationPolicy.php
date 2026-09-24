<?php

namespace App\Policies;

use App\Models\HostelAllocation;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

/**
 * Hostel Allocation authorization (Hostel Management Phase 2).
 */
class HostelAllocationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('hostel_allocations.view');
    }

    public function view(User $user, HostelAllocation $allocation): bool
    {
        return (int) $allocation->college_id === app(TenantContext::class)->id()
            && $user->hasPermission('hostel_allocations.view', $allocation->college_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('hostel_allocations.create');
    }

    public function update(User $user, HostelAllocation $allocation): bool
    {
        return (int) $allocation->college_id === app(TenantContext::class)->id()
            && $user->hasPermission('hostel_allocations.update', $allocation->college_id);
    }

    public function delete(User $user, HostelAllocation $allocation): bool
    {
        return (int) $allocation->college_id === app(TenantContext::class)->id()
            && $user->hasPermission('hostel_allocations.delete', $allocation->college_id);
    }

    public function vacate(User $user, HostelAllocation $allocation): bool
    {
        return $this->update($user, $allocation);
    }

    public function cancel(User $user, HostelAllocation $allocation): bool
    {
        return $this->update($user, $allocation);
    }
}
