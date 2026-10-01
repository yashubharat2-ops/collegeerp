@extends('layouts.app'){{-- Interface preferences the signed-in user owns for themselves
    (route name `preferences.edit`). Every switch below comes from one entry of
    UserPreferenceService::DEFINITIONS, and each entry is wired: this page renders a
    checkbox only for a preference the application really reads — the sidebar's opening
    state and the header's identity block. Nothing here is a placeholder, and nothing an
    administrator owns is editable from here: college-wide settings stay in
    Administration / Settings → System Settings, behind their policy, and this form never
    touches the institutional settings table. --}}
@section('title', 'Preferences')
@section('content')
<div class="max-w-2xl space-y-6">
    <div class="panel">
        <h2 class="panel-title">Interface preferences</h2>
        <p class="panel-subtitle">Saved to your account, applied wherever you sign in, and visible to nobody else. Only preferences this application acts on are listed.</p>
        <form method="POST" action="{{ route('preferences.update') }}" class="mt-6 space-y-4">
            @csrf
            @method('PUT')
            @foreach ($definitions as $key => $definition)
                @php
                    $field = $definition['field'];
                    $enabled = (bool) ($values[$key] ?? $definition['default']);
                @endphp
                {{-- The hidden 0 comes first so an unchecked box is still an explicit
                     "off" in the payload: saving can never silently skip a preference. --}}
                <input type="hidden" name="{{ $field }}" value="0">
                <label class="flex items-start gap-3 rounded-xl bg-slate-50 p-4" for="{{ $field }}">
                    <input class="mt-0.5 h-4 w-4 rounded border-slate-300 text-indigo-600" id="{{ $field }}" name="{{ $field }}" type="checkbox" value="1" @checked($enabled)>
                    <span>
                        <span class="block text-sm font-medium text-slate-800">{{ $definition['label'] }}</span>
                        <span class="mt-1 block text-xs text-slate-500">{{ $definition['help'] }}</span>
                    </span>
                </label>
                @error($field)<p class="text-sm text-rose-600">{{ $message }}</p>@enderror
            @endforeach
            <div class="flex items-center gap-4">
                <button class="button" type="submit">Save preferences</button>
                <a class="text-sm font-semibold text-slate-600 hover:text-indigo-700" href="{{ route('profile.edit') }}">Back to profile</a>
            </div>
        </form>
    </div>
    <div class="panel">
        <h3 class="panel-title">Owned by your college</h3>
        <p class="panel-subtitle">Branding, academic configuration, notification routing and every other college-wide setting belong to the Administration module and its settings policies. They are intentionally absent from this screen: an account panel must not become a second place to change the ERP for everybody else.</p>
    </div>
</div>
@endsection
