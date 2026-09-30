@extends('layouts.app')
@section('title', 'System Settings')
@section('content')
@include('administration.partials.context', ['platform' => ! $institution])
<div class="max-w-5xl space-y-6">
    <div class="panel"><h2 class="panel-title">System Settings</h2><p class="panel-subtitle">College-owned settings and deployment-managed platform configuration are separate. This page is read-only: it cannot disable security, change infrastructure, expose credentials or write environment values.</p></div>
    <div class="panel"><h3 class="panel-title">College scope</h3>
        @if($institution)<p class="panel-subtitle">The active institution is {{ $institution->name }}. College identity and branding are managed in Institution Settings; academic configuration and template availability keep their original owners and policies.</p>@can('viewAny', App\Models\InstitutionalSetting::class)<a class="mt-4 inline-block text-sm font-semibold text-indigo-600 hover:underline" href="{{ route('admin.institution-settings.index') }}">Open Institution Settings →</a>@endcan
        @else<p class="panel-subtitle">No college has been selected. Choose an institution through the existing college-context switch before using college configuration.</p>@endif
        @if(isset($switchableColleges) && $switchableColleges->isNotEmpty())
            <form method="POST" action="{{ route('college-context.switch') }}" class="mt-4 flex flex-wrap items-end gap-3">@csrf<div class="min-w-0 flex-1"><label class="label" for="college_id">Authorized college selection</label><select class="input" name="college_id" id="college_id" required><option value="">Select a college</option>@foreach($switchableColleges as $college)<option value="{{ $college->id }}" @selected($institution?->id === $college->id)>{{ $college->name }}</option>@endforeach</select></div><button class="button" type="submit">Switch college context</button></form>
        @endif
    </div>
    <div class="panel"><h3 class="panel-title">Enforced security behaviour</h3><ul class="mt-4 grid gap-3 text-sm text-slate-600 sm:grid-cols-2"><li class="rounded-xl bg-slate-50 p-4">Tenant context is resolved by the existing middleware. CollegeScope does not implicitly bypass isolation for Super Admins.</li><li class="rounded-xl bg-slate-50 p-4">Module access uses the existing database-driven roles, permissions and resource policies.</li><li class="rounded-xl bg-slate-50 p-4">Write forms require authentication, authorization and CSRF protection. No credentials are editable here.</li><li class="rounded-xl bg-slate-50 p-4">Audit records are immutable. Institution logos and operational files remain on private storage.</li></ul></div>
    @if($platformSettings !== [])
        <div class="panel" data-settings-scope="platform"><h3 class="panel-title">Platform configuration · Super Admin</h3><p class="panel-subtitle">An explicit allowlist of safe runtime values, shared across colleges. The existing system has no platform settings store; changes belong to the deployment configuration, not college settings rows.</p><dl class="mt-4 divide-y divide-slate-100">@foreach($platformSettings as $label => $value)<div class="flex flex-wrap justify-between gap-3 py-3 text-sm"><dt class="text-slate-500">{{ $label }}</dt><dd class="font-semibold">{{ $value }}</dd></div>@endforeach</dl></div>
    @else<div class="panel"><h3 class="panel-title">Platform configuration</h3><p class="panel-subtitle">Deployment-level configuration is visible only to authorized Super Admins. College administrators cannot change platform settings.</p></div>@endif
</div>
@endsection
