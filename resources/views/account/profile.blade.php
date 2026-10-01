@extends('layouts.app'){{-- The signed-in user's own profile (route name `profile.edit`). Two
    panels, and the split between them is the whole point: the first holds the two fields
    the user owns — name and e-mail — and the second holds everything that decides what
    they may see inside the ERP, read live from the same relations the rest of the
    application uses and rendered as text, never as a control. The account on this page
    is always the one in the session: the route takes no user identifier and
    UpdateProfileRequest prohibits status, roles, permissions and college membership
    outright, so this screen cannot widen anybody's access — not even the visitor's own. --}}
@section('title', 'My Profile')
@section('content')
<div class="space-y-6">
    <div class="panel">
        <h2 class="panel-title">Your details</h2>
        <p class="panel-subtitle">How you are addressed across the ERP. Your administrator assigns everything else.</p>
        <form method="POST" action="{{ route('profile.update') }}" class="mt-6 grid gap-4 md:grid-cols-2">
            @csrf
            @method('PUT')
            <div>
                <label class="label" for="name">Full name</label>
                <input class="input" id="name" name="name" value="{{ old('name', $profileUser->name) }}" maxlength="255" required autocomplete="name">
                @error('name')<p class="mt-1 text-sm text-rose-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="label" for="email">E-mail address</label>
                <input class="input" id="email" name="email" type="email" value="{{ old('email', $profileUser->email) }}" maxlength="254" required autocomplete="email">
                @error('email')<p class="mt-1 text-sm text-rose-600">{{ $message }}</p>@enderror
            </div>
            <div class="flex flex-wrap items-center gap-4 md:col-span-2">
                <button class="button" type="submit">Save changes</button>
                <a class="text-sm font-semibold text-indigo-600 hover:underline" href="{{ route('password.change.edit') }}">Change password</a>
                <a class="text-sm font-semibold text-indigo-600 hover:underline" href="{{ route('preferences.edit') }}">Preferences</a>
            </div>
        </form>
    </div>

    <div class="panel">
        <h3 class="panel-title">Role &amp; access</h3>
        <p class="panel-subtitle">Read-only by design. Account status, roles, permissions and college membership are maintained in the Administration module, where they are gated by policy.</p>
        <dl class="mt-6 grid gap-3 text-sm sm:grid-cols-3">
            <div class="rounded-xl bg-slate-50 p-3">
                <dt class="text-slate-500">Account status</dt>
                <dd class="mt-1 font-semibold">{{ $profileUser->is_active ? 'Active' : 'Inactive' }}</dd>
            </div>
            <div class="rounded-xl bg-slate-50 p-3">
                <dt class="text-slate-500">Active college</dt>
                <dd class="mt-1 font-semibold">{{ $activeCollege?->name ?? 'No college context is active' }}</dd>
            </div>
            <div class="rounded-xl bg-slate-50 p-3">
                <dt class="text-slate-500">Last sign-in</dt>
                <dd class="mt-1 font-semibold">{{ $profileUser->last_login_at?->format('d M Y, H:i') ?? 'Not recorded' }}</dd>
            </div>
        </dl>
        @if ($profileUser->isSuperAdmin())
            <p class="mt-4 inline-block rounded-full bg-indigo-50 px-3 py-1 text-xs font-semibold text-indigo-700">Platform administrator — this account can reach every active college.</p>
        @endif
        <h4 class="mt-6 text-sm font-semibold">Roles assigned to you</h4>
        @if ($assignments->isEmpty())
            <p class="mt-2 text-sm text-slate-500">No role is assigned to your account yet, so module access comes only from the platform administrator role if you hold one. Ask an administrator to assign a role for your college.</p>
        @else
            <div class="mt-3 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b text-slate-500">
                            <th class="py-3 pr-4">Role</th>
                            <th class="py-3 pr-4">Scoped to</th>
                            <th class="py-3 pr-4">Permissions</th>
                            <th class="py-3">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($assignments as $assignment)
                            <tr class="border-b">
                                <td class="py-3 pr-4 font-medium text-slate-800">{{ $assignment->name }}<span class="ml-2 font-mono text-xs text-slate-400">{{ $assignment->slug }}</span></td>
                                <td class="py-3 pr-4">{{ $assignment->college?->name ?? 'Every college' }}</td>
                                <td class="py-3 pr-4">{{ $assignment->permissions_count }}</td>
                                <td class="py-3">{{ $assignment->is_active ? 'Active' : 'Inactive' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
        <p class="mt-6 text-xs text-slate-500">A profile picture is not part of the user record in this ERP, which is why the header shows your initials.</p>
    </div>
</div>
@endsection
