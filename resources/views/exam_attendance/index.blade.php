@extends('layouts.app')

@section('title', 'Exam Attendance')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Exam Attendance</h2>
            <p class="panel-subtitle">Mark attendance against an exam schedule — eligibility comes from student enrollments, never duplicated data.</p>
        </div>
        @can('create', App\Models\ExamAttendance::class)
            <a class="button" href="{{ route('exam-attendance.create', array_filter(['exam_schedule_id' => $filters['exam_schedule_id']])) }}">+ Mark single attendance</a>
        @endcan
    </div>

    <form method="GET" action="{{ route('exam-attendance.index') }}" class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <select class="input" name="examination_id">
            <option value="">All examinations</option>
            @foreach($examinations as $exam)
                <option value="{{ $exam->id }}" @selected((int) $filters['examination_id'] === $exam->id)>{{ $exam->name }} ({{ $exam->code }})</option>
            @endforeach
        </select>
        <select class="input" name="academic_year_id">
            <option value="">All academic years</option>
            @foreach($academicYears as $year)
                <option value="{{ $year->id }}" @selected((int) $filters['academic_year_id'] === $year->id)>{{ $year->name }}</option>
            @endforeach
        </select>
        <select class="input" name="academic_term_id">
            <option value="">All academic terms</option>
            @foreach($academicTerms as $term)
                <option value="{{ $term->id }}" @selected((int) $filters['academic_term_id'] === $term->id)>{{ $term->name }} ({{ $term->code }})</option>
            @endforeach
        </select>
        <select class="input" name="exam_schedule_id">
            <option value="">Select exam schedule…</option>
            @foreach($schedules as $sched)
                <option value="{{ $sched->id }}" @selected((int) $filters['exam_schedule_id'] === $sched->id)>
                    {{ $sched->exam_date?->format('d M Y') }} · {{ $sched->examination?->name }} · {{ $sched->subject?->name }} ({{ $sched->section?->name }})
                </option>
            @endforeach
        </select>
        <select class="input" name="program_id">
            <option value="">All programs</option>
            @foreach($programs as $prog)
                <option value="{{ $prog->id }}" @selected((int) $filters['program_id'] === $prog->id)>{{ $prog->name }} ({{ $prog->code }})</option>
            @endforeach
        </select>
        <select class="input" name="section_id">
            <option value="">All sections</option>
            @foreach($sections as $sec)
                <option value="{{ $sec->id }}" @selected((int) $filters['section_id'] === $sec->id)>{{ $sec->name }} ({{ $sec->code }})</option>
            @endforeach
        </select>
        <select class="input" name="subject_id">
            <option value="">All subjects</option>
            @foreach($subjects as $sub)
                <option value="{{ $sub->id }}" @selected((int) $filters['subject_id'] === $sub->id)>{{ $sub->name }} ({{ $sub->code }})</option>
            @endforeach
        </select>
        <input class="input" type="date" name="exam_date" value="{{ $filters['exam_date'] }}" placeholder="Exam date">
        <select class="input" name="attendance_status">
            <option value="">All statuses</option>
            @foreach($statuses as $st)
                <option value="{{ $st }}" @selected($filters['attendance_status'] === $st)>{{ ucfirst($st) }}</option>
            @endforeach
        </select>
        <div class="flex gap-2">
            <button class="button" type="submit">Filter</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('exam-attendance.index') }}">Clear</a>
        </div>
    </form>

    @if($schedule)
        {{-- Marking board for the selected exam schedule. --}}
        <div class="mt-8 rounded-2xl border border-indigo-100 bg-indigo-50/50 p-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="text-sm font-semibold text-slate-900">
                        {{ $schedule->examination?->name }} · {{ $schedule->subject?->name }} ({{ $schedule->subject?->code }})
                    </p>
                    <p class="mt-1 text-xs text-slate-500">
                        {{ $schedule->exam_date?->format('M d, Y') }} · {{ substr((string) $schedule->start_time, 0, 5) }}–{{ substr((string) $schedule->end_time, 0, 5) }}
                        · {{ $schedule->program?->code ?? $schedule->program?->name }} / {{ $schedule->section?->name }}
                        @if($schedule->room) · Room {{ $schedule->room }} @endif
                    </p>
                </div>
                @if($locked)
                    <span class="rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-700">Schedule completed — read only</span>
                @endif
            </div>
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

        {{-- Deliberate block form: a single-line inline raw-PHP assignment
             here would be swallowed by Blade's raw-block scanner (it looks
             ahead for the next closing raw-PHP tag, which belongs to the
             status badge block further down) and corrupt the compiled view. --}}
        @php
            $canMark = auth()->user()->can('create', App\Models\ExamAttendance::class) && ! $locked;
        @endphp

        @if($canMark)
            <form method="POST" action="{{ route('exam-attendance.bulk') }}">
                @csrf
                <input type="hidden" name="exam_schedule_id" value="{{ $schedule->id }}">
                <div class="mt-4 flex flex-wrap items-center gap-2">
                    <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">Bulk mark:</span>
                    <button type="button" class="button !bg-emerald-600 hover:!bg-emerald-700" data-mark-all="present">Mark Present</button>
                    <button type="button" class="button !bg-rose-600 hover:!bg-rose-700" data-mark-all="absent">Mark Absent</button>
                    <button type="button" class="button !bg-amber-500 hover:!bg-amber-600" data-mark-all="late">Mark Late</button>
                    <button type="button" class="button !bg-sky-600 hover:!bg-sky-700" data-mark-all="excused">Mark Excused</button>
                </div>
        @endif

        <div class="mt-4 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b text-slate-500">
                        <th class="py-3">Enrollment No.</th>
                        <th>Student</th>
                        <th>Program / Section</th>
                        <th>Subject</th>
                        <th>Exam Date</th>
                        <th>Time</th>
                        <th>Attendance Status</th>
                        <th>Remarks</th>
                        <th class="text-right">Record</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($enrollments as $index => $enrollment)
                        @php
                            $existing = $existingByEnrollment->get($enrollment->id);
                        @endphp
                        <tr class="border-b">
                            <td class="py-3 font-medium">{{ $enrollment->enrollment_number }}</td>
                            <td>
                                {{ $enrollment->student?->fullName() }}
                                <p class="text-xs text-slate-500">{{ $enrollment->student?->student_number }}</p>
                            </td>
                            <td>
                                {{ $enrollment->program?->code ?? $schedule->program?->code ?? '—' }}
                                <span class="text-xs text-slate-500">/ {{ $enrollment->section?->name ?? $schedule->section?->name }}</span>
                            </td>
                            <td>{{ $schedule->subject?->name }}</td>
                            <td>{{ $schedule->exam_date?->format('M d, Y') }}</td>
                            <td>{{ substr((string) $schedule->start_time, 0, 5) }}–{{ substr((string) $schedule->end_time, 0, 5) }}</td>
                            <td>
                                @can('create', App\Models\ExamAttendance::class)
                                    @if(! $locked)
                                        <input type="hidden" name="records[{{ $index }}][student_enrollment_id]" value="{{ $enrollment->id }}">
                                        <select class="input !w-36" name="records[{{ $index }}][attendance_status]" data-attendance-select>
                                            <option value="">— not marked —</option>
                                            @foreach($statuses as $st)
                                                <option value="{{ $st }}" @selected(old("records.$index.attendance_status", $existing?->attendance_status) === $st)>{{ ucfirst($st) }}</option>
                                            @endforeach
                                        </select>
                                    @else
                                        <span class="text-xs text-slate-500">{{ ucfirst($existing?->attendance_status ?? 'not marked') }}</span>
                                    @endif
                                @else
                                    <span class="text-xs text-slate-500">{{ ucfirst($existing?->attendance_status ?? 'not marked') }}</span>
                                @endcan
                            </td>
                            <td>
                                @can('create', App\Models\ExamAttendance::class)
                                    @if(! $locked)
                                        <input class="input !w-44" type="text" name="records[{{ $index }}][remarks]" value="{{ old("records.$index.remarks", $existing?->remarks) }}" placeholder="Optional remarks">
                                    @else
                                        <span class="text-xs text-slate-500">{{ $existing?->remarks ?? '—' }}</span>
                                    @endif
                                @else
                                    <span class="text-xs text-slate-500">{{ $existing?->remarks ?? '—' }}</span>
                                @endcan
                            </td>
                            <td class="text-right">
                                @if($existing)
                                    <div class="flex flex-wrap items-center justify-end gap-2">
                                        @php
                                            $badgeClass = match($existing->attendance_status) {
                                                'present' => 'bg-emerald-100 text-emerald-700',
                                                'absent' => 'bg-rose-100 text-rose-700',
                                                'late' => 'bg-amber-100 text-amber-700',
                                                default => 'bg-sky-100 text-sky-700',
                                            };
                                        @endphp
                                        <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $badgeClass }}">{{ ucfirst($existing->attendance_status) }}</span>
                                        @can('update', $existing)
                                            <a class="text-xs font-semibold text-indigo-600 hover:underline" href="{{ route('exam-attendance.edit', $existing) }}">Edit</a>
                                        @endcan
                                    </div>
                                @else
                                    <span class="text-xs text-slate-400">Not marked yet</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td class="py-6 text-slate-500" colspan="9">No eligible student enrollments found for this exam schedule.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($canMark)
                <div class="mt-4 flex justify-end">
                    <button class="button" type="submit">Save Attendance</button>
                </div>
            </form>
        @endif

        <div class="mt-4 flex flex-wrap items-center justify-between gap-2">
            <p class="text-xs text-slate-500">Showing {{ $enrollments->firstItem() ?? 0 }}–{{ $enrollments->lastItem() ?? 0 }} of {{ $enrollments->total() }} eligible enrollments.</p>
            {{ $enrollments->links() }}
        </div>
    @else
        {{-- Attendance records list with filters. --}}
        <div class="mt-8 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b text-slate-500">
                        <th class="py-3">Enrollment No.</th>
                        <th>Student</th>
                        <th>Examination</th>
                        <th>Subject</th>
                        <th>Exam Date</th>
                        <th>Status</th>
                        <th>Remarks</th>
                        <th>Marked By / At</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($attendances as $attendance)
                        <tr class="border-b">
                            <td class="py-3 font-medium">{{ $attendance->studentEnrollment?->enrollment_number ?? '—' }}</td>
                            <td>{{ $attendance->studentEnrollment?->student?->fullName() ?? '—' }}</td>
                            <td>{{ $attendance->examSchedule?->examination?->name ?? '—' }}</td>
                            <td>{{ $attendance->examSchedule?->subject?->name ?? '—' }}</td>
                            <td>{{ $attendance->examSchedule?->exam_date?->format('M d, Y') ?? '—' }}</td>
                            <td>
                                @php
                                    $badgeClass = match($attendance->attendance_status) {
                                        'present' => 'bg-emerald-100 text-emerald-700',
                                        'absent' => 'bg-rose-100 text-rose-700',
                                        'late' => 'bg-amber-100 text-amber-700',
                                        default => 'bg-sky-100 text-sky-700',
                                    };
                                @endphp
                                <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $badgeClass }}">{{ ucfirst($attendance->attendance_status) }}</span>
                            </td>
                            <td class="max-w-40 truncate" title="{{ $attendance->remarks }}">{{ $attendance->remarks ?? '—' }}</td>
                            <td>
                                <span class="text-xs text-slate-500">{{ $attendance->markedBy?->name ?? '—' }}</span>
                                <p class="text-xs text-slate-400">{{ $attendance->marked_at?->format('d M Y H:i') }}</p>
                            </td>
                            <td class="text-right">
                                <div class="flex flex-wrap items-center justify-end gap-2">
                                    @can('update', $attendance)
                                        <a class="text-xs font-semibold text-indigo-600 hover:underline" href="{{ route('exam-attendance.edit', $attendance) }}">Edit</a>
                                    @endcan
                                    @can('delete', $attendance)
                                        <form method="POST" action="{{ route('exam-attendance.destroy', $attendance) }}" onsubmit="return confirm(@js('Delete this exam attendance record? This can be undone by an administrator.'))">
                                            @csrf
                                            @method('DELETE')
                                            <button class="text-xs font-semibold text-rose-600 hover:underline" type="submit">Delete</button>
                                        </form>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td class="py-6 text-slate-500" colspan="9">No exam attendance records found. Select an exam schedule above to start marking.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4 flex flex-wrap items-center justify-between gap-2">
            <p class="text-xs text-slate-500">Showing {{ $attendances->firstItem() ?? 0 }}–{{ $attendances->lastItem() ?? 0 }} of {{ $attendances->total() }} attendance records.</p>
            {{ $attendances->links() }}
        </div>
    @endif
</div>
@endsection

@push('scripts')
<script>
    // Bulk quick-mark buttons: set every visible status select at once.
    document.querySelectorAll('[data-mark-all]').forEach(function (button) {
        button.addEventListener('click', function () {
            var status = button.getAttribute('data-mark-all');
            document.querySelectorAll('select[data-attendance-select]').forEach(function (select) {
                select.value = status;
            });
        });
    });
</script>
@endpush
