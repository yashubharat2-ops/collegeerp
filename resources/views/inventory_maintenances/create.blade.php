@extends('layouts.app')

@section('title', 'Record Maintenance')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Record Asset Maintenance</h2>
            <p class="panel-subtitle">A maintenance event for an existing asset. The record stays linked to the asset; stock is not involved. If the work is already completed, pick status "Completed" and fill the completion date.</p>
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

    <form method="POST" action="{{ route('inventory-maintenances.store') }}" class="mt-6 grid gap-4 sm:grid-cols-2">
        @csrf

        <div class="sm:col-span-2">
            <label class="label" for="item_id">Asset</label>
            <select class="input" id="item_id" name="item_id" required>
                <option value="">Select an asset</option>
                @foreach($items as $item)
                    <option value="{{ $item->id }}" @selected($selectedItem === (int) $item->id)>
                        {{ $item->name }} ({{ $item->code }}){{ $item->serial_number ? ' — SN '.$item->serial_number : '' }}{{ $item->status === 'inactive' ? ' — inactive' : '' }}
                    </option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-slate-500">Only assets of the active college can be maintained. This link cannot be changed after saving.</p>
            <p class="mt-1 text-xs text-rose-600">@error('item_id'){{ $message }}@enderror</p>
        </div>

        <div>
            <label class="label" for="title">Title</label>
            <input class="input" id="title" name="title" type="text" value="{{ old('title') }}" maxlength="255" required placeholder="e.g. Annual projector lamp replacement">
            <p class="mt-1 text-xs text-rose-600">@error('title'){{ $message }}@enderror</p>
        </div>

        <div>
            <label class="label" for="maintenance_type">Type</label>
            <select class="input" id="maintenance_type" name="maintenance_type" required>
                @foreach($types as $type)
                    <option value="{{ $type }}" @selected(old('maintenance_type') === $type)>{{ ucfirst(str_replace('_', ' ', $type)) }}</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-rose-600">@error('maintenance_type'){{ $message }}@enderror</p>
        </div>

        <div>
            <label class="label" for="status">Status</label>
            <select class="input" id="status" name="status" required>
                @foreach($statuses as $status)
                    <option value="{{ $status }}" @selected(old('status', 'scheduled') === $status)>{{ ucfirst(str_replace('_', ' ', $status)) }}</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-rose-600">@error('status'){{ $message }}@enderror</p>
        </div>

        <div>
            <label class="label" for="vendor_id">Vendor (optional)</label>
            <select class="input" id="vendor_id" name="vendor_id">
                <option value="">No vendor (internal)</option>
                @foreach($vendors as $vendor)
                    <option value="{{ $vendor->id }}" @selected(old('vendor_id') == $vendor->id)>{{ $vendor->name }} ({{ $vendor->code }})</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-slate-500">Only vendors of the active college can be selected.</p>
            <p class="mt-1 text-xs text-rose-600">@error('vendor_id'){{ $message }}@enderror</p>
        </div>

        <div>
            <label class="label" for="scheduled_on">Scheduled date</label>
            <input class="input" id="scheduled_on" name="scheduled_on" type="date" value="{{ old('scheduled_on') }}">
            <p class="mt-1 text-xs text-rose-600">@error('scheduled_on'){{ $message }}@enderror</p>
        </div>

        <div>
            <label class="label" for="completed_on">Completion date</label>
            <input class="input" id="completed_on" name="completed_on" type="date" value="{{ old('completed_on') }}">
            <p class="mt-1 text-xs text-slate-500">Required when the status is completed.</p>
            <p class="mt-1 text-xs text-rose-600">@error('completed_on'){{ $message }}@enderror</p>
        </div>

        <div>
            <label class="label" for="cost">Cost (optional)</label>
            <input class="input" id="cost" name="cost" type="number" step="0.01" min="0" value="{{ old('cost') }}">
            <p class="mt-1 text-xs text-rose-600">@error('cost'){{ $message }}@enderror</p>
        </div>

        <div>
            <label class="label" for="performed_by">Performed by</label>
            <input class="input" id="performed_by" name="performed_by" type="text" value="{{ old('performed_by') }}" maxlength="255" placeholder="Technician / person in charge">
            <p class="mt-1 text-xs text-rose-600">@error('performed_by'){{ $message }}@enderror</p>
        </div>

        <div class="sm:col-span-2">
            <label class="label" for="description">Description</label>
            <textarea class="input" id="description" name="description" rows="3" maxlength="2000">{{ old('description') }}</textarea>
            <p class="mt-1 text-xs text-rose-600">@error('description'){{ $message }}@enderror</p>
        </div>

        <div class="sm:col-span-2 flex flex-wrap gap-2">
            <button class="button" type="submit">Record maintenance</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('inventory-maintenances.index') }}">Cancel</a>
        </div>
    </form>
</div>
@endsection
