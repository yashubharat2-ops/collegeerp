@if(auth()->user()?->hasPermission('certificates.view') || auth()->user()?->hasPermission('certificate_types.manage') || auth()->user()?->hasPermission('certificate_templates.manage') || auth()->user()?->hasPermission('certificate_reports.view'))
<div class="px-3 pb-2 pt-6 text-xs font-semibold uppercase tracking-widest text-slate-500">CERTIFICATE MANAGEMENT (EC)</div>
@if(auth()->user()->hasPermission('certificates.view'))
@foreach(\App\Models\CertificateType::BUILT_INS as [$code, $name])
<a class="nav-link" href="{{ route('certificates.index', ['type' => $code, 'stage' => 'requests']) }}">{{ $name }}</a>
@endforeach
@elseif(auth()->user()->hasPermission('certificate_types.manage'))
<a class="nav-link" href="{{ route('certificates.types') }}">Custom Certificate</a>
@endif
@if(auth()->user()->hasPermission('certificate_templates.manage'))<a class="nav-link" href="{{ route('certificates.templates') }}">Certificate Templates</a>@endif
@if(auth()->user()->hasPermission('certificate_reports.view'))<a class="nav-link" href="{{ route('certificates.reports') }}">Certificate Reports</a>@endif
@endif
