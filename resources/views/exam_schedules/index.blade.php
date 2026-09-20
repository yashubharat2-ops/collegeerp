@extends('layouts.app')

@section('title', 'Exam Schedule')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Exam Schedule</h2>
            <p class="panel-subtitle">Manage examination timetable, slots, rooms, and marks distribution.</p>
        </div>
        @can('create', App\Models\ExamSchedule::class)
            <a class="button" href="{{ route('exam-schedules.create', array_filter(['examination_id' => $examination_id])) }}">+ New schedule entry</a>
        @endcan
    </div>

    <form method="GET" action="{{ route('exam-schedules.index') }}" class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <input class="input" type="search" name="search" value="{{ $search }}" placeholder="Search exam, subject, room">
        <select class="input" name="examination_id">
            <option value="">All examinations</option>
            @foreach($examinations as $exam)
                <option value="{{ $exam->id }}" @selected((int) $examination_id === $exam->id)>
                    {{ $exam->name }} ({{ $exam->code }})
                </option>
            @endforeach
        </select>
        <select class="input" name="academic_year_id">
            <option value="">All academic years</option>
            @foreach($academicYears as $year)
                <option value="{{ $year->id }}" @selected((int) $academic_year_id === $year->id)>
                    {{ $year->name }}
                </option>
            @endforeach
        </select>
        <select class="input" name="academic_term_id">
            <option value="">All academic terms</option>
            @foreach($academicTerms as $term)
                <option value="{{ $term->id }}" @selected((int) $academic_term_id === $term->id)>
                    {{ $term->name }} ({{ $term->code }})
                </option>
            @endforeach
        </select>
        <select class="input" name="program_id">
            <option value="">All programs</option>
            @foreach($programs as $prog)
                <option value="{{ $prog->id }}" @selected((int) $program_id === $prog->id)>
                    {{ $prog->name }} ({{ $prog->code }})
                </option>
            @endforeach
        </select>
        <select class="input" name="section_id">
            <option value="">All sections</option>
            @foreach($sections as $sec)
                <option value="{{ $sec->id }}" @selected((int) $section_id === $sec->id)>
                    {{ $sec->name }} ({{ $sec->code }})
                </option>
            @endforeach
        </select>
        <select class="input" name="subject_id">
            <option value="">All subjects</option>
            @foreach($subjects as $sub)
                <option value="{{ $sub->id }}" @selected((int) $subject_id === $sub->id)>
                    {{ $sub->name }} ({{ $sub->code }})
                </option>
            @endforeach
        </select>
        <select class="input" name="status">
            <option value="">All statuses</option>
            @foreach($statuses as $st)
                <option value="{{ $st }}" @selected($status === $st)>{{ ucfirst($st) }}</option>
            @endforeach
        </select>
        <div class="flex gap-2 sm:col-span-2 lg:col-span-4">
            <button class="button" type="submit">Filter</button>
            @if($search !== '' || $examination_id || $academic_year_id || $academic_term_id || $program_id || $section_id || $subject_id || $status)
                <a class="button !bg-slate-200 !text-slate-700" href="{{ route('exam-schedules.index') }}">Clear</a>
            @endif
        </div>
    </form>

    <div class="mt-8 overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead>
                <tr class="border-b text-slate-500">
                    <th class="py-3">Examination</th>
                    <th>Date & Time</th>
                    <th>Subject</th>
                    <th>Program / Section</th>
                    <th>Venue / Room</th>
                    <th>Faculty / Invigilator</th>
                    <th>Marks (Max / Pass)</th>
                    <th>Status</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($schedules as $sched)
                    <tr class="border-b">
                        <td class="py-3 font-medium">
                            {{ $sched->examination?->name ?? '—' }}
                            <p class="text-xs text-slate-500">{{ $sched->examination?->code }}</p>
                        </td>
                        <td>
                            <span class="font-medium">{{ $sched->exam_date?->format('M d, Y') }}</span>
                            <p class="text-xs text-slate-500">
                                {{ substr($sched->start_time, 0, 5) }} – {{ substr($sched->end_time, 0, 5) }}
                            </p>
                        </td>
                        <td>
                            <span class="font-medium">{{ $sched->subject?->name ?? '—' }}</span>
                            <p class="text-xs text-slate-500">{{ $sched->subject?->code }}</p>
                        </td>
                        <td>
                            <span>{{ $sched->program?->code ?? $sched->program?->name }}</span>
                            <span class="text-xs text-slate-500">/ {{ $sched->section?->name }}</span>
                        </td>
                        <td>
                            {{ $sched->room ?? '—' }}
                            @if($sched->campus)
                                <p class="text-xs text-slate-500">{{ $sched->campus->name }}</p>
                            @endif
                        </td>
                        <td>
                            {{ $sched->faculty ? $sched->faculty->full_name : '—' }}
                        </td>
                        <td>
                            {{ (float) $sched->max_marks }} / {{ (float) $sched->passing_marks }}
                        </td>
                        <td>
                            @php
                                $badgeClass = match($sched->status) {
                                    'completed' => 'bg-emerald-100 text-emerald-700',
                                    'cancelled' => 'bg-rose-100 text-rose-700',
                                    default => 'bg-blue-100 text-blue-700',
                                };
                            @endphp
                            <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $badgeClass }}">
                                {{ ucfirst($sched->status) }}
                            </span>
                        </td>
                        <td class="text-right">
                            <div class="flex flex-wrap items-center justify-end gap-2">
                                @can('update', $sched)
                                    <a class="text-xs font-semibold text-indigo-600 hover:underline" href="{{ route('exam-schedules.edit', $sched) }}">Edit</a>
                                @endcan
                                @can('delete', $sched)
                                    <form method="POST" action="{{ route('exam-schedules.destroy', $sched) }}" onsubmit="return confirm(@js('Delete schedule entry for '.($sched->subject?->name ?? 'Subject').'? This can be undone by an administrator.'))">
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
                        <td class="py-6 text-slate-500" colspan="9">No exam schedule entries found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4 flex flex-wrap items-center justify-between gap-2">
        <p class="text-xs text-slate-500">Showing {{ $schedules->firstItem() ?? 0 }}–{{ $schedules->lastItem() ?? 0 }} of {{ $schedules->total() }} schedule entries.</p>
        {{ $schedules->links() }}
    </div>
</div>
@endsection
