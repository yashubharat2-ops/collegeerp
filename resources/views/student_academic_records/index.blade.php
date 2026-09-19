@extends('layouts.app')
@section('title','Academic Records')
@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Student Academic Records</h2>
            <p class="panel-subtitle">Academic progression per year/term. Academic years, terms, programs and sections are the Platform's master data — they are referenced here, never duplicated.</p>
        </div>
        @can('create', App\Models\StudentAcademicRecord::class)
            <a class="button" href="{{ route('student-academic-records.create') }}">+ New academic record</a>
        @endcan
    </div>

    <form method="GET" action="{{ route('student-academic-records.index') }}" class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <select class="input" name="student_id">
            <option value="">All students</option>
            @foreach($students as $s)
                <option value="{{ $s->id }}" @selected((string) $student_id === (string) $s->id)>{{ $s->student_number }} — {{ $s->first_name }} {{ $s->last_name }}</option>
            @endforeach
        </select>
        <select class="input" name="academic_year_id">
            <option value="">All academic years</option>
            @foreach($academicYears as $ay)
                <option value="{{ $ay->id }}" @selected((string) $academic_year_id === (string) $ay->id)>{{ $ay->name }} ({{ $ay->code }})</option>
            @endforeach
        </select>
        <select class="input" name="program_id">
            <option value="">All programs</option>
            @foreach($programs as $p)
                <option value="{{ $p->id }}" @selected((string) $program_id === (string) $p->id)>{{ $p->name }}</option>
            @endforeach
        </select>
        <select class="input" name="academic_status">
            <option value="">All academic statuses</option>
            @foreach(App\Models\StudentAcademicRecord::ACADEMIC_STATUSES as $status)
                <option value="{{ $status }}" @selected($academic_status === $status)>{{ ucfirst(str_replace('_', ' ', $status)) }}</option>
            @endforeach
        </select>
        <div class="flex gap-2">
            <button class="button" type="submit">Filter</button>
            @if($student_id || $academic_year_id || $program_id || $academic_status)
                <a class="button !bg-slate-200 !text-slate-700" href="{{ route('student-academic-records.index') }}">Clear</a>
            @endif
        </div>
    </form>

    <div class="mt-8 overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead>
                <tr class="border-b text-slate-500">
                    <th class="py-3">Student</th>
                    <th>Period</th>
                    <th>Program</th>
                    <th>Section</th>
                    <th>Academic</th>
                    <th>Promotion</th>
                    <th>Completion</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
            @forelse($records as $record)
                <tr class="border-b">
                    <td class="py-3">
                        <a class="font-medium text-indigo-600 hover:underline" href="{{ route('students.show', ['student' => $record->student_id, 'tab' => 'academic-records']) }}">
                            {{ $record->student?->student_number }}
                        </a>
                        <span class="block text-xs text-slate-500">{{ $record->student?->fullName() }}</span>
                    </td>
                    <td>{{ $record->periodLabel() }}</td>
                    <td>{{ $record->program?->name ?? '—' }}</td>
                    <td>{{ $record->section?->name ?? '—' }}</td>
                    <td>{{ ucfirst(str_replace('_', ' ', $record->academic_status)) }}</td>
                    <td>{{ ucfirst(str_replace('_', ' ', $record->promotion_status)) }}</td>
                    <td>{{ ucfirst(str_replace('_', ' ', $record->completion_status)) }}</td>
                    <td class="text-right">
                        <div class="flex flex-wrap items-center justify-end gap-2">
                            @can('update', $record)
                                <a class="text-xs font-semibold text-indigo-600 hover:underline" href="{{ route('student-academic-records.edit', $record) }}">Edit</a>
                            @endcan
                            @can('delete', $record)
                                <form method="POST" action="{{ route('student-academic-records.destroy', $record) }}"
                                      onsubmit="return confirm(@js('Delete this academic record for '.$record->student?->fullName().'?'))">
                                    @csrf
                                    @method('DELETE')
                                    <button class="text-xs font-semibold text-rose-600 hover:underline" type="submit">Delete</button>
                                </form>
                            @endcan
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td class="py-6 text-slate-500" colspan="8">No academic records found.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4 flex flex-wrap items-center justify-between gap-2">
        <p class="text-xs text-slate-500">Showing {{ $records->firstItem() ?? 0 }}–{{ $records->lastItem() ?? 0 }} of {{ $records->total() }} records.</p>
        {{ $records->links() }}
    </div>
</div>
@endsection
