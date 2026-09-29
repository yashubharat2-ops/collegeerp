@extends('layouts.app')
@section('title', 'Transport Reports')
@section('content')
<div class="print-area space-y-5">
    <div class="panel">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="panel-title">Transport Reports</h2>
                <p class="panel-subtitle">Read-only reports from the existing vehicles, drivers, routes / stops, student transport assignments and transport fee records — for the active college. Nothing here is calculated away from the operational records or saved: every row and every figure is read live, and money figures come from the same ledger the Transport Fees screen shows.</p>
            </div>
            <span class="rounded-lg bg-indigo-50 px-3 py-2 text-xs font-semibold text-indigo-700">{{ $reports[$report] }}</span>
        </div>
        @include('transport.reports._navigation', ['selected' => $report])
        @include('transport.reports._filters')
    </div>

    <div class="panel">
        <h3 class="panel-title">{{ $reports[$report] }}</h3>
        @include('transport.reports.reports.'.$report)
    </div>
</div>
@endsection
