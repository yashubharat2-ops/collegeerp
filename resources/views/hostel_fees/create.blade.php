@extends('layouts.app')
@section('title', 'Assign Hostel Fee')
@section('content')
<div class="panel">
    <div>
        <h2 class="panel-title">Assign Hostel Fee</h2>
        <p class="panel-subtitle">Assign a hostel fee structure to an existing hostel allocation. Amount is snapshotted server-side from the structure; later edits to the structure never re-price history.</p>
    </div>

    <form class="mt-6 grid gap-4 sm:grid-cols-2" method="POST" action="{{ route('hostel-fees.store') }}">
        @csrf
        <div class="sm:col-span-2">
            <label class="label" for="hostel_allocation_id">Hostel Allocation *</label>
            <select class="input" id="hostel_allocation_id" name="hostel_allocation_id" required>
                <option value="">Select allocation</option>
                @foreach($allocations as $allocation)
                    <option value="{{ $allocation->id }}" @selected((string) old('hostel_allocation_id') === (string) $allocation->id)>
                        {{ $allocation->studentEnrollment?->student?->first_name }} {{ $allocation->studentEnrollment?->student?->last_name }} — {{ $allocation->studentEnrollment?->enrollment_number }} — {{ $allocation->hostel?->name }} Bed {{ $allocation->bed?->bed_number }} ({{ $allocation->academicYear?->name }})
                    </option>
                @endforeach
            </select>
            @error('hostel_allocation_id')<p class="text-xs text-rose-600 mt-1">{{ $message }}</p>@enderror
        </div>

        <div>
            <label class="label" for="hostel_fee_structure_id">Fee Structure *</label>
            <select class="input" id="hostel_fee_structure_id" name="hostel_fee_structure_id" required>
                <option value="">Select structure</option>
                @foreach($structures as $structure)
                    <option value="{{ $structure->id }}" @selected((string) old('hostel_fee_structure_id') === (string) $structure->id)>{{ $structure->name }} ({{ $structure->code }}) — {{ number_format((float) $structure->amount, 2) }}</option>
                @endforeach
            </select>
            @error('hostel_fee_structure_id')<p class="text-xs text-rose-600 mt-1">{{ $message }}</p>@enderror
        </div>

        <div>
            <label class="label" for="effective_from">Effective From *</label>
            <input class="input" id="effective_from" name="effective_from" type="date" required value="{{ old('effective_from', now()->format('Y-m-d')) }}">
            @error('effective_from')<p class="text-xs text-rose-600 mt-1">{{ $message }}</p>@enderror
        </div>

        <div>
            <label class="label" for="effective_until">Effective Until</label>
            <input class="input" id="effective_until" name="effective_until" type="date" value="{{ old('effective_until') }}">
            @error('effective_until')<p class="text-xs text-rose-600 mt-1">{{ $message }}</p>@enderror
        </div>

        <div>
            <label class="label" for="status">Status</label>
            <select class="input" id="status" name="status">
                @foreach($statuses as $statusOption)
                    <option value="{{ $statusOption }}" @selected(old('status', 'active') === $statusOption)>{{ ucfirst($statusOption) }}</option>
                @endforeach
            </select>
            @error('status')<p class="text-xs text-rose-600 mt-1">{{ $message }}</p>@enderror
        </div>

        <div class="sm:col-span-2">
            <label class="label" for="remarks">Remarks</label>
            <textarea class="input" id="remarks" name="remarks" rows="2" maxlength="2000">{{ old('remarks') }}</textarea>
            @error('remarks')<p class="text-xs text-rose-600 mt-1">{{ $message }}</p>@enderror
        </div>

        <div class="sm:col-span-2 flex gap-2">
            <button class="button" type="submit">Assign fee</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('hostel-fees.index') }}">Cancel</a>
        </div>
    </form>
</div>
@endsection
