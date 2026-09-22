<?php

namespace App\Policies;

use App\Models\LeaveType;
use App\Models\User;

class LeaveTypePolicy
{
    public function viewAny(User $user): bool { return $user->hasPermission('leave_types.view'); }
    public function view(User $user, LeaveType $type): bool { return $user->hasPermission('leave_types.view', $type->college_id); }
    public function create(User $user): bool { return $user->hasPermission('leave_types.create'); }
    public function update(User $user, LeaveType $type): bool { return $user->hasPermission('leave_types.update', $type->college_id); }
    public function delete(User $user, LeaveType $type): bool { return $user->hasPermission('leave_types.delete', $type->college_id); }
}
