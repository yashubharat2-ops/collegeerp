<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LinkExistingUserRequest;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Requests\Admin\UpdateUserRolesRequest;
use App\Http\Requests\Admin\UpdateUserStatusRequest;
use App\Models\User;
use App\Services\Administration\UserAdministrationService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class UsersController extends Controller
{
    public function __construct(private readonly UserAdministrationService $users) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', User::class);
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', 'in:active,inactive']]);
        $collegeId = app(TenantContext::class)->require()->getKey();
        $query = $this->users->query()->select(['users.id', 'users.name', 'users.email', 'users.is_active', 'users.last_login_at'])
            ->with(['roles' => fn ($q) => $q->wherePivot('college_id', $collegeId)->where('roles.college_id', $collegeId)])
            ->withCount(['colleges' => fn ($q) => $q->withTrashed()])
            ->withExists(['roles as has_platform_role' => fn ($q) => $q->whereNull('role_user.college_id')])
            ->orderBy('name')->orderBy('id');
        if ($search = trim($filters['search'] ?? '')) {
            $query->where(fn ($q) => $q->where('name', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%'));
        }
        if (isset($filters['status'])) {
            $query->where('is_active', $filters['status'] === 'active');
        }

        return view('administration.users.index', ['users' => $query->paginate(15)->withQueryString(), 'filters' => $filters]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', User::class);

        return view('administration.users.create', ['roles' => $request->user()->hasPermission('users.assign_roles') ? $this->users->roleOptions($request->user()) : collect()]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $this->users->create($request->validated(), $request->user());

        return redirect()->route('admin.users.index')->with('success', 'User created. Password setup uses the existing Forgot Password page.');
    }

    public function edit(Request $request, string $user): View
    {
        $target = $this->users->find($user);
        abort_unless(Gate::allows('update', $target) || Gate::allows('assignRoles', $target), 403);
        $collegeId = app(TenantContext::class)->require()->getKey();

        return view('administration.users.edit', [
            'managedUser' => $target,
            'roles' => Gate::allows('assignRoles', $target) ? $this->users->roleOptions($request->user()) : collect(),
            'selectedRoles' => $target->roles()->wherePivot('college_id', $collegeId)->pluck('roles.id')->all(),
            'isShared' => $target->colleges()->withTrashed()->count() > 1,
        ]);
    }

    public function update(UpdateUserRequest $request, string $user): RedirectResponse
    {
        $this->users->update($request->target(), $request->validated(), $request->user());

        return back()->with('success', 'User profile updated.');
    }

    public function updateStatus(UpdateUserStatusRequest $request, string $user): RedirectResponse
    {
        $this->users->setStatus($request->target(), $request->boolean('is_active'), $request->user());

        return back()->with('success', 'User account status updated.');
    }

    public function updateRoles(UpdateUserRolesRequest $request, string $user): RedirectResponse
    {
        $this->users->assignRoles($request->target(), $request->validated('roles'), $request->user());

        return back()->with('success', 'Roles updated for the active college.');
    }

    public function link(Request $request): View
    {
        $this->authorize('linkExisting', User::class);
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100']]);
        $query = User::query()->select(['id', 'name', 'email', 'is_active'])
            ->whereDoesntHave('colleges', fn ($q) => $q->whereKey(app(TenantContext::class)->require()->getKey()))
            ->orderBy('name')->orderBy('id');
        if ($search = trim($filters['search'] ?? '')) {
            $query->where(fn ($q) => $q->where('name', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%'));
        }

        return view('administration.users.link', ['users' => $query->paginate(15)->withQueryString(), 'filters' => $filters]);
    }

    public function storeLink(LinkExistingUserRequest $request): RedirectResponse
    {
        $this->users->linkExisting((int) $request->validated('user_id'), $request->user());

        return redirect()->route('admin.users.index')->with('success', 'Existing account associated with the active college. Other memberships and role grants are unchanged.');
    }
}
