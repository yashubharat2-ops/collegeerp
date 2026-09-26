@extends('layouts.app')

@section('title', 'Edit Maintenance')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Edit Maintenance — {{ $maintenance->title }}</h2>
            <p class="panel-subtitle">Asset: {{ $maintenance->item?->name }} ({{ $maintenance->item?->code }}{{ $maintenance->item?->serial_number ? ' / '.$maintenance->item->serial_number : '' }}). The asset link is fixed at creation; the record is corrected, never deleted.</p>
        </div>
        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('inventory-maintenances.index') }}">Back</a>
    </div>

    @if($errors->any())
        <div class="alert-error mt-4">
            <ul class="list-inside list-disc space-y-1">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('inventory-maintenances.update', $maintenance) }}" class="mt-6 grid gap-4 sm:grid-cols-2">
        @csrf
        @method('PUT')

        <div>
            <label class="label" for="title">Title</label>
            <input class="input" id="title" name="title" type="text" value="{{ old('title', $maintenance->title) }}" maxlength="255" required>
            <p class="mt-1 text-xs text-rose-600">@error('title'){{ $message }}@enderror</p>
        </div>

        <div>
            <label class="label" for="maintenance_type">Type</label>
            <select class="input" id="maintenance_type" name="maintenance_type" required>
                @foreach($types as $type)
                    <option value="{{ $type }}" @selected(old('maintenance_type', $maintenance->maintenance_type) === $type)>{{ ucfirst(str_replace('_', ' ', $type)) }}</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-rose-600">@error('maintenance_type'){{ $message }}@enderror</p>
        </div>

        <div>
            <label class="label" for="status">Status</label>
            <select class="input" id="status" name="status" required>
                @foreach($statuses as $status)
                    <option value="{{ $status }}" @selected(old('status', $maintenance->status) === $status)>{{ ucfirst(str_replace('_', ' ', $status)) }}</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-rose-600">@error('status'){{ $message }}@enderror</p>
        </div>

        <div>
            <label class="label" for="vendor_id">Vendor (optional)</label>
            <select class="input" id="vendor_id" name="vendor_id">
                <option value="">No vendor (internal)</option>
                @foreach($vendors as $vendor)
                    <option value="{{ $vendor->id }}" @selected(old('vendor_id', (string) $maintenance->vendor_id) === (string) $vendor->id)>{{ $vendor->name }} ({{ $vendor->code }})</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-rose-600">@error('vendor_id'){{ $message }}@enderror</p>
        </div>

        <div>
            <label class="label" for="scheduled_on">Scheduled date</label>
            <input class="input" id="scheduled_on" name="scheduled_on" type="date" value="{{ old('scheduled_on', $maintenance->scheduled_on?->toDateString()) }}">
            <p class="mt-1 text-xs text-rose-600">@error('scheduled_on'){{ $message }}@enderror</p>
        </div>

        <div>
            <label class="label" for="completed_on">Completion date</label>
            <input class="input" id="completed_on" name="completed_on" type="date" value="{{ old('completed_on', $maintenance->completed_on?->toDateString()) }}">
            <p class="mt-1 text-xs text-slate-500">Required when the status is completed.</p>
            <p class="mt-1 text-xs text-rose-600">@error('completed_on'){{ $message }}@enderror</p>
        </div>

        <div>
            <label class="label" for="cost">Cost (optional)</label>
            <input class="input" id="cost" name="cost" type="number" step="0.01" min="0" value="{{ old('cost', $maintenance->cost) }}">
            <p class="mt-1 text-xs text-rose-600">@error('cost'){{ $message }}@enderror</p>
        </div>

        <div>
            <label class="label" for="performed_by">Performed by</label>
            <input class="input" id="performed_by" name="performed_by" type="text" value="{{ old('performed_by', $maintenance->performed_by) }}" maxlength="255">
            <p class="mt-1 text-xs text-rose-600">@error('performed_by'){{ $message }}@enderror</p>
        </div>

        <div class="sm:col-span-2">
            <label class="label" for="description">Description</label>
            <textarea class="input" id="description" name="description" rows="3" maxlength="2000">{{ old('description', $maintenance->description) }}</textarea>
            <p class="mt-1 text-xs text-rose-600">@error('description'){{ $message }}@enderror</p>
        </div>

        <div class="sm:col-span-2 flex flex-wrap gap-2">
            <button class="button" type="submit">Save changes</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('inventory-maintenances.index') }}">Cancel</a>
        </div>
    </form>
</div>
@endsection
