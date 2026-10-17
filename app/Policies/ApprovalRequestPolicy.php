<?php

namespace App\Policies;

use App\Models\ApprovalRequest;
use App\Models\User;
use App\Services\Approval\ApprovalRegistry;

class ApprovalRequestPolicy
{
    public function viewAny(User $user): bool
    {
        // All active users can access approval lists; controller filters by permission for sensitive views
        // This ensures My Requests (personal) is reachable for staff who can submit, while
        // Pending/History content is still filtered server-side and via explicit checks
        return $user->is_active;
    }

    public function viewPending(User $user): bool
    {
        return $user->hasPermission('approvals.view')
            || $user->hasPermission('approvals.approve')
            || $user->hasPermission('approvals.settings.manage');
    }

    public function viewHistory(User $user): bool
    {
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
        // Only check permission here; self-approval and duplicate-decision are enforced
        // in ApprovalService atomically with lockForUpdate to return ValidationException
        // (session errors) rather than 403, preserving pending status and audit.
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
