@extends('layouts.app')
@section('title','Student ID Cards')
@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Student ID Cards</h2>
            <p class="panel-subtitle">
                ID cards are generated on demand from the student record and the current enrollment — no separate
                identity record is created or stored.
            </p>
        </div>
    </div>

    <form method="GET" action="{{ route('student-id-cards.index') }}" class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <input class="input" type="search" name="search" value="{{ $search }}" placeholder="Search number, name or email">
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
        <select class="input" name="section_id">
            <option value="">All sections</option>
            @foreach($sections as $section)
                <option value="{{ $section->id }}" @selected((string) $section_id === (string) $section->id)>
                    {{ $section->name }} — {{ $section->academicYear?->name }} · {{ $section->program?->name }}
                </option>
            @endforeach
        </select>
        <div class="flex gap-2">
            <button class="button" type="submit">Filter</button>
            @if($search !== '' || $academic_year_id || $program_id || $section_id)
                <a class="button !bg-slate-200 !text-slate-700" href="{{ route('student-id-cards.index') }}">Clear</a>
            @endif
        </div>
    </form>

    <div class="mt-8 overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead>
                <tr class="border-b text-slate-500">
                    <th class="py-3">Student</th>
                    <th>Current enrollment</th>
                    <th>Academic year</th>
                    <th>Program</th>
                    <th>Section</th>
                    <th class="text-right">ID card</th>
                </tr>
            </thead>
            <tbody>
            @forelse($students as $student)
                @php($current = $student->currentEnrollment())
                <tr class="border-b">
                    <td class="py-3">
                        <a class="font-medium text-indigo-600 hover:underline" href="{{ route('students.show', $student) }}">{{ $student->student_number }}</a>
                        <span class="block text-xs text-slate-500">{{ $student->fullName() }}</span>
                    </td>
                    <td>{{ $current?->enrollment_number ?? '—' }}</td>
                    <td>{{ $current?->academicYear?->name ?? '—' }}</td>
                    <td>{{ $current?->program?->name ?? '—' }}</td>
                    <td>{{ $current?->section?->name ?? '—' }}</td>
                    <td class="text-right">
                        @if(auth()->user()?->hasPermission('student_id_cards.generate'))
                            <a class="text-xs font-semibold text-indigo-600 hover:underline" href="{{ route('student-id-cards.show', $student) }}" target="_blank" rel="noopener">View / print</a>
                        @else
                            <span class="text-xs text-slate-400">No permission</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td class="py-6 text-slate-500" colspan="6">No students found.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4 flex flex-wrap items-center justify-between gap-2">
        <p class="text-xs text-slate-500">Showing {{ $students->firstItem() ?? 0 }}–{{ $students->lastItem() ?? 0 }} of {{ $students->total() }} students.</p>
        {{ $students->links() }}
    </div>
</div>
@endsection
