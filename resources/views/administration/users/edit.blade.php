@extends('layouts.app')
@section('title', 'Manage User')
@section('content')
@include('administration.partials.context')
<div class="max-w-4xl space-y-6">
    <div class="panel"><div class="flex flex-wrap items-center justify-between gap-3"><div><h2 class="panel-title">{{ $managedUser->name }}</h2><p class="panel-subtitle">{{ $managedUser->email }}</p></div><a class="button !bg-slate-200 !text-slate-700" href="{{ route('admin.users.index') }}">Back to users</a></div>
        @if($isShared)<p class="mt-4 rounded-xl bg-amber-50 p-4 text-sm text-amber-800">This is a shared login identity. Only a Super Admin may change its global profile or account status. College role changes affect only this college.</p>@endif
    </div>
    <div class="panel">
        <h3 class="panel-title">Login profile</h3><p class="panel-subtitle">Passwords, session credentials and platform grants are never editable on this page.</p>
        @can('update', $managedUser)
            <form method="POST" action="{{ route('admin.users.update', $managedUser) }}" class="mt-6 space-y-4">@csrf @method('PUT')
                <div class="grid gap-4 md:grid-cols-2"><div><label class="label" for="name">Name</label><input class="input" name="name" id="name" value="{{ old('name', $managedUser->name) }}" maxlength="255" required></div><div><label class="label" for="email">Email</label><input class="input" name="email" id="email" type="email" value="{{ old('email', $managedUser->email) }}" maxlength="254" required></div></div>
                <button class="button" type="submit">Save profile</button>
            </form>
        @else<p class="mt-4 text-sm text-slate-500">This identity's global profile is protected or your role does not permit profile edits.</p>@endcan
    </div>
    @can('assignRoles', $managedUser)
        <div class="panel"><h3 class="panel-title">College role assignments</h3><p class="panel-subtitle">Saving replaces this college's role assignments, including inactive ones. Other colleges and global/platform grants are preserved.</p>
            <form method="POST" action="{{ route('admin.users.roles.update', $managedUser) }}" class="mt-6 space-y-4">@csrf @method('PATCH')<input type="hidden" name="roles" value="">
                <div class="grid gap-3 sm:grid-cols-2">@forelse($roles as $role)<label class="flex items-center gap-2 rounded-xl border border-slate-200 p-3 text-sm"><input type="checkbox" name="roles[]" value="{{ $role->id }}" @checked(in_array($role->id, old('roles', $selectedRoles) ?? []))>{{ $role->name }}</label>@empty<p class="text-sm text-slate-500">No grantable active college roles.</p>@endforelse</div>
                <p class="text-xs text-slate-500">Leave all roles unchecked to revoke access to college modules. Your own authority cannot be changed here.</p><button class="button" type="submit">Save college roles</button>
            </form>
        </div>
    @endcan
    @can('updateStatus', $managedUser)
        <div class="panel"><h3 class="panel-title">Account status</h3><p class="panel-subtitle">The existing active flag controls login and permissions across all memberships. Deactivation revokes remembered login and database-backed sessions where configured.</p>
            <form method="POST" action="{{ route('admin.users.status', $managedUser) }}" class="mt-4 flex flex-wrap items-center gap-4" onsubmit="return confirm('Change this global login account status?')">@csrf @method('PATCH')<input type="hidden" name="is_active" value="{{ $managedUser->is_active ? 0 : 1 }}">@include('administration.partials.status', ['active' => $managedUser->is_active])<button class="button" type="submit">{{ $managedUser->is_active ? 'Deactivate account' : 'Activate account' }}</button></form>
        </div>
    @endcan
</div>
@endsection
