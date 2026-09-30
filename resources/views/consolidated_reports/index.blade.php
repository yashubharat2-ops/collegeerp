@extends('layouts.app')

@section('title', 'Consolidated Reports')

@section('content')
<div class="print-area space-y-5">
    <div class="panel">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-xs font-semibold uppercase tracking-widest text-indigo-700">REPORTS · Consolidated Reports</p>
                <h2 class="panel-title">Consolidated Reports</h2>
                <p class="panel-subtitle">
                    Read-only college-wide summaries for
                    <span class="font-medium text-slate-800">{{ $college?->name ?? 'the active college' }}</span>,
                    assembled live from the report services that already own each module's data. Nothing is saved twice,
                    nothing is approximated, and no screen here can change a record.
                </p>
            </div>
            <span class="rounded-lg bg-indigo-50 px-3 py-2 text-xs font-semibold text-indigo-700">{{ $reports[$report] }}</span>
        </div>

        @include('consolidated_reports._navigation')

        @if($visible !== [])
            @include('consolidated_reports._filters')
        @endif

        <div class="no-print mt-3 flex flex-wrap items-center gap-3 text-xs text-slate-500" data-shared-reports>
            <span>Module report screens:</span>
            @if(auth()->user()?->can('viewAny', \App\Models\StudentReport::class))
                <a class="font-semibold text-indigo-700 hover:underline" href="{{ route('student-reports.index') }}">Student Reports</a>
            @endif
            @if(auth()->user()?->can('viewAny', \App\Models\AcademicReport::class))
                <a class="font-semibold text-indigo-700 hover:underline" href="{{ route('academic-reports.index') }}">Academic Reports</a>
            @endif
            @if(auth()->user()?->can('viewAny', \App\Models\ExaminationReport::class))
                <a class="font-semibold text-indigo-700 hover:underline" href="{{ route('examination-reports.index') }}">Examination Reports</a>
            @endif
            @if(auth()->user()?->can('viewAny', \App\Models\FinanceReport::class))
                <a class="font-semibold text-indigo-700 hover:underline" href="{{ route('finance-reports.index') }}">Finance Reports</a>
            @endif
            @if(auth()->user()?->can('viewAny', \App\Models\HrReport::class))
                <a class="font-semibold text-indigo-700 hover:underline" href="{{ route('hr-reports.index') }}">HR Reports</a>
            @endif
            @if(auth()->user()?->can('viewAny', \App\Models\LibraryReport::class))
                <a class="font-semibold text-indigo-700 hover:underline" href="{{ route('library-reports.index') }}">Library Reports</a>
            @endif
            @if(auth()->user()?->can('viewAny', \App\Models\TransportReport::class))
                <a class="font-semibold text-indigo-700 hover:underline" href="{{ route('transport-reports.index') }}">Transport Reports</a>
            @endif
            @if(auth()->user()?->can('viewAny', \App\Models\HostelReport::class))
                <a class="font-semibold text-indigo-700 hover:underline" href="{{ route('hostel-reports.index') }}">Hostel Reports</a>
            @endif
            @if(auth()->user()?->can('viewReports', \App\Models\InventoryItem::class))
                <a class="font-semibold text-indigo-700 hover:underline" href="{{ route('inventory-reports.index') }}">Inventory / Asset Reports</a>
            @endif
            @if(auth()->user()?->can('viewAny', \App\Models\CommunicationReport::class))
                <a class="font-semibold text-indigo-700 hover:underline" href="{{ route('communication-reports.index') }}">Communication Reports</a>
            @endif
            @if(auth()->user()?->can('viewAny', \App\Models\CertificateReport::class))
                <a class="font-semibold text-indigo-700 hover:underline" href="{{ route('certificate-reports.index') }}">Certificate Reports</a>
            @endif
        </div>
    </div>

    <div class="panel">
        <h3 class="panel-title">{{ $reports[$report] }}</h3>
        @include('consolidated_reports.reports.'.$report)
    </div>
</div>
@endsection
