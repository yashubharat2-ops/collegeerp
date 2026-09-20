@extends('layouts.app')

@section('title', 'Mark Exam Attendance')

@section('content')
<div class="panel max-w-3xl">
    <h2 class="panel-title">Mark Exam Attendance</h2>
    <p class="panel-subtitle">Single marking — use the index board for bulk marking of a whole schedule.</p>

    <form method="POST" action="{{ route('exam-attendance.store') }}" class="mt-6 grid gap-5 md:grid-cols-2">
        @csrf

        <div class="md:col-span-2">
            <label class="text-sm font-semibold" for="exam_schedule_id">Exam Schedule</label>
            <select class="input mt-1" id="exam_schedule_id" name="exam_schedule_id" required data-schedule-select>
                <option value="">Select exam schedule…</option>
                @foreach($schedules as $sched)
                    <option value="{{ $sched->id }}" @selected((int) old('exam_schedule_id', $selectedScheduleId ?? 0) === $sched->id)>
                        {{ $sched->exam_date?->format('d M Y') }} · {{ $sched->examination?->name }} · {{ $sched->subject?->name }} ({{ $sched->section?->name }})
                    </option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-rose-600">@error('exam_schedule_id'){{ $message }}@enderror</p>
        </div>

        <div>
            <label class="text-sm font-semibold" for="student_enrollment_id">Student Enrollment</label>
            <select class="input mt-1" id="student_enrollment_id" name="student_enrollment_id" required>
                <option value="">{{ $schedule ? 'Select eligible student…' : 'Select a schedule first' }}</option>
                @foreach($enrollments as $enrollment)
                    <option value="{{ $enrollment->id }}" @selected((int) old('student_enrollment_id') === $enrollment->id)>
                        {{ $enrollment->enrollment_number }} — {{ $enrollment->student?->fullName() }}
                    </option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-slate-500">Only academically eligible enrollments are listed.</p>
            <p class="mt-1 text-xs text-rose-600">@error('student_enrollment_id'){{ $message }}@enderror</p>
        </div>

        <div>
            <label class="text-sm font-semibold" for="attendance_status">Attendance Status</label>
            <select class="input mt-1" id="attendance_status" name="attendance_status" required>
                <option value="">Select status…</option>
                @foreach($statuses as $st)
                    <option value="{{ $st }}" @selected(old('attendance_status') === $st)>{{ ucfirst($st) }}</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-rose-600">@error('attendance_status'){{ $message }}@enderror</p>
        </div>

        <div class="md:col-span-2">
            <label class="text-sm font-semibold" for="remarks">Remarks</label>
            <textarea class="input mt-1" id="remarks" name="remarks" rows="2" placeholder="Optional remarks">{{ old('remarks') }}</textarea>
            <p class="mt-1 text-xs text-rose-600">@error('remarks'){{ $message }}@enderror</p>
        </div>

        <div class="flex gap-2 md:col-span-2">
            <button class="button" type="submit">Save Attendance</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('exam-attendance.index') }}">Cancel</a>
        </div>
    </form>
</div>
@endsection

@push('scripts')
<script>
    // Refresh the eligible-enrollment list when the schedule changes.
    var scheduleSelect = document.querySelector('[data-schedule-select]');
    if (scheduleSelect) {
        scheduleSelect.addEventListener('change', function () {
            var url = @json(route('exam-attendance.create'));
            window.location.href = url + '?exam_schedule_id=' + encodeURIComponent(scheduleSelect.value);
        });
    }
</script>
@endpush
