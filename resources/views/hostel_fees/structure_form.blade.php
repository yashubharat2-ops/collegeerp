@extends('layouts.app')

@section('title', ($record ? 'Edit' : 'Create').' Hostel Fee Structure')

@section('content')

<div class="panel">

    <div>
        <h2 class="panel-title">{{ $record ? 'Edit' : 'Create' }} Hostel Fee Structure</h2>
        <p class="panel-subtitle">Hostel fee structure is the pricing master — no money is collected here. Amount is snapshotted onto assignments server-side.</p>
    </div>

    <form class="mt-6 grid gap-4 sm:grid-cols-2" method="POST" action="{{ $record ? route('hostel-fee-structures.update', $record) : route('hostel-fee-structures.store') }}">
        @csrf
        @if($record) @method('PUT') @endif

        <div>
            <label class="label" for="academic_year_id">Academic Year *</label>
            <select class="input" id="academic_year_id" name="academic_year_id" required>
                <option value="">Select academic year</option>

                @foreach($years as $year)
                    <option value="{{ $year->id }}" @selected((string) old('academic_year_id', $record?->academic_year_id ?? '') === (string) $year->id)>{{ $year->name }}</option>
                @endforeach
            </select>

            @error('academic_year_id')
                <p class="text-xs text-rose-600 mt-1">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label class="label" for="name">Name *</label>
            <input class="input" id="name" name="name" type="text" required maxlength="255" value="{{ old('name', $record?->name ?? '') }}">

            @error('name')
                <p class="text-xs text-rose-600 mt-1">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label class="label" for="code">Code *</label>
            <input class="input" id="code" name="code" type="text" required maxlength="50" value="{{ old('code', $record?->code ?? '') }}" placeholder="HOSTEL-2026">

            <p class="text-xs text-slate-500 mt-1">Tenant-aware, upper-cased, unique per college, reserved on archived records.</p>

            @error('code')
                <p class="text-xs text-rose-600 mt-1">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label class="label" for="amount">Amount *</label>
            <input class="input" id="amount" name="amount" type="number" step="0.01" min="0.01" max="9999999999.99" required value="{{ old('amount', $record?->amount ?? '') }}">

            @error('amount')
                <p class="text-xs text-rose-600 mt-1">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label class="label" for="frequency">Frequency</label>
            <select class="input" id="frequency" name="frequency">
                <option value="">Select frequency</option>

                @foreach($frequencies as $freq)
                    <option value="{{ $freq }}" @selected(old('frequency', $record?->frequency ?? '') === $freq)>{{ ucfirst($freq) }}</option>
                @endforeach
            </select>

            @error('frequency')
                <p class="text-xs text-rose-600 mt-1">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label class="label" for="status">Status *</label>
            <select class="input" id="status" name="status" required>

                @foreach($statuses as $statusOption)
                    <option value="{{ $statusOption }}" @selected(old('status', $record?->status ?? 'active') === $statusOption)>{{ ucfirst($statusOption) }}</option>
                @endforeach

            </select>

            @error('status')
                <p class="text-xs text-rose-600 mt-1">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label class="label" for="effective_from">Effective From</label>
            <input class="input" id="effective_from" name="effective_from" type="date" value="{{ old('effective_from', $record?->effective_from?->format('Y-m-d') ?? '') }}">

            @error('effective_from')
                <p class="text-xs text-rose-600 mt-1">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label class="label" for="effective_until">Effective Until</label>
            <input class="input" id="effective_until" name="effective_until" type="date" value="{{ old('effective_until', $record?->effective_until?->format('Y-m-d') ?? '') }}">

            @error('effective_until')
                <p class="text-xs text-rose-600 mt-1">{{ $message }}</p>
            @enderror
        </div>

        <div class="sm:col-span-2">
            <label class="label" for="description">Description</label>
            <textarea class="input" id="description" name="description" rows="3" maxlength="2000">{{ old('description', $record?->description ?? '') }}</textarea>

            @error('description')
                <p class="text-xs text-rose-600 mt-1">{{ $message }}</p>
            @enderror
        </div>

        <div class="sm:col-span-2 flex gap-2">
            <button class="button" type="submit">{{ $record ? 'Update' : 'Create' }} structure</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('hostel-fee-structures.index') }}">Cancel</a>
        </div>

    </form>
</div>

@endsection
