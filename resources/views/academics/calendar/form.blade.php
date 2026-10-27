@extends('layouts.app')

@section('title', isset($item) ? 'Edit Academic Calendar Event' : 'Add Academic Calendar Event')

@section('content')
@php
    /*
     * ONE form for create and edit, driven by the same field list the controller
     * validates (calendarRules()), so the two can never drift.
     *
     * The academic term stays OPTIONAL: a year-wide event (holiday, convocation,
     * admission window) belongs to an academic year but to no single term. The
     * "All terms" option below was previously unusable — the rule list read
     * `['nullable', ...refRules()]`, which keeps `required` in force, so saving a
     * year-wide event always failed validation.
     *
     * Class names are the Academic module's theme contract (`card`, `input`,
     * `btn-primary`) and the save control is a real <button>.
     */
    $editing = isset($item);
    // Arrow functions capture by value at definition time, so an undefined
    // $item would raise a warning on the create screen. Normalise it to null.
    $item = $item ?? null;
    $action = $editing ? route('academic-calendar.update', $item) : route('academic-calendar.store');
    $dateValue = static fn ($value): string => $value ? $value->format('Y-m-d') : '';
@endphp

<div class="mb-5 flex flex-wrap items-start justify-between gap-3">
    <div>
        <h2 class="panel-title">{{ $editing ? 'Edit calendar event' : 'Add calendar event' }}</h2>
        <p class="panel-subtitle">Events belong to an academic year; a term is optional, for year-wide events.</p>
    </div>
    <a class="button !bg-slate-200 !text-slate-700" href="{{ route('academic-calendar.index') }}">Back to calendar</a>
</div>

<form method="POST" action="{{ $action }}" class="card grid gap-4 md:grid-cols-2">
    @csrf
    @if ($editing)
        @method('PUT')
    @endif

    <label class="block">
        <span class="label">Title</span>
        <input class="input" name="title" maxlength="255" required value="{{ old('title', $item->title ?? '') }}">
        @error('title')<span class="mt-1 block text-xs text-rose-600">{{ $message }}</span>@enderror
    </label>

    <label class="block">
        <span class="label">Event type</span>
        <input class="input" name="event_type" maxlength="50" required placeholder="holiday, examination, orientation"
               value="{{ old('event_type', $item->event_type ?? '') }}">
        @error('event_type')<span class="mt-1 block text-xs text-rose-600">{{ $message }}</span>@enderror
    </label>

    <label class="block">
        <span class="label">Start date</span>
        <input class="input" type="date" name="start_date" required value="{{ old('start_date', $dateValue($item->start_date ?? null)) }}">
        @error('start_date')<span class="mt-1 block text-xs text-rose-600">{{ $message }}</span>@enderror
    </label>

    <label class="block">
        <span class="label">End date</span>
        <input class="input" type="date" name="end_date" required value="{{ old('end_date', $dateValue($item->end_date ?? null)) }}">
        @error('end_date')<span class="mt-1 block text-xs text-rose-600">{{ $message }}</span>@enderror
    </label>

    <label class="block">
        <span class="label">Academic year</span>
        <select class="input" name="academic_year_id" required>
            <option value="">— Select academic year —</option>
            @foreach ($years ?? collect() as $year)
                <option value="{{ $year->id }}" @selected((int) old('academic_year_id', $item->academic_year_id ?? 0) === (int) $year->id)>{{ $year->name }}</option>
            @endforeach
        </select>
        @error('academic_year_id')<span class="mt-1 block text-xs text-rose-600">{{ $message }}</span>@enderror
    </label>

    <label class="block">
        <span class="label">Academic term (optional)</span>
        <select class="input" name="academic_term_id">
            <option value="">All terms — year-wide event</option>
            @foreach ($terms ?? collect() as $term)
                <option value="{{ $term->id }}" @selected((int) old('academic_term_id', $item->academic_term_id ?? 0) === (int) $term->id)>{{ $term->name }}</option>
            @endforeach
        </select>
        <span class="mt-1 block text-xs text-slate-500">A term must belong to the selected academic year.</span>
        @error('academic_term_id')<span class="mt-1 block text-xs text-rose-600">{{ $message }}</span>@enderror
    </label>

    <label class="block">
        <span class="label">Status</span>
        <select class="input" name="status" required>
            @foreach (['published', 'draft', 'cancelled'] as $status)
                <option value="{{ $status }}" @selected(old('status', $item->status ?? 'published') === $status)>{{ ucfirst($status) }}</option>
            @endforeach
        </select>
        @error('status')<span class="mt-1 block text-xs text-rose-600">{{ $message }}</span>@enderror
    </label>

    <label class="block md:col-span-2">
        <span class="label">Description (optional)</span>
        <textarea class="input" name="description" rows="3">{{ old('description', $item->description ?? '') }}</textarea>
        @error('description')<span class="mt-1 block text-xs text-rose-600">{{ $message }}</span>@enderror
    </label>

    <div class="md:col-span-2">
        <button class="btn-primary w-fit" type="submit">{{ $editing ? 'Save changes' : 'Save event' }}</button>
    </div>
</form>
@endsection
