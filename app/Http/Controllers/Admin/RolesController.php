<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreRoleRequest;
use App\Http\Requests\Admin\UpdateRoleRequest;
use App\Models\Role;
use App\Services\Administration\RoleAdministrationService;
use App\Services\Authorization\RolePermissionService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RolesController extends Controller
{
    public function __construct(private readonly RoleAdministrationService $roles, private readonly RolePermissionService $permissions) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Role::class);
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', 'in:active,inactive']]);
        $query = $this->roles->query()->withCount('permissions')
            ->withCount(['users' => fn ($q) => $q->where('role_user.college_id', app(TenantContext::class)->id())])->orderBy('name')->orderBy('id');
        if ($search = trim($filters['search'] ?? '')) {
            $query->where(fn ($q) => $q->where('name', 'like', '%'.$search.'%')->orWhere('slug', 'like', '%'.$search.'%'));
        }
        if (isset($filters['status'])) {
            $query->where('is_active', $filters['status'] === 'active');
        }

        return view('administration.roles.index', [
            'roles' => $query->paginate(15)->withQueryString(), 'filters' => $filters,
            'platformRoles' => $request->user()->isSuperAdmin() ? Role::query()->whereNull('college_id')->orderBy('name')->get(['id', 'name', 'slug', 'is_active']) : collect(),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Role::class);

        return view('administration.roles.create', [
            'role' => new Role(['is_active' => true]),
            'permissions' => $this->permissions->grantablePermissions($request->user())->groupBy('module'),
            'selectedPermissions' => [],
        ]);
    }

    public function store(StoreRoleRequest $request): RedirectResponse
    {
        $this->roles->create($request->validated(), $request->user());

        return redirect()->route('admin.roles.index')->with('success', 'College role created.');
    }

    public function edit(Request $request, string $role): View
    {
        $target = $this->roles->find($role);
        $this->authorize('update', $target);

        return view('administration.roles.edit', [
            'role' => $target,
            'permissions' => $this->permissions->grantablePermissions($request->user())->groupBy('module'),
            'selectedPermissions' => $target->permissions()->pluck('permissions.id')->all(),
            'inactivePermissions' => $target->permissions()->where('permissions.is_active', false)->count(),
        ]);
    }

    public function update(UpdateRoleRequest $request, string $role): RedirectResponse
    {
        $this->roles->update($request->target(), $request->validated(), $request->user());

        return back()->with('success', 'Role and permission assignments updated.');
    }
}
