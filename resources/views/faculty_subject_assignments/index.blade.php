@extends('layouts.app')

@section('title', 'Faculty–Subject Assignments')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Faculty–Subject Assignments</h2>
            <p class="panel-subtitle">Manage teaching allocations of faculty and staff members to subjects and sections.</p>
        </div>
        @can('create', App\Models\FacultySubjectAssignment::class)
            <a class="button" href="{{ route('faculty-subject-assignments.create') }}">+ New assignment</a>
        @endcan
    </div>

    <form method="GET" action="{{ route('faculty-subject-assignments.index') }}" class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <select class="input" name="academic_year_id">
            <option value="">All academic years</option>
            @foreach($academicYears as $year)
                <option value="{{ $year->id }}" @selected((int) $academic_year_id === $year->id)>
                    {{ $year->name }}
                </option>
            @endforeach
        </select>
        <select class="input" name="faculty_id">
            <option value="">All faculty members</option>
            @foreach($faculties as $fac)
                <option value="{{ $fac->id }}" @selected((int) $faculty_id === $fac->id)>
                    {{ $fac->full_name }}
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
            <option value="active" @selected($status === 'active')>Active</option>
            <option value="inactive" @selected($status === 'inactive')>Inactive</option>
        </select>
        <div class="flex gap-2">
            <button class="button" type="submit">Filter</button>
            @if($academic_year_id || $faculty_id || $subject_id || $status)
                <a class="button !bg-slate-200 !text-slate-700" href="{{ route('faculty-subject-assignments.index') }}">Clear</a>
            @endif
        </div>
    </form>

    <div class="mt-8 overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead>
                <tr class="border-b text-slate-500">
                    <th class="py-3">Faculty</th>
                    <th>Subject</th>
                    <th>Academic Year</th>
                    <th>Term / Sem</th>
                    <th>Program</th>
                    <th>Section</th>
                    <th>Status</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($assignments as $assign)
                    <tr class="border-b">
                        <td class="py-3 font-medium">
                            {{ $assign->faculty?->full_name ?? '—' }}
                            <div class="text-xs text-slate-500">{{ $assign->faculty?->employee_code }}</div>
                        </td>
                        <td>
                            {{ $assign->subject?->name ?? '—' }}
                            <div class="text-xs text-slate-500">{{ $assign->subject?->code }}</div>
                        </td>
                        <td>{{ $assign->academicYear?->name ?? '—' }}</td>
                        <td>{{ $assign->academicTerm?->name ?? '— (Full year)' }}</td>
                        <td>{{ $assign->program?->name ?? '— (All)' }}</td>
                        <td>{{ $assign->section?->name ?? '— (All)' }}</td>
                        <td>
                            <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $assign->status === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-600' }}">
                                {{ ucfirst($assign->status) }}
                            </span>
                        </td>
                        <td class="text-right">
                            <div class="flex flex-wrap items-center justify-end gap-2">
                                @can('update', $assign)
                                    <a class="text-xs font-semibold text-indigo-600 hover:underline" href="{{ route('faculty-subject-assignments.edit', $assign) }}">Edit</a>
                                @endcan
                                @can('delete', $assign)
                                    <form method="POST" action="{{ route('faculty-subject-assignments.destroy', $assign) }}" onsubmit="return confirm(@js('Delete faculty subject assignment? This can be undone by an administrator.'))">
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
                        <td class="py-6 text-slate-500" colspan="8">No assignments found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4 flex flex-wrap items-center justify-between gap-2">
        <p class="text-xs text-slate-500">Showing {{ $assignments->firstItem() ?? 0 }}–{{ $assignments->lastItem() ?? 0 }} of {{ $assignments->total() }} assignments.</p>
        {{ $assignments->links() }}
    </div>
</div>
@endsection
