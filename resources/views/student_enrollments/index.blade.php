@extends('layouts.app')
@section('title','Enrollments')
@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Enrollments</h2>
            <p class="panel-subtitle">Periodic academic-year enrollments. Past enrollments are preserved as history when a student moves to a new academic year.</p>
        </div>
        @can('create', App\Models\StudentEnrollment::class)
            <a class="button" href="{{ route('student-enrollments.create') }}">+ New enrollment</a>
        @endcan
    </div>

    <form method="GET" action="{{ route('student-enrollments.index') }}" class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <select class="input" name="student_id">
            <option value="">All students</option>
            @foreach($students as $s)
                <option value="{{ $s->id }}" @selected((int) $student_id === $s->id)>{{ $s->student_number }} — {{ $s->first_name }} {{ $s->last_name }}</option>
            @endforeach
        </select>
        <select class="input" name="academic_year_id">
            <option value="">All academic years</option>
            @foreach($academicYears as $ay)
                <option value="{{ $ay->id }}" @selected((int) $academic_year_id === $ay->id)>{{ $ay->name }} ({{ $ay->code }})</option>
            @endforeach
        </select>
        <select class="input" name="status">
            <option value="">All statuses</option>
            @foreach(\App\Models\StudentEnrollment::STATUSES as $s)
                <option value="{{ $s }}" @selected($status === $s)>{{ ucfirst($s) }}</option>
            @endforeach
        </select>
        <div class="flex gap-2">
            <button class="button" type="submit">Filter</button>
            @if($student_id || $academic_year_id || $status)
                <a class="button !bg-slate-200 !text-slate-700" href="{{ route('student-enrollments.index') }}">Clear</a>
            @endif
        </div>
    </form>

    <div class="mt-8 overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead>
                <tr class="border-b text-slate-500">
                    <th class="py-3">Number</th>
                    <th>Student</th>
                    <th>Academic year</th>
                    <th>Program</th>
                    <th>Date</th>
                    <th>Status</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($enrollments as $enrollment)
                    <tr class="border-b">
                        <td class="py-3 font-medium">{{ $enrollment->enrollment_number }}</td>
                        <td>{{ $enrollment->student?->student_number }} — {{ $enrollment->student?->first_name }} {{ $enrollment->student?->last_name }}</td>
                        <td>{{ $enrollment->academicYear?->name ?? '—' }}</td>
                        <td>{{ $enrollment->program?->name ?? '—' }}</td>
                        <td>{{ $enrollment->enrollment_date?->format('d M Y') ?? '—' }}</td>
                        <td>
                            <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $enrollment->status === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-600' }}">
                                {{ ucfirst($enrollment->status) }}
                            </span>
                        </td>
                        <td class="text-right">
                            <div class="flex flex-wrap items-center justify-end gap-2">
                                @can('update', $enrollment)
                                    <a class="text-xs font-semibold text-indigo-600 hover:underline" href="{{ route('student-enrollments.edit', $enrollment) }}">Edit</a>
                                @endcan
                                @can('delete', $enrollment)
                                    <form method="POST" action="{{ route('student-enrollments.destroy', $enrollment) }}" onsubmit="return confirm(@js('Delete enrollment '.$enrollment->enrollment_number.'? This can be undone by an administrator.'))">
                                        @csrf
                                        @method('DELETE')
                                        <button class="text-xs font-semibold text-rose-600 hover:underline" type="submit">Delete</button>
                                    </form>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td class="py-6 text-slate-500" colspan="7">No enrollments found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4 flex flex-wrap items-center justify-between gap-2">
        <p class="text-xs text-slate-500">Showing {{ $enrollments->firstItem() ?? 0 }}–{{ $enrollments->lastItem() ?? 0 }} of {{ $enrollments->total() }} enrollments.</p>
        {{ $enrollments->links() }}
    </div>
</div>
@endsection
