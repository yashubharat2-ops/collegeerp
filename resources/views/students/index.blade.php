@extends('layouts.app')
@section('title','Students')
@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Students</h2>
            <p class="panel-subtitle">Officially enrolled students within the active college. Each student accumulates an enrollment per academic year.</p>
        </div>
        @can('create', App\Models\Student::class)
            <a class="button" href="{{ route('students.create') }}">+ New student</a>
        @endcan
    </div>

    <form method="GET" action="{{ route('students.index') }}" class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <input class="input" type="search" name="search" value="{{ $search }}" placeholder="Search number, name, email, phone">
        <select class="input" name="status">
            <option value="">All statuses</option>
            @foreach(\App\Models\Student::STATUSES as $s)
                <option value="{{ $s }}" @selected($status === $s)>{{ ucfirst($s) }}</option>
            @endforeach
        </select>
        <select class="input" name="academic_year_id">
            <option value="">All academic years</option>
            @foreach($academicYears as $ay)
                <option value="{{ $ay->id }}" @selected((int) $academic_year_id === $ay->id)>{{ $ay->name }} ({{ $ay->code }})</option>
            @endforeach
        </select>
        <div class="flex gap-2">
            <button class="button" type="submit">Filter</button>
            @if($search !== '' || $status || $academic_year_id)
                <a class="button !bg-slate-200 !text-slate-700" href="{{ route('students.index') }}">Clear</a>
            @endif
        </div>
    </form>

    <div class="mt-8 overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead>
                <tr class="border-b text-slate-500">
                    <th class="py-3">Number</th>
                    <th>Name</th>
                    <th>Contact</th>
                    <th>Current enrollment</th>
                    <th>Status</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($students as $student)
                    <tr class="border-b">
                        <td class="py-3 font-medium">{{ $student->student_number }}</td>
                        <td>
                            <a href="{{ route('students.show', $student) }}" class="font-medium text-indigo-600 hover:underline">
                                {{ $student->first_name }} {{ $student->middle_name }} {{ $student->last_name }}
                            </a>
                        </td>
                        <td>{{ $student->email ?? '—' }} @if($student->phone)<span class="block text-xs text-slate-500">{{ $student->phone }}</span>@endif</td>
                        <td class="text-slate-500">
                            @php($active = $student->enrollments->first(fn ($e) => $e->status === 'active'))
                            @if($active && $active->academicYear)
                                {{ $active->academicYear->name }}@if($active->program) · {{ $active->program->name }}@endif
                            @else
                                —
                            @endif
                        </td>
                        <td>
                            <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $student->status === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-600' }}">
                                {{ ucfirst($student->status) }}
                            </span>
                        </td>
                        <td class="text-right">
                            <div class="flex flex-wrap items-center justify-end gap-2">
                                @can('view', $student)
                                    <a class="text-xs font-semibold text-indigo-600 hover:underline" href="{{ route('students.show', $student) }}">View</a>
                                @endcan
                                @can('update', $student)
                                    <a class="text-xs font-semibold text-indigo-600 hover:underline" href="{{ route('students.edit', $student) }}">Edit</a>
                                @endcan
                                @can('delete', $student)
                                    <form method="POST" action="{{ route('students.destroy', $student) }}" onsubmit="return confirm(@js('Delete student '.$student->first_name.' '.$student->last_name.'? This can be undone by an administrator.'))">
                                        @csrf
                                        @method('DELETE')
                                        <button class="text-xs font-semibold text-rose-600 hover:underline" type="submit">Delete</button>
                                    </form>
                                @endcan
                            </div>
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
