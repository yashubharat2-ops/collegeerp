@if(auth()->user()?->hasPermission('certificates.view') || auth()->user()?->hasPermission('certificate_types.manage') || auth()->user()?->hasPermission('certificate_templates.manage') || auth()->user()?->hasPermission('certificate_reports.view'))
<div class="px-3 pb-2 pt-6 text-xs font-semibold uppercase tracking-widest text-slate-500">CERTIFICATE MANAGEMENT (EC)</div>
@if(auth()->user()->hasPermission('certificates.view'))
@php
    $certificateMenuTypes = collect(\App\Models\CertificateType::BUILT_INS)->map(fn ($item) => ['code' => $item[0], 'name' => $item[1]])->values();
    $certificateMenuTypes = $certificateMenuTypes->concat(\App\Models\CertificateType::whereNull('builtin_key')->orderBy('name')->get(['code', 'name'])->toArray());
@endphp
@foreach($certificateMenuTypes as $menuType)
<details class="px-3 py-2" @if(request('type') === $menuType['code']) open @endif>
    <summary class="cursor-pointer text-sm">{{ $menuType['name'] }}</summary>
    @foreach(['requests', 'generation', 'issuance', 'verification'] as $menuStage)
    <a class="nav-link text-xs" href="{{ route('certificates.index', ['type' => $menuType['code'], 'stage' => $menuStage]) }}">{{ ucfirst($menuStage) }}</a>
    @endforeach
    @if($menuType['code'] === 'CUSTOM' && auth()->user()->hasPermission('certificate_types.manage'))<a class="nav-link text-xs" href="{{ route('certificates.types') }}">Manage custom types</a>@endif
</details>
@endforeach
@endif
@if(auth()->user()->hasPermission('certificate_types.manage'))<a class="nav-link" href="{{ route('certificates.types') }}">Certificate Types</a>@endif
@if(auth()->user()->hasPermission('certificate_templates.manage'))<a class="nav-link" href="{{ route('certificates.templates') }}">Certificate Templates</a>@endif
@if(auth()->user()->hasPermission('certificate_reports.view'))<a class="nav-link" href="{{ route('certificates.reports') }}">Certificate Reports</a>@endif
@endif
