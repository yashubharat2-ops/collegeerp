@extends('layouts.app')
@section('title', 'Roles')
@section('content')
@include('administration.partials.context')
<div class="space-y-6">
    <div class="panel">
        <div class="flex flex-wrap items-start justify-between gap-4"><div><h2 class="panel-title">College roles</h2><p class="panel-subtitle">The existing RBAC role definitions for this college. System roles and your own authority are protected.</p></div>@can('create', App\Models\Role::class)<a class="button" href="{{ route('admin.roles.create') }}">+ New role</a>@endcan</div>
        <form method="GET" action="{{ route('admin.roles.index') }}" class="mt-6 grid gap-3 md:grid-cols-3">
            <div><label class="label" for="search">Role name or identifier</label><input class="input" type="search" name="search" id="search" maxlength="100" value="{{ $filters['search'] ?? '' }}"></div>
            <div><label class="label" for="status">Status</label><select class="input" name="status" id="status"><option value="">All statuses</option><option value="active" @selected(($filters['status'] ?? '') === 'active')>Active</option><option value="inactive" @selected(($filters['status'] ?? '') === 'inactive')>Inactive</option></select></div>
            <div class="flex items-end gap-2"><button class="button" type="submit">Filter</button><a class="button !bg-slate-200 !text-slate-700" href="{{ route('admin.roles.index') }}">Clear</a></div>
        </form>
        <div class="mt-6 overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr class="border-b text-slate-500"><th class="py-3 pr-4">Role</th><th class="pr-4">Permissions</th><th class="pr-4">College assignments</th><th class="pr-4">Status</th><th class="text-right">Action</th></tr></thead><tbody>
            @forelse($roles as $role)<tr class="border-b"><td class="py-3 pr-4"><p class="font-medium">{{ $role->name }}</p><p class="text-xs text-slate-500">{{ $role->slug }} @if($role->is_system) · System role @endif</p>@if($role->description)<p class="mt-1 max-w-md text-xs text-slate-500">{{ $role->description }}</p>@endif</td><td class="pr-4">{{ $role->permissions_count }}</td><td class="pr-4">{{ $role->users_count }}</td><td class="pr-4">@include('administration.partials.status', ['active' => $role->is_active])</td><td class="text-right">@can('update', $role)<a class="font-semibold text-indigo-600 hover:underline" href="{{ route('admin.roles.edit', $role) }}">Edit / Assign permissions</a>@else<span class="text-xs text-slate-500">Protected / read-only</span>@endcan</td></tr>
            @empty<tr><td class="py-6 text-slate-500" colspan="5">No college roles match the filters.</td></tr>@endforelse
        </tbody></table></div>
        <div class="mt-4">{{ $roles->links() }}</div>
    </div>
    @if($platformRoles->isNotEmpty())
        <div class="panel"><h3 class="panel-title">Platform role definitions</h3><p class="panel-subtitle">Super Admin visibility only. Global/system definitions and platform grants are not edited through college forms. No implicit cross-college permissions are created here.</p>
            <div class="mt-4 grid gap-3 sm:grid-cols-2">@foreach($platformRoles as $role)<div class="rounded-xl bg-slate-50 p-4"><p class="text-sm font-semibold">{{ $role->name }}</p><p class="mt-1 text-xs text-slate-500">{{ $role->slug }} · Read-only platform definition</p></div>@endforeach</div>
        </div>
    @endif
</div>
@endsection
