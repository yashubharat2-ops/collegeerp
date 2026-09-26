@extends('layouts.app')

@section('title', 'Record Item Issue')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Record Item Issue / Allocation</h2>
            <p class="panel-subtitle">Issue consumable stock to a student or a staff member. Stock is reduced through the stock ledger (stock-out); stock can never go negative.</p>
        </div>
        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('inventory-issues.index') }}">Back</a>
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

    <form method="POST" action="{{ route('inventory-issues.store') }}" class="mt-6 grid gap-4 sm:grid-cols-2">
        @csrf

        <div class="sm:col-span-2">
            <label class="label" for="item_id">Consumable item</label>
            <select class="input" id="item_id" name="item_id" required>
                <option value="">Select a consumable</option>
                @foreach($items as $item)
                    <option value="{{ $item->id }}" data-quantity="{{ $item->quantity }}" @selected($selectedItem === (int) $item->id)>
                        {{ $item->name }} ({{ $item->code }}) — {{ $item->quantity }} {{ $item->unit }} on hand
                    </option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-slate-500">Only active consumables of the active college can be issued. Assets are assigned through Asset Assignment.</p>
            <p class="mt-1 text-xs text-rose-600">@error('item_id'){{ $message }}@enderror</p>
        </div>

        <div>
            <label class="label" for="quantity">Quantity</label>
            <input class="input" id="quantity" name="quantity" type="number" step="0.01" min="0.01" value="{{ old('quantity') }}" required>
            <p class="mt-1 text-xs text-slate-500">Reduced from the on-hand stock.</p>
            <p class="mt-1 text-xs text-rose-600">@error('quantity'){{ $message }}@enderror</p>
        </div>

        <div>
            <label class="label" for="movement_date">Issue date</label>
            <input class="input" id="movement_date" name="movement_date" type="date" value="{{ old('movement_date', now()->toDateString()) }}" required>
            <p class="mt-1 text-xs text-rose-600">@error('movement_date'){{ $message }}@enderror</p>
        </div>

        <div>
            <label class="label" for="issued_to_type">Recipient type</label>
            <select class="input" id="issued_to_type" name="issued_to_type" required>
                <option value="student" @selected(old('issued_to_type', 'student') === 'student')>Student</option>
                <option value="faculty" @selected(old('issued_to_type') === 'faculty')>Staff</option>
            </select>
            <p class="mt-1 text-xs text-rose-600">@error('issued_to_type'){{ $message }}@enderror</p>
        </div>

        <div>
            <label class="label" for="issued_to_id">Recipient</label>
            <select class="input" id="issued_to_id" name="issued_to_id" required>
                <option value="">Select a recipient</option>
                <optgroup label="Students">
                    @foreach($students as $student)
                        <option value="{{ $student->id }}" data-type="student" @selected(old('issued_to_id') == $student->id && old('issued_to_type', 'student') === 'student')>
                            {{ trim(implode(' ', array_filter([$student->first_name, $student->middle_name, $student->last_name]))) }} ({{ $student->student_number }})
                        </option>
                    @endforeach
                </optgroup>
                <optgroup label="Staff">
                    @foreach($faculties as $faculty)
                        <option value="{{ $faculty->id }}" data-type="faculty" @selected(old('issued_to_id') == $faculty->id && old('issued_to_type') === 'faculty')>
                            {{ $faculty->full_name }} ({{ $faculty->employee_code }})
                        </option>
                    @endforeach
                </optgroup>
            </select>
            <p class="mt-1 text-xs text-slate-500">Only people of the active college can be selected.</p>
            <p class="mt-1 text-xs text-rose-600">@error('issued_to_id'){{ $message }}@enderror</p>
        </div>

        <div>
            <label class="label" for="purpose">Purpose (optional)</label>
            <input class="input" id="purpose" name="purpose" type="text" value="{{ old('purpose') }}" maxlength="255" placeholder="Why the stock was issued">
            <p class="mt-1 text-xs text-rose-600">@error('purpose'){{ $message }}@enderror</p>
        </div>

        <div>
            <label class="label" for="reference">Reference (optional)</label>
            <input class="input" id="reference" name="reference" type="text" value="{{ old('reference') }}" maxlength="100" placeholder="e.g. lab requisition slip">
            <p class="mt-1 text-xs text-slate-500">Stored upper-cased. The auto issue number travels separately as the ledger reference.</p>
            <p class="mt-1 text-xs text-rose-600">@error('reference'){{ $message }}@enderror</p>
        </div>

        <div class="sm:col-span-2">
            <label class="label" for="notes">Notes</label>
            <textarea class="input" id="notes" name="notes" rows="2" maxlength="2000">{{ old('notes') }}</textarea>
            <p class="mt-1 text-xs text-rose-600">@error('notes'){{ $message }}@enderror</p>
        </div>

        <div class="sm:col-span-2 flex flex-wrap gap-2">
            <button class="button" type="submit">Record issue</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('inventory-issues.index') }}">Cancel</a>
        </div>
    </form>
</div>
@endsection
