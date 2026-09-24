@extends('layouts.app')
@section('title', 'Edit Hostel Fee Assignment')
@section('content')
<div class="panel">
    <div>
        <h2 class="panel-title">Edit Hostel Fee Assignment</h2>
        <p class="panel-subtitle">Allocation and structure are immutable after creation — create a new assignment to re-price. Only period, status and remarks are editable here. Amount snapshot is preserved.</p>
    </div>

    <div class="mt-4 rounded-lg bg-slate-50 p-4 text-sm">
        <p class="font-semibold">{{ $feeAssignment->hostelAllocation?->studentEnrollment?->student?->first_name }} {{ $feeAssignment->hostelAllocation?->studentEnrollment?->student?->last_name }} — {{ $feeAssignment->feeStructure?->name }}</p>
        <p>Assigned amount: {{ number_format((float) $feeAssignment->assigned_amount, 2) }} (snapshot)</p>
        @if($ledger)
            <p>Outstanding: {{ number_format((float) $ledger['outstanding'], 2) }} — Collected: {{ number_format((float) $ledger['net_collected'], 2) }}</p>
        @endif
    </div>

    <form class="mt-6 grid gap-4 sm:grid-cols-2" method="POST" action="{{ route('hostel-fees.update', $feeAssignment) }}">
        @csrf
        @method('PUT')
        <div>
            <label class="label" for="effective_from">Effective From *</label>
            <input class="input" id="effective_from" name="effective_from" type="date" required value="{{ old('effective_from', $feeAssignment->effective_from?->format('Y-m-d')) }}">
            @error('effective_from')<p class="text-xs text-rose-600 mt-1">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="label" for="effective_until">Effective Until</label>
            <input class="input" id="effective_until" name="effective_until" type="date" value="{{ old('effective_until', $feeAssignment->effective_until?->format('Y-m-d')) }}">
            @error('effective_until')<p class="text-xs text-rose-600 mt-1">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="label" for="status">Status</label>
            <select class="input" id="status" name="status">
                @foreach($statuses as $statusOption)
                    <option value="{{ $statusOption }}" @selected(old('status', $feeAssignment->status) === $statusOption)>{{ ucfirst($statusOption) }}</option>
                @endforeach
            </select>
            @error('status')<p class="text-xs text-rose-600 mt-1">{{ $message }}</p>@enderror
        </div>
        <div class="sm:col-span-2">
            <label class="label" for="remarks">Remarks</label>
            <textarea class="input" id="remarks" name="remarks" rows="2" maxlength="2000">{{ old('remarks', $feeAssignment->remarks) }}</textarea>
            @error('remarks')<p class="text-xs text-rose-600 mt-1">{{ $message }}</p>@enderror
        </div>
        <div class="sm:col-span-2 flex gap-2">
            <button class="button" type="submit">Update</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('hostel-fees.index') }}">Cancel</a>
        </div>
    </form>
</div>
@endsection
