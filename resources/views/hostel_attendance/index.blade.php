@extends('layouts.app')

@section('title', 'Hostel Attendance')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Hostel Attendance</h2>
            <p class="panel-subtitle">Daily attendance for current hostel residents. Only an active hostel allocation can be marked, and each student has one record per date. Corrections stay on the original record.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            @can('create', App\Models\HostelAttendance::class)
                <a class="button !bg-slate-200 !text-slate-700" href="{{ route('hostel-attendance.bulk') }}">Bulk attendance</a>
                <a class="button" href="{{ route('hostel-attendance.create') }}">+ Mark attendance</a>
            @endcan
        </div>
    </div>

    <form class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-5" method="GET" action="{{ route('hostel-attendance.index') }}">
        <div>
            <label class="label" for="attendance_date">Date</label>
            <input class="input" id="attendance_date" name="attendance_date" type="date" value="{{ $filters['attendance_date'] }}">
        </div>
        <div>
            <label class="label" for="hostel_id">Hostel</label>
            <select class="input" id="hostel_id" name="hostel_id">
                <option value="">All hostels</option>
                @foreach($hostels as $hostel)
                    <option value="{{ $hostel->id }}" @selected((string) $filters['hostel_id'] === (string) $hostel->id)>{{ $hostel->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="search">Student search</label>
            <input class="input" id="search" name="search" type="search" value="{{ $filters['search'] }}" placeholder="Name, number, enrollment">
        </div>
        <div>
            <label class="label" for="attendance_status">Status</label>
            <select class="input" id="attendance_status" name="attendance_status">
                <option value="">All statuses</option>
                @foreach($statuses as $status)
                    <option value="{{ $status }}" @selected($filters['attendance_status'] === $status)>{{ ucfirst($status) }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex items-end gap-2">
            <button class="button" type="submit">Filter</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('hostel-attendance.index') }}">Clear</a>
        </div>
    </form>

    <div class="mt-6 overflow-x-auto">
        <table class="w-full min-w-[760px] text-left text-sm">
            <thead>
                <tr class="border-b text-slate-500">
                    <th class="py-2 pr-3">Date</th>
                    <th class="py-2 pr-3">Student</th>
                    <th class="py-2 pr-3">Hostel</th>
                    <th class="py-2 pr-3">Room / Bed</th>
                    <th class="py-2 pr-3">Status</th>
                    <th class="py-2 pr-3">Remarks</th>
                    <th class="py-2 pr-3">Marked</th>
                    <th class="py-2 text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($attendances as $attendance)
                    @php
                        $tone = match ($attendance->attendance_status) {
                            'present' => 'bg-emerald-100 text-emerald-700',
                            'absent' => 'bg-rose-100 text-rose-700',
                            'leave' => 'bg-amber-100 text-amber-700',
                            default => 'bg-slate-100 text-slate-600',
                        };
                    @endphp
                    <tr class="border-b">
                        <td class="whitespace-nowrap py-2 pr-3">{{ $attendance->attendance_date?->format('d M Y') }}</td>
                        <td class="whitespace-nowrap py-2 pr-3 font-medium">
                            {{ $attendance->studentEnrollment?->student?->first_name }} {{ $attendance->studentEnrollment?->student?->last_name }}
                            <span class="block text-xs text-slate-500">{{ $attendance->studentEnrollment?->enrollment_number ?? $attendance->studentEnrollment?->student?->student_number ?? '—' }}</span>
                        </td>
                        <td class="whitespace-nowrap py-2 pr-3">
                            {{ $attendance->allocation?->hostel?->name ?? '—' }}
                            <span class="block text-xs text-slate-500">{{ $attendance->allocation?->building?->name ?? '—' }}</span>
                        </td>
                        <td class="whitespace-nowrap py-2 pr-3">{{ $attendance->allocation?->room?->room_number ?? '—' }} / {{ $attendance->allocation?->bed?->bed_number ?? '—' }}</td>
                        <td class="whitespace-nowrap py-2 pr-3"><span class="rounded-full px-3 py-1 text-xs font-semibold {{ $tone }}">{{ ucfirst($attendance->attendance_status) }}</span></td>
                        <td class="max-w-[16rem] py-2 pr-3 text-slate-600">{{ $attendance->remarks ?: '—' }}</td>
                        <td class="whitespace-nowrap py-2 pr-3 text-xs text-slate-500">
                            {{ $attendance->marked_at?->format('d M Y H:i') ?? '—' }}
                            <span class="block">{{ $attendance->marker?->name ?? '—' }}</span>
                        </td>
                        <td class="whitespace-nowrap py-2 text-right">
                            <div class="flex justify-end gap-2">
                                @can('update', $attendance)
                                    <a class="button !bg-slate-200 !text-slate-700" href="{{ route('hostel-attendance.edit', $attendance) }}">Correct</a>
                                @endcan
                                @can('delete', $attendance)
                                    <form method="POST" action="{{ route('hostel-attendance.destroy', $attendance) }}" onsubmit="return confirm('Delete this attendance record? History is preserved as a soft delete.');">
                                        @csrf
                                        @method('DELETE')
                                        <button class="button !bg-rose-100 !text-rose-700" type="submit">Delete</button>
                                    </form>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td class="py-4 text-slate-500" colspan="8">No hostel attendance records found for this college.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-6">{{ $attendances->links() }}</div>
</div>
@endsection
