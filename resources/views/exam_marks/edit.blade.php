@extends('layouts.app')

@section('title', 'Edit Marks')

@section('content')
<div class="panel max-w-3xl">
    <h2 class="panel-title">Edit Marks</h2>
    <p class="panel-subtitle">The student and exam schedule are fixed; only the marks, status and remarks can be corrected.</p>

    <div class="mt-6 grid gap-3 rounded-2xl bg-slate-50 p-4 text-sm sm:grid-cols-2">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Student</p>
            <p class="mt-1 font-medium">{{ $mark->studentEnrollment?->student?->fullName() }} ({{ $mark->studentEnrollment?->enrollment_number }})</p>
        </div>
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Examination</p>
            <p class="mt-1 font-medium">{{ $mark->examSchedule?->examination?->name ?? '—' }}</p>
        </div>
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Subject / Section</p>
            <p class="mt-1 font-medium">{{ $mark->examSchedule?->subject?->name }} / {{ $mark->examSchedule?->section?->name }}</p>
        </div>
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Exam Date & Time</p>
            <p class="mt-1 font-medium">
                {{ $mark->examSchedule?->exam_date?->format('M d, Y') }}
                · {{ substr((string) $mark->examSchedule?->start_time, 0, 5) }}–{{ substr((string) $mark->examSchedule?->end_time, 0, 5) }}
            </p>
        </div>
    </div>

    <form method="POST" action="{{ route('exam-marks.update', $mark) }}" class="mt-6 grid gap-5 md:grid-cols-2">
        @csrf
        @method('PUT')

        <div>
            <label class="text-sm font-semibold" for="max_marks">Max Marks</label>
            <input class="input mt-1" type="number" step="0.01" min="0" id="max_marks" name="max_marks"
                value="{{ old('max_marks', $mark->max_marks) }}" required>
            <p class="mt-1 text-xs text-rose-600">@error('max_marks'){{ $message }}@enderror</p>
        </div>

        <div>
            <label class="text-sm font-semibold" for="passing_marks">Passing Marks</label>
            <input class="input mt-1" type="number" step="0.01" min="0" id="passing_marks" name="passing_marks"
                value="{{ old('passing_marks', $mark->passing_marks) }}" required>
            <p class="mt-1 text-xs text-rose-600">@error('passing_marks'){{ $message }}@enderror</p>
        </div>

        <div>
            <label class="text-sm font-semibold" for="obtained_marks">Obtained Marks</label>
            <input class="input mt-1" type="number" step="0.01" min="0" id="obtained_marks" name="obtained_marks"
                value="{{ old('obtained_marks', $mark->obtained_marks) }}" placeholder="Leave empty for absent / withheld">
            <p class="mt-1 text-xs text-rose-600">@error('obtained_marks'){{ $message }}@enderror</p>
        </div>

        <div>
            <label class="text-sm font-semibold" for="status">Status</label>
            <select class="input mt-1" id="status" name="status" required>
                @foreach($statuses as $st)
                    <option value="{{ $st }}" @selected(old('status', $mark->status) === $st)>{{ ucfirst($st) }}</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-slate-500">Absent / withheld entries keep no obtained marks.</p>
            <p class="mt-1 text-xs text-rose-600">@error('status'){{ $message }}@enderror</p>
        </div>

        <div class="md:col-span-2">
            <label class="text-sm font-semibold" for="remarks">Remarks</label>
            <input class="input mt-1" type="text" id="remarks" name="remarks" value="{{ old('remarks', $mark->remarks) }}" placeholder="Optional remarks">
            <p class="mt-1 text-xs text-rose-600">@error('remarks'){{ $message }}@enderror</p>
        </div>

        <div class="flex flex-wrap items-center gap-2 md:col-span-2">
            <button class="button" type="submit">Update Marks</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('exam-marks.index', ['exam_schedule_id' => $mark->exam_schedule_id]) }}">Back to grid</a>
        </div>
    </form>

    @can('delete', $mark)
        <form method="POST" action="{{ route('exam-marks.destroy', $mark) }}" class="mt-4" onsubmit="return confirm(@js('Delete this marks entry? This can be undone by an administrator.'))">
            @csrf
            @method('DELETE')
            <button class="button !bg-rose-600 hover:!bg-rose-700" type="submit">Delete</button>
        </form>
    @endcan
</div>
@endsection
