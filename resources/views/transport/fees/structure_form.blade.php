@extends('layouts.app')
@section('title', ($record ? 'Edit' : 'Add').' Transport Fee Structure')
@section('content')
<div class="panel max-w-4xl">
    <h2 class="panel-title">{{ $record ? 'Edit' : 'Add' }} transport fee structure</h2>
    <p class="panel-subtitle">How much does this route / stop combination cost for this academic year and period? Editing the amount never re-prices existing student assignments.</p>
    @if($errors->any())<div class="alert-error mt-4"><ul class="list-inside list-disc">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

    <form method="POST" action="{{ route('transport-fee-structures.'.($record ? 'update' : 'store'), $record ? ['transport_fee_structure' => $record->id] : []) }}" class="mt-6 grid gap-4 sm:grid-cols-2">
        @csrf
        @if($record) @method('PUT') @endif
        <label class="block text-sm font-medium text-slate-700">
            Academic year *
            <select class="input mt-1" name="academic_year_id" required>
                <option value="">— Select academic year —</option>
                @foreach($years as $year)
                    <option value="{{ $year->id }}" @selected((int) old('academic_year_id', $record->academic_year_id ?? 0) === (int) $year->id)>{{ $year->name }}</option>
                @endforeach
            </select>
            @error('academic_year_id')<span class="text-red-600">{{ $message }}</span>@enderror
        </label>
        <label class="block text-sm font-medium text-slate-700">
            Name *
            <input class="input mt-1" type="text" name="name" maxlength="255" value="{{ old('name', $record->name ?? '') }}" required placeholder="e.g. City Route Annual Fee">
            @error('name')<span class="text-red-600">{{ $message }}</span>@enderror
        </label>
        <label class="block text-sm font-medium text-slate-700">
            Code *
            <input class="input mt-1" type="text" name="code" maxlength="50" value="{{ old('code', $record->code ?? '') }}" required placeholder="e.g. TRF-NORTH">
            @error('code')<span class="text-red-600">{{ $message }}</span>@enderror
        </label>
        <label class="block text-sm font-medium text-slate-700">
            Amount *
            <input class="input mt-1" type="number" name="amount" step="0.01" min="0.01" max="9999999999.99" value="{{ old('amount', $record->amount ?? '') }}" required>
            @error('amount')<span class="text-red-600">{{ $message }}</span>@enderror
            <span class="mt-1 block text-xs text-slate-500">Decimal money, at most 2 decimals.</span>
        </label>
        <label class="block text-sm font-medium text-slate-700">
            Route narrowing
            <select class="input mt-1" name="transport_route_id">
                <option value="">All routes</option>
                @foreach($routes as $route)
                    <option value="{{ $route->id }}" @selected((int) old('transport_route_id', $record->transport_route_id ?? 0) === (int) $route->id)>{{ $route->name }} ({{ $route->code }})</option>
                @endforeach
            </select>
            @error('transport_route_id')<span class="text-red-600">{{ $message }}</span>@enderror
        </label>
        <label class="block text-sm font-medium text-slate-700">
            Stop narrowing
            <select class="input mt-1" name="transport_stop_id">
                <option value="">All stops</option>
                @foreach($stops as $stop)
                    <option value="{{ $stop->id }}" @selected((int) old('transport_stop_id', $record->transport_stop_id ?? 0) === (int) $stop->id)>{{ $routes->firstWhere('id', $stop->route_id)?->name ?? '—' }} — {{ $stop->name }}</option>
                @endforeach
            </select>
            @error('transport_stop_id')<span class="text-red-600">{{ $message }}</span>@enderror
            <span class="mt-1 block text-xs text-slate-500">A narrowed stop must belong to the narrowed route.</span>
        </label>
        <label class="block text-sm font-medium text-slate-700">
            Effective from *
            <input class="input mt-1" type="date" name="effective_from" value="{{ old('effective_from', isset($record) ? $record->effective_from?->format('Y-m-d') : now()->format('Y-m-d')) }}" required>
            @error('effective_from')<span class="text-red-600">{{ $message }}</span>@enderror
        </label>
        <label class="block text-sm font-medium text-slate-700">
            Effective until
            <input class="input mt-1" type="date" name="effective_until" value="{{ old('effective_until', isset($record) ? $record->effective_until?->format('Y-m-d') : '') }}">
            @error('effective_until')<span class="text-red-600">{{ $message }}</span>@enderror
        </label>
        <label class="block text-sm font-medium text-slate-700">
            Status *
            <select class="input mt-1" name="status" required>
                @foreach($statuses as $status)
                    <option value="{{ $status }}" @selected(old('status', $record->status ?? 'active') === $status)>{{ ucfirst($status) }}</option>
                @endforeach
            </select>
            @error('status')<span class="text-red-600">{{ $message }}</span>@enderror
        </label>
        <label class="block text-sm font-medium text-slate-700 sm:col-span-2">
            Remarks
            <textarea class="input mt-1" name="remarks" maxlength="2000" rows="2">{{ old('remarks', $record->remarks ?? '') }}</textarea>
            @error('remarks')<span class="text-red-600">{{ $message }}</span>@enderror
        </label>
        <div class="flex gap-2 sm:col-span-2">
            <button class="button" type="submit">Save</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('transport-fee-structures.index') }}">Cancel</a>
        </div>
    </form>
</div>
@endsection
