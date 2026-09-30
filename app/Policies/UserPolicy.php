<?php

namespace App\Policies;

use App\Models\User;
use App\Services\Authorization\RolePermissionService;
use App\Support\Tenancy\TenantContext;

/** Policies over the existing shared login identity and college membership pivot. */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return app(TenantContext::class)->has() && $user->hasPermission('users.view');
    }

    public function create(User $user): bool
    {
        return app(TenantContext::class)->has() && $user->hasPermission('users.create');
    }

    public function update(User $user, User $target): bool
    {
        // Name / email / account status are GLOBAL attributes, not membership
        // attributes. A college administrator cannot change another college's
        // shared identity, even when the target belongs to their college too.
        return $this->belongsToActiveCollege($target)
            && $user->hasPermission('users.update')
            && ($user->isSuperAdmin() || (
                $target->colleges()->withTrashed()->count() === 1
                && ! $target->roles()->whereNull('role_user.college_id')->exists()
            ));
    }

    public function updateStatus(User $user, User $target): bool
    {
        return $user->getKey() !== $target->getKey() && $this->update($user, $target);
    }

    public function assignRoles(User $user, User $target): bool
    {
        // Own access and platform grants are not editable through a college
        // assignment form. Other colleges' assignments remain untouched.
        if (! $this->belongsToActiveCollege($target)
            || $user->getKey() === $target->getKey()
            || ! $user->hasPermission('users.assign_roles')
            || (! $user->isSuperAdmin() && $target->roles()->whereNull('role_user.college_id')->exists())) {
            return false;
        }
        if (! $user->isSuperAdmin()) {
            foreach ($target->roles()->wherePivot('college_id', app(TenantContext::class)->id())->get() as $role) {
                if (! app(RolePermissionService::class)->canGrantRole($user, $role)) {
                    return false;
                }
            }
        }

        return true;
    }

    public function linkExisting(User $user): bool
    {
        return $user->isSuperAdmin() && $this->create($user);
    }

    private function belongsToActiveCollege(User $target): bool
    {
        $collegeId = app(TenantContext::class)->id();

        return $collegeId !== null && $target->colleges()->whereKey($collegeId)->exists();
    }
}
