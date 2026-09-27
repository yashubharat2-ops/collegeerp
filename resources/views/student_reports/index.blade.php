@extends('layouts.app')
@section('title', 'Student Reports')
@section('content')
<div class="print-area space-y-5">
    <div class="panel">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="panel-title">Student Reports</h2>
                <p class="panel-subtitle">Read-only reports from existing student, admission, enrollment and lifecycle records for the active college.</p>
            </div>
            <span class="rounded-lg bg-indigo-50 px-3 py-2 text-xs font-semibold text-indigo-700">{{ $reports[$report] }}</span>
        </div>
        @include('student_reports._navigation', ['selected' => $report])
        @include('student_reports._filters')
    </div>

    <div class="panel">
        <h3 class="panel-title">{{ $reports[$report] }}</h3>
        @include('student_reports.reports.'.$report)
    </div>
</div>
@endsection
