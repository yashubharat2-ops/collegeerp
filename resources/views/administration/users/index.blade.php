@extends('layouts.app')
@section('title', 'Users')
@section('content')
@include('administration.partials.context')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div><h2 class="panel-title">Users</h2><p class="panel-subtitle">Existing login accounts associated with the active college. Role grants shown here belong only to this college.</p></div>
        <div class="flex flex-wrap gap-2">
            @can('linkExisting', App\Models\User::class)<a class="button !bg-slate-200 !text-slate-700" href="{{ route('admin.users.link') }}">Link existing account</a>@endcan
            @can('create', App\Models\User::class)<a class="button" href="{{ route('admin.users.create') }}">+ New user</a>@endcan
        </div>
    </div>
    <form method="GET" action="{{ route('admin.users.index') }}" class="mt-6 grid gap-3 md:grid-cols-3">
        <div><label class="label" for="search">Name or email</label><input class="input" id="search" name="search" type="search" maxlength="100" value="{{ $filters['search'] ?? '' }}"></div>
        <div><label class="label" for="status">Account status</label><select class="input" id="status" name="status"><option value="">All statuses</option><option value="active" @selected(($filters['status'] ?? '') === 'active')>Active</option><option value="inactive" @selected(($filters['status'] ?? '') === 'inactive')>Inactive</option></select></div>
        <div class="flex items-end gap-2"><button class="button" type="submit">Filter</button><a class="button !bg-slate-200 !text-slate-700" href="{{ route('admin.users.index') }}">Clear</a></div>
    </form>
    <div class="mt-6 overflow-x-auto">
        <table class="w-full text-left text-sm"><thead><tr class="border-b text-slate-500"><th class="py-3 pr-4">User</th><th class="pr-4">College roles</th><th class="pr-4">Status</th><th class="pr-4">Last login (UTC)</th><th class="text-right">Actions</th></tr></thead><tbody>
            @forelse($users as $managedUser)
                <tr class="border-b align-top">
                    <td class="py-3 pr-4"><p class="font-medium">{{ $managedUser->name }}</p><p class="text-slate-500">{{ $managedUser->email }}</p>@if($managedUser->has_platform_role)<p class="mt-1 text-xs text-indigo-600">Protected platform account</p>@elseif($managedUser->colleges_count > 1)<p class="mt-1 text-xs text-slate-500">Shared login identity</p>@endif</td>
                    <td class="py-3 pr-4">{{ $managedUser->roles->pluck('name')->join(', ') ?: 'No college roles' }}</td>
                    <td class="py-3 pr-4">@include('administration.partials.status', ['active' => $managedUser->is_active])</td>
                    <td class="whitespace-nowrap py-3 pr-4">{{ $managedUser->last_login_at?->utc()->format('d M Y, H:i') ?? 'Never' }}</td>
                    <td class="py-3 text-right"><div class="flex flex-wrap justify-end gap-3">
                        @if(auth()->user()->can('update', $managedUser) || auth()->user()->can('assignRoles', $managedUser))<a class="font-semibold text-indigo-600 hover:underline" href="{{ route('admin.users.edit', $managedUser) }}">Manage</a>@endif
                        @can('updateStatus', $managedUser)
                            <form method="POST" action="{{ route('admin.users.status', $managedUser) }}" onsubmit="return confirm('Change this login account status? Shared accounts are affected in every college.')">@csrf @method('PATCH')<input type="hidden" name="is_active" value="{{ $managedUser->is_active ? 0 : 1 }}"><button class="font-semibold text-slate-600 hover:text-indigo-600" type="submit">{{ $managedUser->is_active ? 'Deactivate' : 'Activate' }}</button></form>
                        @endcan
                    </div></td>
                </tr>
            @empty<tr><td colspan="5" class="py-6 text-slate-500">No users match the filters in this college.</td></tr>@endforelse
        </tbody></table>
    </div>
    <div class="mt-4 flex flex-wrap items-center justify-between gap-3"><p class="text-xs text-slate-500">{{ $users->total() }} college-associated accounts.</p>{{ $users->links() }}</div>
</div>
@endsection
