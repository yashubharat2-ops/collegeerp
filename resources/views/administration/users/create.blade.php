@extends('layouts.app')
@section('title', 'Create User')
@section('content')
@include('administration.partials.context')
<div class="panel max-w-4xl">
    <h2 class="panel-title">New college user</h2>
    <p class="panel-subtitle">Creates an account in the existing authentication system and associates it only with the active college. No initial password is supplied or displayed.</p>
    <form method="POST" action="{{ route('admin.users.store') }}" class="mt-6 space-y-6">@csrf
        <div class="grid gap-4 md:grid-cols-2">
            <div><label class="label" for="name">Name</label><input class="input" id="name" name="name" maxlength="255" value="{{ old('name') }}" required autocomplete="name">@error('name')<p class="mt-1 text-sm text-rose-600">{{ $message }}</p>@enderror</div>
            <div><label class="label" for="email">Email</label><input class="input" id="email" name="email" type="email" maxlength="254" value="{{ old('email') }}" required autocomplete="email">@error('email')<p class="mt-1 text-sm text-rose-600">{{ $message }}</p>@enderror</div>
        </div>
        <div><input type="hidden" name="is_active" value="0"><label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', true))> Account active</label></div>
        @if(auth()->user()->hasPermission('users.assign_roles'))
            <fieldset><legend class="label">College roles</legend><p class="mb-3 text-sm text-slate-500">Only active roles you are authorized to grant are offered. Platform roles are not assignable here.</p>
                <div class="grid gap-3 sm:grid-cols-2">@forelse($roles as $role)<label class="flex items-center gap-2 rounded-xl border border-slate-200 p-3 text-sm"><input type="checkbox" name="roles[]" value="{{ $role->id }}" @checked(in_array($role->id, old('roles', [])))>{{ $role->name }}</label>@empty<p class="text-sm text-slate-500">No grantable active college roles. The account can be created without roles.</p>@endforelse</div>
                @error('roles')<p class="mt-2 text-sm text-rose-600">{{ $message }}</p>@enderror
            </fieldset>
        @endif
        <div class="rounded-xl bg-slate-50 p-4 text-sm text-slate-600">The user sets their own password using <a class="text-indigo-600 hover:underline" href="{{ route('password.request') }}">Forgot Password</a>. This uses the existing password broker and configured mail delivery. Creating an account does not send a message.</div>
        <div class="flex gap-3"><button class="button" type="submit">Create user</button><a class="button !bg-slate-200 !text-slate-700" href="{{ route('admin.users.index') }}">Cancel</a></div>
    </form>
</div>
@endsection
