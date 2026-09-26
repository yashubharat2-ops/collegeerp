@extends('layouts.app')

@section('title', 'Assign Asset')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Assign Asset</h2>
            <p class="panel-subtitle">Hand an individual asset to a student or a staff member. Assignment is custody, not consumption — stock is not changed. An asset with an active assignment must be returned before it can be assigned again.</p>
        </div>
        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('inventory-assignments.index') }}">Back</a>
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

    <form method="POST" action="{{ route('inventory-assignments.store') }}" class="mt-6 grid gap-4 sm:grid-cols-2">
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
            <p class="mt-1 text-xs text-slate-500">Only assets of the active college can be assigned. Consumables are issued through Item Issue / Allocation.</p>
            <p class="mt-1 text-xs text-rose-600">@error('item_id'){{ $message }}@enderror</p>
        </div>

        <div>
            <label class="label" for="assigned_on">Assignment date</label>
            <input class="input" id="assigned_on" name="assigned_on" type="date" value="{{ old('assigned_on', now()->toDateString()) }}" required>
            <p class="mt-1 text-xs text-rose-600">@error('assigned_on'){{ $message }}@enderror</p>
        </div>

        <div>
            <label class="label" for="assigned_to_type">Assignee type</label>
            <select class="input" id="assigned_to_type" name="assigned_to_type" required>
                <option value="student" @selected(old('assigned_to_type', 'student') === 'student')>Student</option>
                <option value="faculty" @selected(old('assigned_to_type') === 'faculty')>Staff</option>
            </select>
            <p class="mt-1 text-xs text-rose-600">@error('assigned_to_type'){{ $message }}@enderror</p>
        </div>

        <div>
            <label class="label" for="assigned_to_id">Assignee</label>
            <select class="input" id="assigned_to_id" name="assigned_to_id" required>
                <option value="">Select an assignee</option>
                <optgroup label="Students">
                    @foreach($students as $student)
                        <option value="{{ $student->id }}" data-type="student" @selected(old('assigned_to_id') == $student->id && old('assigned_to_type', 'student') === 'student')>
                            {{ trim(implode(' ', array_filter([$student->first_name, $student->middle_name, $student->last_name]))) }} ({{ $student->student_number }})
                        </option>
                    @endforeach
                </optgroup>
                <optgroup label="Staff">
                    @foreach($faculties as $faculty)
                        <option value="{{ $faculty->id }}" data-type="faculty" @selected(old('assigned_to_id') == $faculty->id && old('assigned_to_type') === 'faculty')>
                            {{ $faculty->full_name }} ({{ $faculty->employee_code }})
                        </option>
                    @endforeach
                </optgroup>
            </select>
            <p class="mt-1 text-xs text-slate-500">Only people of the active college can be selected.</p>
            <p class="mt-1 text-xs text-rose-600">@error('assigned_to_id'){{ $message }}@enderror</p>
        </div>

        <div>
            <label class="label" for="purpose">Purpose (optional)</label>
            <input class="input" id="purpose" name="purpose" type="text" value="{{ old('purpose') }}" maxlength="255" placeholder="Why the asset was assigned">
            <p class="mt-1 text-xs text-rose-600">@error('purpose'){{ $message }}@enderror</p>
        </div>

        <div class="sm:col-span-2 flex flex-wrap gap-2">
            <button class="button" type="submit">Assign asset</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('inventory-assignments.index') }}">Cancel</a>
        </div>
    </form>
</div>
@endsection
