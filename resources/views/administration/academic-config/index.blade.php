@extends('layouts.app')
@section('title', 'Academic Configuration')
@section('content')
@include('administration.partials.context')
<div class="space-y-6">
    <div class="panel"><h2 class="panel-title">Academic Configuration</h2><p class="panel-subtitle">One entry point to the existing academic masters. Every record, relationship, validator and policy stays in its original module; no duplicate configuration tables are used.</p>
        @if($activeYear)<p class="mt-4 rounded-xl bg-indigo-50 p-4 text-sm text-indigo-700">Active session: <strong>{{ $activeYear->name }}</strong> · {{ $activeYear->starts_on?->format('d M Y') }} – {{ $activeYear->ends_on?->format('d M Y') }}</p>@endif
    </div>
    <div class="grid gap-6 md:grid-cols-2 xl:grid-cols-3">
        @foreach($configuration as $master)
            <div class="panel" data-master="{{ $master['route'] }}"><div class="flex justify-between gap-3"><h3 class="panel-title">{{ $master['label'] }}</h3><span class="rounded-full bg-slate-100 px-3 py-1 text-sm font-semibold">{{ $master['count'] }}</span></div><p class="panel-subtitle">{{ $master['description'] }}</p>
                <ul class="mt-4 space-y-1 text-sm text-slate-600">@forelse($master['samples'] as $name)<li class="truncate" title="{{ $name }}">{{ $name }}</li>@empty<li>No records configured in this college.</li>@endforelse</ul>
                <div class="mt-5 flex flex-wrap gap-2"><a class="button" href="{{ route($master['route']) }}">Manage</a>@if($master['createRoute'])<a class="button !bg-slate-200 !text-slate-700" href="{{ route($master['createRoute']) }}">+ New</a>@endif</div>
            </div>
        @endforeach
    </div>
</div>
@endsection
