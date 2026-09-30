<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Services\Administration\RoleAdministrationService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class PermissionsController extends Controller
{
    public function __invoke(Request $request, RoleAdministrationService $roles): View
    {
        $this->authorize('viewAny', Permission::class);
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'module' => ['nullable', 'string', 'max:80']]);
        $query = Permission::query()->orderBy('module')->orderBy('action')->orderBy('slug');
        if ($search = trim($filters['search'] ?? '')) {
            $query->where(fn ($q) => $q->where('name', 'like', '%'.$search.'%')->orWhere('slug', 'like', '%'.$search.'%'));
        }
        if (! empty($filters['module'])) {
            $query->where('module', $filters['module']);
        }

        return view('administration.permissions.index', [
            'permissions' => $query->get()->groupBy('module'),
            'modules' => Permission::query()->distinct()->orderBy('module')->pluck('module'),
            'filters' => $filters,
            'platform' => ! app(TenantContext::class)->has(),
            'editableRoles' => app(TenantContext::class)->has() && $request->user()->hasPermission('roles.update')
                ? $roles->query()->orderBy('name')->get()->filter(fn ($role) => Gate::allows('update', $role)) : collect(),
        ]);
    }
}
