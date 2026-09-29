@extends('layouts.app')
@section('title', 'Library Reports')
@section('content')
<div class="print-area space-y-5">
    <div class="panel">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="panel-title">Library Reports</h2>
                <p class="panel-subtitle">Read-only reports from the existing book catalogue, book categories, authors, publishers, book copies, library members, issue / return records, renewals and fines — for the active college. Nothing here is calculated or saved: every row and every figure is read live from the operational records.</p>
            </div>
            <span class="rounded-lg bg-indigo-50 px-3 py-2 text-xs font-semibold text-indigo-700">{{ $reports[$report] }}</span>
        </div>
        @include('library_reports._navigation', ['selected' => $report])
        @include('library_reports._filters')
    </div>

    <div class="panel">
        <h3 class="panel-title">{{ $reports[$report] }}</h3>
        @include('library_reports.reports.'.$report)
    </div>
</div>
@endsection
