<?php

namespace App\Policies;

use App\Models\HostelFeeAssignment;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

/**
 * RBAC for hostel fee assignments (Hostel Fees module).
 *
 * Permission set is hostel_fees.*. Recording a collection is an update-level
 * action (collect) so the permission catalogue is not split; the resulting
 * payment rows are governed by existing Finance policies thereafter.
 */
class HostelFeeAssignmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('hostel_fees.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('hostel_fees.create');
    }

    public function view(User $user, HostelFeeAssignment $record): bool
    {
        return $this->allowed($user, $record, 'view');
    }

    public function update(User $user, HostelFeeAssignment $record): bool
    {
        return $this->allowed($user, $record, 'update');
    }

    public function delete(User $user, HostelFeeAssignment $record): bool
    {
        return $this->allowed($user, $record, 'delete');
    }

    public function collect(User $user, HostelFeeAssignment $record): bool
    {
        return $this->allowed($user, $record, 'collect');
    }

    private function allowed(User $user, HostelFeeAssignment $record, string $action): bool
    {
        // collect permission maps to hostel_fees.collect, otherwise to the generic action
        $slug = $action === 'collect' ? 'hostel_fees.collect' : 'hostel_fees.'.$action;

        return (int) $record->college_id === app(TenantContext::class)->id()
            && $user->hasPermission($slug, $record->college_id);
    }
}
