@extends('layouts.app')

@section('title', 'Marks Entry')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Marks Entry</h2>
            <p class="panel-subtitle">Capture marks against an exam schedule — data entry only; result processing belongs to later phases.</p>
        </div>
        @can('create', App\Models\ExamMark::class)
            <a class="button" href="{{ route('exam-marks.create', array_filter(['exam_schedule_id' => $filters['exam_schedule_id']])) }}">+ Enter single marks</a>
        @endcan
    </div>

    <form method="GET" action="{{ route('exam-marks.index') }}" class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
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
        <select class="input" name="status">
            <option value="">All statuses</option>
            @foreach($statuses as $st)
                <option value="{{ $st }}" @selected($filters['status'] === $st)>{{ ucfirst($st) }}</option>
            @endforeach
        </select>
        <div class="flex gap-2">
            <button class="button" type="submit">Filter</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('exam-marks.index') }}">Clear</a>
        </div>
    </form>

    @if($schedule)
        {{-- Entry grid for the selected exam schedule. --}}
        <div class="mt-8 rounded-2xl border border-indigo-100 bg-indigo-50/50 p-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="text-sm font-semibold text-slate-900">
                        {{ $schedule->examination?->name }} · {{ $schedule->subject?->name }} ({{ $schedule->subject?->code }})
                    </p>
                    <p class="mt-1 text-xs text-slate-500">
                        {{ $schedule->exam_date?->format('M d, Y') }} · {{ substr((string) $schedule->start_time, 0, 5) }}–{{ substr((string) $schedule->end_time, 0, 5) }}
                        · {{ $schedule->program?->code ?? $schedule->program?->name }} / {{ $schedule->section?->name }}
                        · Default marks {{ (float) $schedule->max_marks }} / passing {{ (float) $schedule->passing_marks }}
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
            $canEnter = auth()->user()->can('create', App\Models\ExamMark::class) && ! $locked;
        @endphp

        @if($canEnter)
            <form method="POST" action="{{ route('exam-marks.bulk') }}">
                @csrf
                <input type="hidden" name="exam_schedule_id" value="{{ $schedule->id }}">
        @endif

        <div class="mt-4 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b text-slate-500">
                        <th class="py-3">Enrollment No.</th>
                        <th>Student</th>
                        <th>Section</th>
                        <th>Max Marks</th>
                        <th>Passing Marks</th>
                        <th>Obtained Marks</th>
                        <th>Status</th>
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
                            <td class="py-3 font-medium">
                                {{ $enrollment->enrollment_number }}
                                @if($canEnter)
                                    <input type="hidden" name="records[{{ $index }}][student_enrollment_id]" value="{{ $enrollment->id }}">
                                @endif
                            </td>
                            <td>
                                {{ $enrollment->student?->fullName() }}
                                <p class="text-xs text-slate-500">{{ $enrollment->student?->student_number }}</p>
                            </td>
                            <td>{{ $enrollment->section?->name ?? $schedule->section?->name }}</td>
                            @if($canEnter)
                                <td>
                                    <input class="input !w-24" type="number" step="0.01" min="0" name="records[{{ $index }}][max_marks]"
                                        value="{{ old("records.$index.max_marks", $existing?->max_marks ?? $schedule->max_marks) }}">
                                </td>
                                <td>
                                    <input class="input !w-24" type="number" step="0.01" min="0" name="records[{{ $index }}][passing_marks]"
                                        value="{{ old("records.$index.passing_marks", $existing?->passing_marks ?? $schedule->passing_marks) }}">
                                </td>
                                <td>
                                    <input class="input !w-24" type="number" step="0.01" min="0" name="records[{{ $index }}][obtained_marks]"
                                        value="{{ old("records.$index.obtained_marks", $existing?->obtained_marks) }}" placeholder="—">
                                </td>
                                <td>
                                    <select class="input !w-32" name="records[{{ $index }}][status]">
                                        <option value="">— auto —</option>
                                        @foreach($statuses as $st)
                                            <option value="{{ $st }}" @selected(old("records.$index.status", $existing?->status) === $st)>{{ ucfirst($st) }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td>
                                    <input class="input !w-40" type="text" name="records[{{ $index }}][remarks]" value="{{ old("records.$index.remarks", $existing?->remarks) }}" placeholder="Optional">
                                </td>
                            @else
                                <td>{{ $existing ? (float) $existing->max_marks : (float) $schedule->max_marks }}</td>
                                <td>{{ $existing ? (float) $existing->passing_marks : (float) $schedule->passing_marks }}</td>
                                <td>{{ $existing?->obtained_marks !== null ? (float) $existing->obtained_marks : '—' }}</td>
                                <td>
                                    @php
                                        $badgeClass = match($existing?->status) {
                                            'entered' => 'bg-emerald-100 text-emerald-700',
                                            'absent' => 'bg-rose-100 text-rose-700',
                                            'withheld' => 'bg-amber-100 text-amber-700',
                                            default => 'bg-slate-100 text-slate-600',
                                        };
                                    @endphp
                                    <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $badgeClass }}">{{ ucfirst($existing?->status ?? 'draft') }}</span>
                                </td>
                                <td class="max-w-40 truncate" title="{{ $existing?->remarks }}">{{ $existing?->remarks ?? '—' }}</td>
                            @endif
                            <td class="text-right">
                                @if($existing)
                                    <div class="flex flex-wrap items-center justify-end gap-2">
                                        @can('update', $existing)
                                            <a class="text-xs font-semibold text-indigo-600 hover:underline" href="{{ route('exam-marks.edit', $existing) }}">Edit</a>
                                        @endcan
                                        <span class="text-xs text-slate-400">{{ ucfirst($existing->status) }}</span>
                                    </div>
                                @else
                                    <span class="text-xs text-slate-400">Not entered yet</span>
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

        @if($canEnter)
                <div class="mt-4 flex items-center justify-between gap-2">
                    <p class="text-xs text-slate-500">Rows left empty are not changed. Absent / withheld rows keep no obtained marks.</p>
                    <button class="button" type="submit">Save Marks</button>
                </div>
            </form>
        @endif

        <div class="mt-4 flex flex-wrap items-center justify-between gap-2">
            <p class="text-xs text-slate-500">Showing {{ $enrollments->firstItem() ?? 0 }}–{{ $enrollments->lastItem() ?? 0 }} of {{ $enrollments->total() }} eligible enrollments.</p>
            {{ $enrollments->links() }}
        </div>
    @else
        {{-- Marks records list with filters. --}}
        <div class="mt-8 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b text-slate-500">
                        <th class="py-3">Enrollment No.</th>
                        <th>Student</th>
                        <th>Examination</th>
                        <th>Subject</th>
                        <th>Marks (Obt / Max / Pass)</th>
                        <th>Status</th>
                        <th>Entered By / At</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($marks as $mark)
                        <tr class="border-b">
                            <td class="py-3 font-medium">{{ $mark->studentEnrollment?->enrollment_number ?? '—' }}</td>
                            <td>{{ $mark->studentEnrollment?->student?->fullName() ?? '—' }}</td>
                            <td>{{ $mark->examSchedule?->examination?->name ?? '—' }}</td>
                            <td>{{ $mark->examSchedule?->subject?->name ?? '—' }}</td>
                            <td>
                                @if($mark->obtained_marks !== null)
                                    <span class="font-medium">{{ (float) $mark->obtained_marks }}</span>
                                @else
                                    <span class="text-slate-400">—</span>
                                @endif
                                <span class="text-xs text-slate-500">/ {{ (float) $mark->max_marks }} / {{ (float) $mark->passing_marks }}</span>
                            </td>
                            <td>
                                @php
                                    $badgeClass = match($mark->status) {
                                        'entered' => 'bg-emerald-100 text-emerald-700',
                                        'absent' => 'bg-rose-100 text-rose-700',
                                        'withheld' => 'bg-amber-100 text-amber-700',
                                        default => 'bg-slate-100 text-slate-600',
                                    };
                                @endphp
                                <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $badgeClass }}">{{ ucfirst($mark->status) }}</span>
                            </td>
                            <td>
                                <span class="text-xs text-slate-500">{{ $mark->enteredBy?->name ?? '—' }}</span>
                                <p class="text-xs text-slate-400">{{ $mark->entered_at?->format('d M Y H:i') }}</p>
                            </td>
                            <td class="text-right">
                                <div class="flex flex-wrap items-center justify-end gap-2">
                                    @can('update', $mark)
                                        <a class="text-xs font-semibold text-indigo-600 hover:underline" href="{{ route('exam-marks.edit', $mark) }}">Edit</a>
                                    @endcan
                                    @can('delete', $mark)
                                        <form method="POST" action="{{ route('exam-marks.destroy', $mark) }}" onsubmit="return confirm(@js('Delete this marks entry? This can be undone by an administrator.'))">
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
                            <td class="py-6 text-slate-500" colspan="8">No marks entries found. Select an exam schedule above to start entering marks.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4 flex flex-wrap items-center justify-between gap-2">
            <p class="text-xs text-slate-500">Showing {{ $marks->firstItem() ?? 0 }}–{{ $marks->lastItem() ?? 0 }} of {{ $marks->total() }} marks entries.</p>
            {{ $marks->links() }}
        </div>
    @endif
</div>
@endsection
