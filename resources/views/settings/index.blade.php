@extends('layouts.app')
@section('title', 'Institution Settings')
@section('content')
@include('administration.partials.context')
<div class="max-w-4xl space-y-6">
    <div class="panel"><h2 class="panel-title">Institution Settings</h2><p class="panel-subtitle">Identity and contact details come from the existing college record. Branding is stored in this college's existing institutional settings. No arbitrary settings, secrets or environment values are exposed.</p>
        <p class="mt-3 text-sm text-slate-500">Institution code: <span class="font-mono">{{ $institution->code }}</span> · College ownership, status and stable identifiers are not editable here.</p>
        <form method="POST" enctype="multipart/form-data" action="{{ route('admin.institution-settings.update') }}" class="mt-6">@csrf @method('PUT')
            <fieldset class="space-y-6" @disabled(! auth()->user()->can('manage', App\Models\InstitutionalSetting::class))>
                <div class="grid gap-4 md:grid-cols-2">
                    <div class="md:col-span-2"><label class="label" for="name">Institution name</label><input class="input" id="name" name="name" value="{{ old('name', $institution->name) }}" maxlength="255" required></div>
                    <div><label class="label" for="email">Contact email</label><input class="input" id="email" name="email" type="email" value="{{ old('email', $institution->email) }}" maxlength="254"></div>
                    <div><label class="label" for="phone">Contact phone</label><input class="input" id="phone" name="phone" value="{{ old('phone', $institution->phone) }}" maxlength="50"></div>
                    <div class="md:col-span-2"><label class="label" for="address">Address</label><textarea class="input" id="address" name="address" rows="3" maxlength="2000">{{ old('address', $institution->address) }}</textarea></div>
                </div>
                <div class="border-t border-slate-200 pt-6"><h3 class="panel-title">Basic branding</h3><p class="panel-subtitle">The navigation uses this short name and privately stored logo for the active college.</p>
                    <div class="mt-4 grid gap-4 md:grid-cols-2"><div><label class="label" for="short_name">Navigation short name</label><input class="input" id="short_name" name="short_name" value="{{ old('short_name', $branding['short_name']) }}" maxlength="80"><p class="mt-1 text-xs text-slate-500">Leave blank to use the product name.</p></div><div><label class="label" for="logo">Institution logo</label><input class="input" id="logo" name="logo" type="file" accept="image/png,image/jpeg,image/webp"><p class="mt-1 text-xs text-slate-500">PNG, JPEG or WebP. Maximum 2 MB and 2,000 × 2,000 pixels. SVG is not accepted.</p>@error('logo')<p class="mt-1 text-sm text-rose-600">{{ $message }}</p>@enderror</div></div>
                    @if($branding['has_logo'])<div class="mt-4 flex flex-wrap items-center gap-4"><img class="h-20 w-20 rounded-xl border border-slate-200 object-contain" src="{{ route('admin.institution-settings.logo') }}" alt="{{ $institution->name }} logo"><label class="flex items-center gap-2 text-sm"><input name="remove_logo" type="checkbox" value="1" @checked(old('remove_logo'))> Remove current logo</label></div>@endif
                </div>
                @can('manage', App\Models\InstitutionalSetting::class)<button class="button" type="submit">Save institution settings</button>@endcan
            </fieldset>
        </form>
        @cannot('manage', App\Models\InstitutionalSetting::class)<p class="mt-4 text-sm text-slate-500">Read-only: your role permits viewing institution settings, not changing them.</p>@endcannot
    </div>
    <div class="panel"><h3 class="panel-title">Academic session display</h3><p class="panel-subtitle">The active session is derived from the existing Academic Year master, not a second display setting.</p>
        @if($activeYear)<p class="mt-4 text-sm font-semibold">{{ $activeYear->name }}</p>@else<p class="mt-4 text-sm text-slate-500">No active session is available to display with your current permissions.</p>@endif
        @can('viewAcademicConfiguration', App\Models\InstitutionalSetting::class)<a class="mt-4 inline-block text-sm font-semibold text-indigo-600 hover:underline" href="{{ route('admin.academic-config.index') }}">Open Academic Configuration →</a>@endcan
    </div>
</div>
@endsection
