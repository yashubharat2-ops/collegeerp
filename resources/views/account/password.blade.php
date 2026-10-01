@extends('layouts.app')
@section('title', 'Change Password')
@section('content')
<div class="max-w-2xl space-y-6">
    <div class="panel">
        <h2 class="panel-title">Change your password</h2>
        <p class="panel-subtitle">Confirm the password you sign in with, then choose a new one. The same twelve-character rule the password reset screen enforces applies here.</p>
        <form method="POST" action="{{ route('password.change.store') }}" class="mt-6 space-y-4" autocomplete="off">
            @csrf
            @method('PUT')
            <div>
                <label class="label" for="current_password">Current password</label>
                <input class="input" id="current_password" name="current_password" type="password" required autocomplete="current-password">
                @error('current_password')<p class="mt-1 text-sm text-rose-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="label" for="password">New password</label>
                <input class="input" id="password" name="password" type="password" required minlength="12" autocomplete="new-password">
                @error('password')<p class="mt-1 text-sm text-rose-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="label" for="password_confirmation">Confirm new password</label>
                <input class="input" id="password_confirmation" name="password_confirmation" type="password" required minlength="12" autocomplete="new-password">
            </div>
            <div class="flex items-center gap-4">
                <button class="button" type="submit">Update password</button>
                <a class="text-sm font-semibold text-slate-600 hover:text-indigo-700" href="{{ route('profile.edit') }}">Back to profile</a>
            </div>
        </form>
    </div>
    <div class="panel">
        <p class="text-sm text-slate-600">Changing your password does not sign you out of this device, and it does not change anything else about your account — your e-mail address lives on <a class="font-semibold text-indigo-600 hover:underline" href="{{ route('profile.edit') }}">your profile</a>. If you cannot remember your current password, sign out and use the reset link on the sign-in screen.</p>
    </div>
</div>
@endsection
