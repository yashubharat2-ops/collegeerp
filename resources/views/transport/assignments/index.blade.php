@extends('layouts.app')
@section('title', 'Student Transport Assignment')
@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Student Transport Assignment</h2>
            <p class="panel-subtitle">Existing student enrollments onto existing routes and stops — no duplicate masters. One active assignment per enrollment and academic year; completed and cancelled history is preserved.</p>
        </div>
        @can('create', App\Models\StudentTransportAssignment::class)
            <a class="button" href="{{ route('transport-assignments.create') }}">+ Assign student</a>
        @endcan
    </div>

    <form method="GET" action="{{ route('transport-assignments.index') }}" class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
        <select class="input" name="student_id">
            <option value="">All students</option>
            @foreach($enrollments->unique('student_id') as $enrollment)
                <option value="{{ $enrollment->student_id }}" @selected((string) $selected['student_id'] === (string) $enrollment->student_id)>{{ $enrollment->student?->student_number }} — {{ $enrollment->student?->first_name }} {{ $enrollment->student?->last_name }}</option>
            @endforeach
        </select>
        <select class="input" name="academic_year_id">
            <option value="">All academic years</option>
            @foreach($years as $year)
                <option value="{{ $year->id }}" @selected((string) $selected['academic_year_id'] === (string) $year->id)>{{ $year->name }}</option>
            @endforeach
        </select>
        <select class="input" name="route_id">
            <option value="">All routes</option>
            @foreach($routes as $route)
                <option value="{{ $route->id }}" @selected((string) $selected['route_id'] === (string) $route->id)>{{ $route->name }}</option>
            @endforeach
        </select>
        <select class="input" name="stop_id">
            <option value="">All stops</option>
            @foreach($stops as $stop)
                <option value="{{ $stop->id }}" @selected((string) $selected['stop_id'] === (string) $stop->id)>{{ $routes->firstWhere('id', $stop->route_id)?->name ?? '—' }} — {{ $stop->name }}</option>
            @endforeach
        </select>
        <select class="input" name="status">
            <option value="">All statuses</option>
            @foreach($statuses as $status)
                <option value="{{ $status }}" @selected($selected['status'] === $status)>{{ ucfirst($status) }}</option>
            @endforeach
        </select>
        <div class="flex gap-2">
            <button class="button" type="submit">Filter</button>
            @if(array_filter($selected))
                <a class="button !bg-slate-200 !text-slate-700" href="{{ route('transport-assignments.index') }}">Clear</a>
            @endif
        </div>
    </form>

    <div class="mt-6 overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead class="border-b bg-slate-50 text-slate-500">
                <tr>
                    <th class="whitespace-nowrap px-3 py-3">Student</th>
                    <th class="whitespace-nowrap px-3 py-3">Enrollment</th>
                    <th class="whitespace-nowrap px-3 py-3">Academic Year</th>
                    <th class="whitespace-nowrap px-3 py-3">Route</th>
                    <th class="whitespace-nowrap px-3 py-3">Stop</th>
                    <th class="whitespace-nowrap px-3 py-3">Period</th>
                    <th class="whitespace-nowrap px-3 py-3">Status</th>
                    <th class="px-3 py-3">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                @forelse($assignments as $assignment)
                    <tr>
                        <td class="whitespace-nowrap px-3 py-3 font-medium">
                            {{ $assignment->studentEnrollment?->student?->first_name }} {{ $assignment->studentEnrollment?->student?->last_name }}
                            <span class="block text-xs text-slate-500">{{ $assignment->studentEnrollment?->student?->student_number }}</span>
                        </td>
                        <td class="whitespace-nowrap px-3 py-3">{{ $assignment->studentEnrollment?->enrollment_number ?? '—' }}</td>
                        <td class="whitespace-nowrap px-3 py-3">{{ $assignment->academicYear?->name ?? '—' }}</td>
                        <td class="whitespace-nowrap px-3 py-3">{{ $assignment->transportRoute?->name ?? '—' }}</td>
                        <td class="whitespace-nowrap px-3 py-3">{{ $assignment->transportStop?->name ?? '—' }}</td>
                        <td class="whitespace-nowrap px-3 py-3 text-xs">{{ $assignment->start_date?->format('d M Y') }} {{ $assignment->end_date ? '→ '.$assignment->end_date->format('d M Y') : '' }}</td>
                        <td class="whitespace-nowrap px-3 py-3">
                            <span class="rounded-full px-2 py-0.5 text-xs font-semibold
                                @if($assignment->status === App\Models\StudentTransportAssignment::STATUS_ACTIVE) bg-emerald-100 text-emerald-700
                                @elseif($assignment->status === App\Models\StudentTransportAssignment::STATUS_COMPLETED) bg-slate-100 text-slate-700
                                @else bg-rose-100 text-rose-700 @endif">{{ ucfirst($assignment->status) }}</span>
                        </td>
                        <td class="px-3 py-3">
                            <div class="flex gap-2">
                                @can('update', $assignment)<a class="button" href="{{ route('transport-assignments.edit', $assignment->id) }}">Edit</a>@endcan
                                @can('delete', $assignment)
                                    <form method="POST" action="{{ route('transport-assignments.destroy', $assignment->id) }}" onsubmit="return confirm('Delete this transport assignment? History is preserved (soft delete) and the action is audited.')">@csrf @method('DELETE')<button class="button !bg-red-600">Delete</button></form>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-3 py-8 text-center text-slate-500">No student transport assignments found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $assignments->links() }}</div>
</div>
@endsection
