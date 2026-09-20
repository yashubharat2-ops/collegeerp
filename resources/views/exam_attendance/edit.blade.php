@extends('layouts.app')

@section('title', 'Edit Exam Attendance')

@section('content')
<div class="panel max-w-3xl">
    <h2 class="panel-title">Edit Exam Attendance</h2>
    <p class="panel-subtitle">The student and exam schedule are fixed; only the status and remarks can be corrected.</p>

    <div class="mt-6 grid gap-3 rounded-2xl bg-slate-50 p-4 text-sm sm:grid-cols-2">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Student</p>
            <p class="mt-1 font-medium">{{ $attendance->studentEnrollment?->student?->fullName() }} ({{ $attendance->studentEnrollment?->enrollment_number }})</p>
        </div>
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Examination</p>
            <p class="mt-1 font-medium">{{ $attendance->examSchedule?->examination?->name ?? '—' }}</p>
        </div>
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Subject / Section</p>
            <p class="mt-1 font-medium">{{ $attendance->examSchedule?->subject?->name }} / {{ $attendance->examSchedule?->section?->name }}</p>
        </div>
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Exam Date & Time</p>
            <p class="mt-1 font-medium">
                {{ $attendance->examSchedule?->exam_date?->format('M d, Y') }}
                · {{ substr((string) $attendance->examSchedule?->start_time, 0, 5) }}–{{ substr((string) $attendance->examSchedule?->end_time, 0, 5) }}
            </p>
        </div>
    </div>

    <form method="POST" action="{{ route('exam-attendance.update', $attendance) }}" class="mt-6 grid gap-5 md:grid-cols-2">
        @csrf
        @method('PUT')

        <div>
            <label class="text-sm font-semibold" for="attendance_status">Attendance Status</label>
            <select class="input mt-1" id="attendance_status" name="attendance_status" required>
                @foreach($statuses as $st)
                    <option value="{{ $st }}" @selected(old('attendance_status', $attendance->attendance_status) === $st)>{{ ucfirst($st) }}</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-rose-600">@error('attendance_status'){{ $message }}@enderror</p>
        </div>

        <div>
            <label class="text-sm font-semibold" for="remarks">Remarks</label>
            <input class="input mt-1" type="text" id="remarks" name="remarks" value="{{ old('remarks', $attendance->remarks) }}" placeholder="Optional remarks">
            <p class="mt-1 text-xs text-rose-600">@error('remarks'){{ $message }}@enderror</p>
        </div>

        <div class="flex flex-wrap items-center gap-2 md:col-span-2">
            <button class="button" type="submit">Update Attendance</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('exam-attendance.index', ['exam_schedule_id' => $attendance->exam_schedule_id]) }}">Back to board</a>
        </div>
    </form>

    @can('delete', $attendance)
        <form method="POST" action="{{ route('exam-attendance.destroy', $attendance) }}" class="mt-4" onsubmit="return confirm(@js('Delete this exam attendance record? This can be undone by an administrator.'))">
            @csrf
            @method('DELETE')
            <button class="button !bg-rose-600 hover:!bg-rose-700" type="submit">Delete</button>
        </form>
    @endcan
</div>
@endsection
