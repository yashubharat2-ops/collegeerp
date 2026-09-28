@extends('layouts.app')
@section('title', 'Finance Reports')
@section('content')
<div class="print-area space-y-5">
    <div class="panel">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="panel-title">Finance Reports</h2>
                <p class="panel-subtitle">Read-only reports from the existing fee structures, fee categories, fee assignments, collections, receipts, dues, discounts / concessions, refunds and the transport and hostel fee charges recorded through the same Finance payment rows — for the active college. Nothing here is calculated or saved: every figure is read live from the operational records.</p>
            </div>
            <span class="rounded-lg bg-indigo-50 px-3 py-2 text-xs font-semibold text-indigo-700">{{ $reports[$report] }}</span>
        </div>
        @include('finance_reports._navigation', ['selected' => $report])
        @include('finance_reports._filters')
    </div>

    <div class="panel">
        <h3 class="panel-title">{{ $reports[$report] }}</h3>
        @include('finance_reports.reports.'.$report)
    </div>
</div>
@endsection
