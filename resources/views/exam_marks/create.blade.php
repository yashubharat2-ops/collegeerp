@extends('layouts.app')

@section('title', 'Enter Marks')

@section('content')
<div class="panel max-w-3xl">
    <h2 class="panel-title">Enter Marks</h2>
    <p class="panel-subtitle">Single entry — use the index grid for bulk saving of a whole schedule.</p>

    <form method="POST" action="{{ route('exam-marks.store') }}" class="mt-6 grid gap-5 md:grid-cols-2">
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

        <div class="md:col-span-2">
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
            <label class="text-sm font-semibold" for="max_marks">Max Marks</label>
            <input class="input mt-1" type="number" step="0.01" min="0" id="max_marks" name="max_marks"
                value="{{ old('max_marks', $schedule?->max_marks) }}" required>
            <p class="mt-1 text-xs text-rose-600">@error('max_marks'){{ $message }}@enderror</p>
        </div>

        <div>
            <label class="text-sm font-semibold" for="passing_marks">Passing Marks</label>
            <input class="input mt-1" type="number" step="0.01" min="0" id="passing_marks" name="passing_marks"
                value="{{ old('passing_marks', $schedule?->passing_marks) }}" required>
            <p class="mt-1 text-xs text-rose-600">@error('passing_marks'){{ $message }}@enderror</p>
        </div>

        <div>
            <label class="text-sm font-semibold" for="obtained_marks">Obtained Marks</label>
            <input class="input mt-1" type="number" step="0.01" min="0" id="obtained_marks" name="obtained_marks"
                value="{{ old('obtained_marks') }}" placeholder="Leave empty for absent / withheld">
            <p class="mt-1 text-xs text-rose-600">@error('obtained_marks'){{ $message }}@enderror</p>
        </div>

        <div>
            <label class="text-sm font-semibold" for="status">Status</label>
            <select class="input mt-1" id="status" name="status" required>
                @foreach($statuses as $st)
                    <option value="{{ $st }}" @selected(old('status', 'entered') === $st)>{{ ucfirst($st) }}</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-slate-500">Absent / withheld entries keep no obtained marks.</p>
            <p class="mt-1 text-xs text-rose-600">@error('status'){{ $message }}@enderror</p>
        </div>

        <div class="md:col-span-2">
            <label class="text-sm font-semibold" for="remarks">Remarks</label>
            <textarea class="input mt-1" id="remarks" name="remarks" rows="2" placeholder="Optional remarks">{{ old('remarks') }}</textarea>
            <p class="mt-1 text-xs text-rose-600">@error('remarks'){{ $message }}@enderror</p>
        </div>

        <div class="flex gap-2 md:col-span-2">
            <button class="button" type="submit">Save Marks</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('exam-marks.index') }}">Cancel</a>
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
            var url = @json(route('exam-marks.create'));
            window.location.href = url + '?exam_schedule_id=' + encodeURIComponent(scheduleSelect.value);
        });
    }
</script>
@endpush
