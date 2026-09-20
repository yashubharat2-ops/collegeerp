@extends('layouts.app')

@section('title', 'Examinations')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Examinations</h2>
            <p class="panel-subtitle">Manage examinations and assessments within the active college.</p>
        </div>
        @can('create', App\Models\Examination::class)
            <a class="button" href="{{ route('examinations.create') }}">+ New examination</a>
        @endcan
    </div>

    <form method="GET" action="{{ route('examinations.index') }}" class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <input class="input" type="search" name="search" value="{{ $search }}" placeholder="Search name, code, type">
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
        <select class="input" name="status">
            <option value="">All statuses</option>
            @foreach($statuses as $st)
                <option value="{{ $st }}" @selected($status === $st)>{{ ucfirst($st) }}</option>
            @endforeach
        </select>
        <div class="flex gap-2">
            <button class="button" type="submit">Filter</button>
            @if($search !== '' || $academic_year_id || $academic_term_id || $status)
                <a class="button !bg-slate-200 !text-slate-700" href="{{ route('examinations.index') }}">Clear</a>
            @endif
        </div>
    </form>

    <div class="mt-8 overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead>
                <tr class="border-b text-slate-500">
                    <th class="py-3">Name</th>
                    <th>Code</th>
                    <th>Academic Year</th>
                    <th>Term</th>
                    <th>Type</th>
                    <th>Schedule Window</th>
                    <th>Status</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($examinations as $exam)
                    <tr class="border-b">
                        <td class="py-3 font-medium">
                            {{ $exam->name }}
                            @if($exam->description)
                                <p class="mt-0.5 max-w-xs truncate text-xs text-slate-500" title="{{ $exam->description }}">{{ $exam->description }}</p>
                            @endif
                        </td>
                        <td>{{ $exam->code }}</td>
                        <td>{{ $exam->academicYear?->name ?? '—' }}</td>
                        <td>{{ $exam->academicTerm?->name ?? '—' }}</td>
                        <td>{{ $exam->exam_type }}</td>
                        <td class="text-xs text-slate-600">
                            {{ $exam->start_date?->format('M d, Y') }} – {{ $exam->end_date?->format('M d, Y') }}
                        </td>
                        <td>
                            @php
                                $badgeClass = match($exam->status) {
                                    'published' => 'bg-blue-100 text-blue-700',
                                    'completed' => 'bg-emerald-100 text-emerald-700',
                                    'cancelled' => 'bg-rose-100 text-rose-700',
                                    default => 'bg-slate-200 text-slate-600',
                                };
                            @endphp
                            <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $badgeClass }}">
                                {{ ucfirst($exam->status) }}
                            </span>
                        </td>
                        <td class="text-right">
                            <div class="flex flex-wrap items-center justify-end gap-2">
                                @can('viewAny', App\Models\ExamSchedule::class)
                                    <a class="text-xs font-semibold text-slate-600 hover:underline" href="{{ route('exam-schedules.index', ['examination_id' => $exam->id]) }}">Schedule</a>
                                @endcan
                                @can('update', $exam)
                                    <a class="text-xs font-semibold text-indigo-600 hover:underline" href="{{ route('examinations.edit', $exam) }}">Edit</a>
                                @endcan
                                @can('delete', $exam)
                                    <form method="POST" action="{{ route('examinations.destroy', $exam) }}" onsubmit="return confirm(@js('Delete examination '.$exam->name.'? This can be undone by an administrator.'))">
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
                        <td class="py-6 text-slate-500" colspan="8">No examinations found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4 flex flex-wrap items-center justify-between gap-2">
        <p class="text-xs text-slate-500">Showing {{ $examinations->firstItem() ?? 0 }}–{{ $examinations->lastItem() ?? 0 }} of {{ $examinations->total() }} examinations.</p>
        {{ $examinations->links() }}
    </div>
</div>
@endsection
