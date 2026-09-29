@extends('layouts.app')

@section('title', 'Hostel Reports')

@section('content')
<div class="space-y-5">
    <section class="panel">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="panel-title">Hostel Reports</h2>
                <p class="panel-subtitle">Read-only reports built from the active college’s existing Hostel, Student and Finance records. Occupancy follows Hostel Allocations; hostel fee balances use the shared HostelFeeService / FeeLedger.</p>
            </div>
            <span class="rounded-lg bg-indigo-50 px-3 py-2 text-xs font-semibold text-indigo-700">{{ $reports[$report] }}</span>
        </div>

        @include('hostel_reports._navigation')
        @if($visible !== [])
            @include('hostel_reports._filters')
        @endif
    </section>

    <section class="panel">
        <h3 class="panel-title">{{ $reports[$report] }}</h3>
        @include('hostel_reports.reports.'.$report)
    </section>
</div>
@endsection
