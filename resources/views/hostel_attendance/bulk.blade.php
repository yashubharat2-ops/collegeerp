@extends('layouts.app')

@section('title', 'Bulk Hostel Attendance')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Bulk Hostel Attendance</h2>
            <p class="panel-subtitle">Mark every current resident for one date. The save is transactional: if any selected row is not a current resident of this college, nothing is written. Blank rows are skipped. Saving again corrects the existing mark.</p>
        </div>
        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('hostel-attendance.index') }}">Back to register</a>
    </div>

    <form class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4" method="GET" action="{{ route('hostel-attendance.bulk') }}">
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
            <input class="input" id="search" name="search" type="search" value="{{ $filters['search'] }}" placeholder="Name or enrollment">
        </div>
        <div class="flex items-end gap-2">
            <button class="button" type="submit">Load residents</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('hostel-attendance.bulk') }}">Clear</a>
        </div>
    </form>

    <form class="mt-6" method="POST" action="{{ route('hostel-attendance.bulk.store') }}">
        @csrf
        <input type="hidden" name="attendance_date" value="{{ $filters['attendance_date'] }}">
        @error('attendance_date')<p class="mb-3 text-sm text-rose-600">{{ $message }}</p>@enderror
        @error('records')<p class="mb-3 text-sm text-rose-600">{{ $message }}</p>@enderror

        <div class="mb-3 flex flex-wrap gap-2">
            @can('create', App\Models\HostelAttendance::class)
                <button class="button !bg-slate-200 !text-slate-700" type="button" onclick="document.querySelectorAll('[data-attendance-status]').forEach(function (el) { el.value = 'present'; })">Mark all present</button>
                <button class="button !bg-slate-200 !text-slate-700" type="button" onclick="document.querySelectorAll('[data-attendance-status]').forEach(function (el) { el.value = ''; })">Clear statuses</button>
            @endcan
        </div>

        <div class="overflow-x-auto">
            <table class="w-full min-w-[760px] text-left text-sm">
                <thead>
                    <tr class="border-b text-slate-500">
                        <th class="py-2 pr-3">Student</th>
                        <th class="py-2 pr-3">Hostel</th>
                        <th class="py-2 pr-3">Room / Bed</th>
                        <th class="py-2 pr-3">Status</th>
                        <th class="py-2">Remarks</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($residents as $index => $resident)
                        @php($marked = $existing->get($resident->id))
                        <tr class="border-b">
                            <td class="whitespace-nowrap py-2 pr-3 font-medium">
                                {{ $resident->studentEnrollment?->student?->first_name }} {{ $resident->studentEnrollment?->student?->last_name }}
                                <span class="block text-xs text-slate-500">{{ $resident->studentEnrollment?->enrollment_number }}</span>
                                <input type="hidden" name="records[{{ $index }}][hostel_allocation_id]" value="{{ $resident->id }}">
                            </td>
                            <td class="whitespace-nowrap py-2 pr-3">{{ $resident->hostel?->name ?? '—' }}<span class="block text-xs text-slate-500">{{ $resident->building?->name ?? '—' }}</span></td>
                            <td class="whitespace-nowrap py-2 pr-3">{{ $resident->room?->room_number ?? '—' }} / {{ $resident->bed?->bed_number ?? '—' }}</td>
                            <td class="py-2 pr-3">
                                <select class="input" name="records[{{ $index }}][attendance_status]" data-attendance-status>
                                    <option value="">—</option>
                                    @foreach($statuses as $status)
                                        <option value="{{ $status }}" @selected(old("records.$index.attendance_status", $marked?->attendance_status) === $status)>{{ ucfirst($status) }}</option>
                                    @endforeach
                                </select>
                                @error("records.$index.hostel_allocation_id")<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                                @error("records.$index.attendance_status")<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                            </td>
                            <td class="py-2">
                                <input class="input" name="records[{{ $index }}][remarks]" type="text" maxlength="2000" value="{{ old("records.$index.remarks", $marked?->remarks) }}" placeholder="Optional">
                            </td>
                        </tr>
                    @empty
                        <tr><td class="py-4 text-slate-500" colspan="5">No current hostel residents match these filters.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @can('create', App\Models\HostelAttendance::class)
            @if($residents->isNotEmpty())
                <div class="mt-6">
                    <button class="button" type="submit">Save bulk attendance</button>
                </div>
            @endif
        @endcan
    </form>
</div>
@endsection
