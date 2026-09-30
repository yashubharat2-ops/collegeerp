<?php

namespace App\Services\Administration;

use App\Models\College;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Services\Authorization\RolePermissionService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class RoleAdministrationService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly RolePermissionService $permissions,
        private readonly AuditLogService $audit,
    ) {}

    public function query(): Builder
    {
        return Role::query()->where('college_id', $this->tenant->require()->getKey());
    }

    public function find(string|int $id): Role
    {
        return $this->query()->findOrFail($id);
    }

    public function create(array $data, User $actor): Role
    {
        Gate::forUser($actor)->authorize('create', Role::class);

        return DB::transaction(function () use ($data, $actor): Role {
            $college = $this->lockCollege();
            if (in_array($data['slug'], [Role::SUPER_ADMIN_SLUG, 'college-admin'], true)
                || $this->query()->where('slug', $data['slug'])->exists()) {
                throw ValidationException::withMessages(['slug' => 'This role identifier is unavailable.']);
            }
            $role = Role::create([
                ...Arr::only($data, ['name', 'slug', 'description', 'is_active']),
                'college_id' => $college->getKey(), 'is_system' => false,
            ]);
            $this->permissions->syncPermissions($role, $data['permissions'] ?? [], $actor);
            $this->audit->record('roles.created', $role, [], $this->snapshot($role));

            return $role;
        });
    }

    public function update(Role $target, array $data, User $actor): void
    {
        DB::transaction(function () use ($target, $data, $actor): void {
            $this->lockCollege();
            $role = $this->query()->whereKey($target->getKey())->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('update', $role);
            $before = $this->snapshot($role);
            $role->update(Arr::only($data, ['name', 'description', 'is_active']));
            if (array_key_exists('permissions', $data)) {
                $this->permissions->syncPermissions($role, $data['permissions'], $actor);
            }
            $this->audit->record('roles.updated', $role, $before, $this->snapshot($role));
        });
    }

    private function snapshot(Role $role): array
    {
        return [...$role->only(['name', 'slug', 'description', 'is_active']),
            'permission_ids' => $role->permissions()->orderBy('permissions.id')->pluck('permissions.id')->all()];
    }

    private function lockCollege(): College
    {
        return College::query()->whereKey($this->tenant->require()->getKey())->lockForUpdate()->firstOrFail();
    }
}
