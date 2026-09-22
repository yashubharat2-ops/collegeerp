@extends('layouts.app')
@section('title', 'Transport Dashboard')
@section('content')
<div class="panel">
    <h2 class="panel-title">Transport Dashboard</h2>
    <p class="panel-subtitle">Live transport master statistics for the active college. Archived records are excluded.</p>
    <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @foreach($stats as $label => $count)
            <div class="rounded-lg border border-slate-200 p-5">
                <p class="text-sm text-slate-500">{{ $label }}</p>
                <p class="mt-2 text-3xl font-semibold">{{ $count }}</p>
            </div>
        @endforeach
    </div>
    @if(array_sum($stats) === 0)
        <p class="panel-subtitle mt-6">No transport records yet. Add vehicles, drivers and routes using the permitted master screens.</p>
    @endif
</div>
@endsection
