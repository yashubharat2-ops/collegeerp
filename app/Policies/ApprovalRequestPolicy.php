<?php

namespace App\Policies;

use App\Models\ApprovalRequest;
use App\Models\User;
use App\Services\Approval\ApprovalRegistry;

class ApprovalRequestPolicy
{
    public function viewAny(User $user): bool
    {
        // Any user with any approvals permission can view lists (filtered by scope)
        return $user->hasPermission('approvals.view')
            || $user->hasPermission('approvals.approve')
            || $user->hasPermission('approvals.settings.manage');
    }

    public function view(User $user, ApprovalRequest $request): bool
    {
        // Requester can always see their own, approvers can see pending for their permission, history visible to those with view
        if ((int) $request->requested_by === (int) $user->getKey()) {
            return true;
        }
        $perm = ApprovalRegistry::permissionFor($request->module, $request->action);
        if ($perm && $user->hasPermission($perm, $request->college_id)) {
            return true;
        }
        return $user->hasPermission('approvals.view', $request->college_id);
    }

    public function approve(User $user, ApprovalRequest $request): bool
    {
        if ((int) $request->requested_by === (int) $user->getKey()) {
            return false;
        }
        if ($request->status !== ApprovalRequest::STATUS_PENDING) {
            return false;
        }
        $perm = ApprovalRegistry::permissionFor($request->module, $request->action);
        if (! $perm) {
            return false;
        }
        return $user->hasPermission($perm, $request->college_id);
    }

    public function reject(User $user, ApprovalRequest $request): bool
    {
        return $this->approve($user, $request);
    }

    public function cancel(User $user, ApprovalRequest $request): bool
    {
        if ($request->status !== ApprovalRequest::STATUS_PENDING) {
            return false;
        }
        if ((int) $request->requested_by === (int) $user->getKey()) {
            return true;
        }
        // Admin with approve permission can cancel others if needed
        $perm = ApprovalRegistry::permissionFor($request->module, $request->action);
        return $perm && $user->hasPermission($perm, $request->college_id);
    }
}
