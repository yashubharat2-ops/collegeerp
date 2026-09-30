<?php

namespace App\Services\Authorization;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Coordination of the EXISTING role_user and permission_role pivots. */
class RolePermissionService
{
    public function assign(User $user, Role $role, ?int $collegeId = null): void
    {
        abort_unless($role->college_id === null || (int) $role->college_id === $collegeId, 403);
        abort_if($role->slug === Role::SUPER_ADMIN_SLUG && $collegeId !== null, 403);
        if ($collegeId !== null) {
            abort_unless($user->colleges()->whereKey($collegeId)->exists(), 403);
        }

        DB::transaction(function () use ($user, $role, $collegeId): void {
            User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $assigned = $user->roles()->whereKey($role->getKey());
            $collegeId === null ? $assigned->wherePivotNull('college_id') : $assigned->wherePivot('college_id', $collegeId);

            // syncWithoutDetaching keys only by role_id and can overwrite the
            // college on a shared system-role pivot. Check the full tuple.
            if (! $assigned->exists()) {
                $user->roles()->attach($role->getKey(), ['college_id' => $collegeId]);
            }
        });
    }

    /** Replace just one college's assignments; never global or sibling pivots. */
    public function syncForCollege(User $user, int $collegeId, Collection $roles): void
    {
        abort_unless(app(TenantContext::class)->id() === $collegeId, 403);
        abort_unless($user->colleges()->whereKey($collegeId)->exists(), 403);
        foreach ($roles as $role) {
            abort_unless($role->is_active && (int) $role->college_id === $collegeId && $role->slug !== Role::SUPER_ADMIN_SLUG, 403);
        }

        DB::transaction(function () use ($user, $collegeId, $roles): void {
            User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $existing = $user->roles()->wherePivot('college_id', $collegeId)->pluck('roles.id')->all();
            $removed = array_diff($existing, $roles->modelKeys());
            if ($removed !== []) {
                $user->roles()->wherePivot('college_id', $collegeId)->detach($removed);
            }
            foreach ($roles as $role) {
                $this->assign($user, $role, $collegeId);
            }
        });
    }

    public function canGrantRole(User $actor, Role $role): bool
    {
        if (! $actor->is_active) {
            return false;
        }
        $required = $role->permissions()->where('permissions.is_active', true)->pluck('permissions.id')->all();

        return array_diff($required, $this->grantableQuery($actor)->pluck('permissions.id')->all()) === [];
    }

    public function grantablePermissions(User $actor): Collection
    {
        return $this->grantableQuery($actor)->orderBy('module')->orderBy('action')->get();
    }

    /**
     * Bulk read of the same active registry / role / membership grants used by
     * User::hasPermission. It avoids issuing a permission check query for every
     * checkbox while keeping the existing RBAC models and pivot semantics.
     */
    private function grantableQuery(User $actor): Builder
    {
        $query = Permission::query()->where('permissions.is_active', true);
        if (! $actor->is_active) {
            return $query->whereRaw('1 = 0');
        }
        if ($actor->isSuperAdmin()) {
            return $query;
        }
        $collegeId = app(TenantContext::class)->id();
        if ($collegeId === null || ! $actor->colleges()->whereKey($collegeId)->exists()) {
            return $query->whereRaw('1 = 0');
        }
        $roleIds = $actor->roles()->where('roles.is_active', true)->wherePivot('college_id', $collegeId)->pluck('roles.id');

        return $query->whereHas('roles', fn (Builder $roles) => $roles->whereIn('roles.id', $roleIds));
    }

    /** No permission definitions are created or changed by an assignment. */
    public function syncPermissions(Role $role, array $ids, User $actor): void
    {
        $collegeId = app(TenantContext::class)->id();
        abort_unless($actor->is_active && $collegeId !== null && (int) $role->college_id === $collegeId, 403);
        $permissions = Permission::query()->where('is_active', true)->whereKey($ids)->get();
        if ($permissions->count() !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['permissions' => 'Choose existing active permissions.']);
        }
        if (array_diff($permissions->modelKeys(), $this->grantableQuery($actor)->pluck('permissions.id')->all()) !== []) {
            throw ValidationException::withMessages(['permissions' => 'You may only assign permissions you hold in the active college.']);
        }
        $role->permissions()->sync($permissions->modelKeys());
    }
}
