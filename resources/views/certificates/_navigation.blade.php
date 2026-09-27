<div class="no-print mb-6 flex flex-wrap gap-4 text-sm">
    @if(auth()->user()->hasPermission('certificates.view'))<a href="{{ route('certificates.index') }}">All certificates</a>@endif
    @if(auth()->user()->hasPermission('certificate_types.manage'))<a href="{{ route('certificates.types') }}">Manage custom certificate types</a>@endif
    @if(auth()->user()->hasPermission('certificate_templates.manage'))<a href="{{ route('certificates.templates') }}">Certificate Templates</a>@endif
    @if(auth()->user()->hasPermission('certificate_reports.view'))<a href="{{ route('certificates.reports') }}">Certificate Reports</a>@endif
</div>
