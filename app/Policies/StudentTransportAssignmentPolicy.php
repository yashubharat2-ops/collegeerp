<?php

namespace App\Policies;

use App\Models\StudentTransportAssignment;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

class StudentTransportAssignmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('student_transport_assignments.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('student_transport_assignments.create');
    }

    public function view(User $user, StudentTransportAssignment $record): bool
    {
        return $this->allowed($user, $record, 'view');
    }

    public function update(User $user, StudentTransportAssignment $record): bool
    {
        return $this->allowed($user, $record, 'update');
    }

    public function delete(User $user, StudentTransportAssignment $record): bool
    {
        return $this->allowed($user, $record, 'delete');
    }

    private function allowed(User $user, StudentTransportAssignment $record, string $action): bool
    {
        return (int) $record->college_id === app(TenantContext::class)->id()
            && $user->hasPermission('student_transport_assignments.'.$action, $record->college_id);
    }
}
