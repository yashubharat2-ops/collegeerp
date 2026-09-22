<?php

namespace App\Policies;

use App\Models\StudentTransportFeeAssignment;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

/**
 * RBAC for student transport fee assignments (Transport Fees module).
 *
 * Permission set is the module's `transport_fees.*` four (view / create /
 * update / delete). Recording a Finance collection against an assignment is an
 * UPDATE-level action on it (`collect` below) so the permission catalogue is
 * not split; the resulting payment rows are governed by the existing Finance
 * policies for everything after that.
 */
class StudentTransportFeeAssignmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('transport_fees.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('transport_fees.create');
    }

    public function view(User $user, StudentTransportFeeAssignment $record): bool
    {
        return $this->allowed($user, $record, 'view');
    }

    public function update(User $user, StudentTransportFeeAssignment $record): bool
    {
        return $this->allowed($user, $record, 'update');
    }

    public function delete(User $user, StudentTransportFeeAssignment $record): bool
    {
        return $this->allowed($user, $record, 'delete');
    }

    /** Recording a collection is an update-level action on the assignment. */
    public function collect(User $user, StudentTransportFeeAssignment $record): bool
    {
        return $this->allowed($user, $record, 'update');
    }

    private function allowed(User $user, StudentTransportFeeAssignment $record, string $action): bool
    {
        return (int) $record->college_id === app(TenantContext::class)->id()
            && $user->hasPermission('transport_fees.'.$action, $record->college_id);
    }
}
