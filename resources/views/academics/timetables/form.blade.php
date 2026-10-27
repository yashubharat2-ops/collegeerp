@extends('layouts.app')

@section('title', isset($item) ? 'Edit Timetable Entry' : 'Add Timetable Entry')

@section('content')
@php
    /*
     * ONE form for create and edit.
     *
     * The entry's identity — academic year, term, program, section, subject and
     * faculty — is fixed once it exists: a different pairing is a different
     * period, and the conflict check in AcademicsController is built around that
     * pairing. So the edit view shows those six values read-only and posts only
     * the movable fields, which is exactly the set updateTimetable() validates.
     * (Before this view existed the list had no actions column at all, so
     * update/destroy were registered endpoints no operator could ever reach.)
     *
     * The class names are the Academic module's theme contract (`card`, `input`,
     * `btn-primary`), and the save control stays a real <button>: Tailwind's
     * preflight resets an unclassed control into a plain-text look.
     */
    $editing = isset($item);
    // Arrow functions capture by value at definition time, so an undefined
    // $item would raise a warning on the create screen. Normalise it to null.
    $item = $item ?? null;
    $action = $editing ? route('academic-timetables.update', $item) : route('academic-timetables.store');
    $timeValue = static fn ($value): string => $value ? substr((string) $value, 0, 5) : '';

    // field, label, option list, and how an option is labelled
    $contextFields = [
        ['academic_year_id', 'Academic year', $years ?? collect()],
        ['academic_term_id', 'Academic term', $terms ?? collect()],
        ['program_id', 'Program', $programs ?? collect()],
        ['section_id', 'Section', $sections ?? collect()],
        ['subject_id', 'Subject', $subjects ?? collect()],
        ['faculty_id', 'Faculty', $faculties ?? collect()],
    ];
    $selected = static fn (string $field) => (int) old($field, $item->{$field} ?? 0);
    $days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

    // Read-only recap of the slot, shown only when editing. Each relation is
    // named explicitly instead of derived from the field name, so a relation
    // rename is a visible error here rather than a silent "—".
    $contextSummary = $editing ? [
        ['Academic year', $item->academicYear?->name ?? '—'],
        ['Academic term', $item->academicTerm?->name ?? '—'],
        ['Program', $item->program?->name ?? '—'],
        ['Section', $item->section?->name ?? '—'],
        ['Subject', $item->subject?->name ?? '—'],
        ['Faculty', $item->faculty?->full_name ?? '—'],
    ] : [];
@endphp

<div class="mb-5 flex flex-wrap items-start justify-between gap-3">
    <div>
        <h2 class="panel-title">{{ $editing ? 'Edit timetable entry' : 'Add timetable entry' }}</h2>
        <p class="panel-subtitle">
            Conflict checking covers faculty, section and room within the same academic term.
        </p>
    </div>
    <a class="button !bg-slate-200 !text-slate-700" href="{{ route('academic-timetables.index') }}">Back to timetable</a>
</div>

@if ($editing)
    <div class="card mb-4">
        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Slot this entry belongs to</p>
        <div class="mt-3 grid gap-3 text-sm sm:grid-cols-3">
            @foreach ($contextSummary as [$label, $value])
                <p><span class="block text-xs text-slate-500">{{ $label }}</span>{{ $value }}</p>
            @endforeach
        </div>
        <p class="mt-3 text-xs text-slate-500">
            Year, term, program, section, subject and faculty are the entry's identity and cannot be changed here.
            Delete the entry and add a new one if the slot itself is wrong.
        </p>
    </div>
@endif

<form method="POST" action="{{ $action }}" class="card grid gap-4 md:grid-cols-2">
    @csrf
    @if ($editing)
        @method('PUT')
    @endif

    @unless ($editing)
        @foreach ($contextFields as [$field, $label, $options])
            <label class="block">
                <span class="label">{{ $label }}</span>
                <select class="input" name="{{ $field }}" required>
                    <option value="">— Select {{ Str::lower($label) }} —</option>
                    @foreach ($options as $option)
                        <option value="{{ $option->id }}" @selected($selected($field) === (int) $option->id)>{{ $option->name ?? $option->full_name }}</option>
                    @endforeach
                </select>
                @error($field)<span class="mt-1 block text-xs text-rose-600">{{ $message }}</span>@enderror
            </label>
        @endforeach
    @endunless

    <label class="block">
        <span class="label">Day</span>
        <select class="input" name="day_of_week" required>
            @foreach ($days as $index => $day)
                <option value="{{ $index + 1 }}" @selected((int) old('day_of_week', $item->day_of_week ?? 1) === $index + 1)>{{ $day }}</option>
            @endforeach
        </select>
        @error('day_of_week')<span class="mt-1 block text-xs text-rose-600">{{ $message }}</span>@enderror
    </label>

    <label class="block">
        <span class="label">Period (optional)</span>
        <input class="input" type="number" name="period" min="1" value="{{ old('period', $item->period ?? '') }}">
        @error('period')<span class="mt-1 block text-xs text-rose-600">{{ $message }}</span>@enderror
    </label>

    <label class="block">
        <span class="label">Start time</span>
        <input class="input" type="time" name="start_time" required value="{{ old('start_time', $timeValue($item->start_time ?? null)) }}">
        @error('start_time')<span class="mt-1 block text-xs text-rose-600">{{ $message }}</span>@enderror
    </label>

    <label class="block">
        <span class="label">End time</span>
        <input class="input" type="time" name="end_time" required value="{{ old('end_time', $timeValue($item->end_time ?? null)) }}">
        @error('end_time')<span class="mt-1 block text-xs text-rose-600">{{ $message }}</span>@enderror
    </label>

    <label class="block">
        <span class="label">Room / classroom (optional)</span>
        <input class="input" name="room" maxlength="100" value="{{ old('room', $item->room ?? '') }}">
        @error('room')<span class="mt-1 block text-xs text-rose-600">{{ $message }}</span>@enderror
    </label>

    <label class="block">
        <span class="label">Status</span>
        <select class="input" name="status" required>
            @foreach (['active', 'inactive'] as $status)
                <option value="{{ $status }}" @selected(old('status', $item->status ?? 'active') === $status)>{{ ucfirst($status) }}</option>
            @endforeach
        </select>
        @error('status')<span class="mt-1 block text-xs text-rose-600">{{ $message }}</span>@enderror
    </label>

    <label class="block md:col-span-2">
        <span class="label">Remarks (optional)</span>
        <textarea class="input" name="remarks" rows="2">{{ old('remarks', $item->remarks ?? '') }}</textarea>
        @error('remarks')<span class="mt-1 block text-xs text-rose-600">{{ $message }}</span>@enderror
    </label>

    <div class="md:col-span-2">
        <button class="btn-primary w-fit" type="submit">{{ $editing ? 'Save changes' : 'Save timetable' }}</button>
    </div>
</form>
@endsection
