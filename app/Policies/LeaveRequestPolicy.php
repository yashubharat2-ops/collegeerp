<?php

namespace App\Policies;

use App\Models\LeaveRequest;
use App\Models\User;

class LeaveRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('leave_requests.view') || $user->hasPermission('leave_requests.create');
    }

    public function view(User $user, LeaveRequest $request): bool
    {
        return $user->hasPermission('leave_requests.view', $request->college_id)
            || (int) $request->requested_by === (int) $user->getKey();
    }

    public function create(User $user): bool { return $user->hasPermission('leave_requests.create'); }

    public function update(User $user, LeaveRequest $request): bool
    {
        return $user->hasPermission('leave_requests.update', $request->college_id)
            || (int) $request->requested_by === (int) $user->getKey();
    }

    public function approve(User $user, LeaveRequest $request): bool { return $user->hasPermission('leave_requests.approve', $request->college_id); }

    public function cancel(User $user, LeaveRequest $request): bool
    {
        return $user->hasPermission('leave_requests.update', $request->college_id)
            || $user->hasPermission('leave_requests.approve', $request->college_id)
            || (int) $request->requested_by === (int) $user->getKey();
    }

    public function delete(User $user, LeaveRequest $request): bool
    {
        return $user->hasPermission('leave_requests.delete', $request->college_id)
            || (int) $request->requested_by === (int) $user->getKey();
    }
}
