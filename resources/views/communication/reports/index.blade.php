@extends('layouts.app')

@section('content')
<div class="space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <p class="text-xs font-semibold uppercase tracking-widest text-teal-700">REPORTS · Communication Reports</p>
            <h1 class="text-2xl font-bold text-slate-900">{{ $reportTitle }}</h1>
            <p class="text-sm text-slate-600">
                Read-only college-scoped communication analytics for <span class="font-medium text-slate-800">{{ $college?->name ?? 'Current College' }}</span>.
            </p>
        </div>
        <span class="inline-flex items-center rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">
            Read-Only Report
        </span>
    </div>

    @include('communication.reports._navigation', ['reports' => $reports, 'active' => $report])

    @include('communication.reports._filters')

    @include('communication.reports.reports.' . $report)
</div>
@endsection
