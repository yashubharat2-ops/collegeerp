<?php

namespace App\Services\Administration;

use App\Models\College;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Services\Authorization\RolePermissionService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UserAdministrationService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly RolePermissionService $roles,
        private readonly AuditLogService $audit,
    ) {}

    public function query(): Builder
    {
        $collegeId = $this->tenant->require()->getKey();

        // User itself is a global identity, so never add a CollegeScope to it.
        return User::query()->whereHas('colleges', fn (Builder $query) => $query->whereKey($collegeId));
    }

    public function find(string|int $id): User
    {
        return $this->query()->findOrFail($id);
    }

    public function roleOptions(User $actor): Collection
    {
        $grantable = $this->roles->grantablePermissions($actor)->modelKeys();

        return Role::query()->where('college_id', $this->tenant->require()->getKey())
            ->where('is_active', true)->where('slug', '!=', Role::SUPER_ADMIN_SLUG)
            ->with(['permissions' => fn ($q) => $q->where('permissions.is_active', true)])
            ->orderBy('name')->get()
            ->filter(fn (Role $role) => array_diff($role->permissions->modelKeys(), $grantable) === []);
    }

    public function create(array $data, User $actor): User
    {
        Gate::forUser($actor)->authorize('create', User::class);
        try {
            return DB::transaction(function () use ($data, $actor): User {
                $college = $this->lockCollege();
                $user = User::create([
                    'name' => $data['name'], 'email' => $data['email'],
                    // Password setup reuses the existing Forgot Password flow.
                    // No initial credential is emailed, rendered or logged.
                    'password' => Str::password(64), 'is_active' => $data['is_active'] ?? true,
                ]);
                $user->colleges()->attach($college->getKey(), ['is_default' => true]);
                if (($data['roles'] ?? []) !== []) {
                    $this->assignRoles($user, $data['roles'], $actor);
                }
                $this->audit->record('users.created', $user, [], $user->only(['name', 'email', 'is_active']));

                return $user;
            });
        } catch (QueryException $exception) {
            $this->translateDuplicateEmail($exception);
        }
    }

    public function update(User $target, array $data, User $actor): void
    {
        try {
            DB::transaction(function () use ($target, $data, $actor): void {
                $this->lockCollege();
                $user = $this->query()->whereKey($target->getKey())->lockForUpdate()->firstOrFail();
                Gate::forUser($actor)->authorize('update', $user);
                $before = $user->only(['name', 'email']);
                $user->fill(['name' => $data['name'], 'email' => $data['email']]);
                if ($user->isDirty('email')) {
                    $user->email_verified_at = null;
                }
                $user->save();
                $this->audit->record('users.updated', $user, $before, $user->only(['name', 'email']));
            });
        } catch (QueryException $exception) {
            $this->translateDuplicateEmail($exception);
        }
    }

    public function setStatus(User $target, bool $active, User $actor): void
    {
        DB::transaction(function () use ($target, $active, $actor): void {
            $this->lockCollege();
            $user = $this->query()->whereKey($target->getKey())->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('updateStatus', $user);
            $before = ['is_active' => $user->is_active];
            $user->is_active = $active;
            if (! $active) {
                $user->remember_token = null;
            }
            $user->save();
            if (! $active && config('session.driver') === 'database') {
                DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))->where('user_id', $user->getKey())->delete();
            }
            $this->audit->record('users.status_changed', $user, $before, ['is_active' => $user->is_active]);
        });
    }

    public function assignRoles(User $target, array $ids, User $actor): void
    {
        DB::transaction(function () use ($target, $ids, $actor): void {
            $college = $this->lockCollege();
            $user = $this->query()->whereKey($target->getKey())->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('assignRoles', $user);
            $roles = Role::query()->where('college_id', $college->getKey())->where('is_active', true)
                ->where('slug', '!=', Role::SUPER_ADMIN_SLUG)->whereKey($ids)->lockForUpdate()->get();
            if ($roles->count() !== count(array_unique($ids))) {
                throw ValidationException::withMessages(['roles' => 'Choose active roles belonging to the active college.']);
            }
            foreach ($roles as $role) {
                if (! $this->roles->canGrantRole($actor, $role)) {
                    throw ValidationException::withMessages(['roles' => 'You cannot assign a role with permissions you do not hold.']);
                }
            }
            $before = $user->roles()->wherePivot('college_id', $college->getKey())->pluck('roles.id')->all();
            $this->roles->syncForCollege($user, (int) $college->getKey(), $roles);
            $this->audit->record('users.roles_assigned', $user, ['role_ids' => $before], ['role_ids' => $roles->modelKeys()]);
        });
    }

    public function linkExisting(int $id, User $actor): void
    {
        Gate::forUser($actor)->authorize('linkExisting', User::class);
        DB::transaction(function () use ($id): void {
            $college = $this->lockCollege();
            $user = User::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            if (! $user->colleges()->whereKey($college->getKey())->exists()) {
                $default = ! $user->colleges()->withTrashed()->exists();
                $user->colleges()->attach($college->getKey(), ['is_default' => $default]);
                $this->audit->record('users.college_linked', $user, [], ['college_id' => $college->getKey()]);
            }
        });
    }

    private function lockCollege(): College
    {
        return College::query()->whereKey($this->tenant->require()->getKey())->lockForUpdate()->firstOrFail();
    }

    private function translateDuplicateEmail(QueryException $exception): never
    {
        if (in_array((string) $exception->getCode(), ['23000', '23505'], true)
            && (str_contains(strtolower($exception->getMessage()), 'users_email_unique')
                || str_contains(strtolower($exception->getMessage()), 'users.email'))) {
            throw ValidationException::withMessages(['email' => 'This email address is unavailable.']);
        }
        throw $exception;
    }
}
