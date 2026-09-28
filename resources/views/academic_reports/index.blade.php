@extends('layouts.app')
@section('title', 'Academic Reports')
@section('content')
<div class="print-area space-y-5">
    <div class="panel">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="panel-title">Academic Reports</h2>
                <p class="panel-subtitle">Read-only reports from existing subject enrollment, class/section, faculty assignment, timetable, attendance and academic calendar records for the active college.</p>
            </div>
            <span class="rounded-lg bg-indigo-50 px-3 py-2 text-xs font-semibold text-indigo-700">{{ $reports[$report] }}</span>
        </div>
        @include('academic_reports._navigation', ['selected' => $report])
        @include('academic_reports._filters')
    </div>

    <div class="panel">
        <h3 class="panel-title">{{ $reports[$report] }}</h3>
        @include('academic_reports.reports.'.$report)
    </div>
</div>
@endsection
