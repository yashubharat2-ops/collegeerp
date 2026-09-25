<?php

namespace App\Policies;

use App\Models\Notice;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

/**
 * Notices / Announcements authorization (Communication Management).
 *
 * Uses the centralized RBAC (User::hasPermission, tenant-aware; Super Admin
 * handled there). Record-level abilities additionally require the record to
 * belong to the ACTIVE college, so a permission held in another college can
 * never act on it. Downloading the attachment is a `view`-level action;
 * publish / unpublish / archive share `notices.publish`.
 */
class NoticePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('notices.view');
    }

    public function view(User $user, Notice $notice): bool
    {
        return $this->allowed($user, $notice, 'view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('notices.create');
    }

    public function update(User $user, Notice $notice): bool
    {
        return $this->allowed($user, $notice, 'update');
    }

    public function delete(User $user, Notice $notice): bool
    {
        return $this->allowed($user, $notice, 'delete');
    }

    public function publish(User $user, Notice $notice): bool
    {
        return $this->allowed($user, $notice, 'publish');
    }

    public function download(User $user, Notice $notice): bool
    {
        return $this->allowed($user, $notice, 'view');
    }

    private function allowed(User $user, Notice $notice, string $action): bool
    {
        $active = app(TenantContext::class)->id();

        return $active !== null
            && (int) $notice->college_id === (int) $active
            && $user->hasPermission('notices.'.$action, (int) $notice->college_id);
    }
}
