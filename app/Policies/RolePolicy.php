<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;
use App\Services\Authorization\RolePermissionService;
use App\Support\Tenancy\TenantContext;

class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return app(TenantContext::class)->has() && $user->hasPermission('roles.view');
    }

    public function create(User $user): bool
    {
        return app(TenantContext::class)->has() && $user->hasPermission('roles.create');
    }

    public function update(User $user, Role $role): bool
    {
        $collegeId = app(TenantContext::class)->id();

        // Global/system role definitions are not copied into a college's
        // permission system. Platform roles are read-only here. A Super Admin
        // may maintain college system roles, but never the Super Admin role.
        if ($collegeId === null || (int) $role->college_id !== $collegeId || $role->slug === Role::SUPER_ADMIN_SLUG) {
            return false;
        }

        if (! $user->hasPermission('roles.update') || ($role->is_system && ! $user->isSuperAdmin())) {
            return false;
        }

        if (! $user->isSuperAdmin()) {
            if ($role->users()->whereKey($user->getKey())->wherePivot('college_id', $collegeId)->exists()) {
                return false; // never edit the role that grants your own authority
            }

            // Limited administrators cannot take over a more privileged role.
            if (! app(RolePermissionService::class)->canGrantRole($user, $role)) {
                return false;
            }
        }

        return true;
    }
}
