@extends('layouts.app')

@section('title', 'Hostel Reports')

@section('content')
@php
    $fmtPct = function ($value) {
        return $value === null ? '—' : number_format((float) $value, 2).'%';
    };
@endphp
<div class="panel">
    <div>
        <h2 class="panel-title">Hostel Reports</h2>
        <p class="panel-subtitle">Read-only aggregates computed live from the existing Hostel, Student and Finance records. There are no report tables and nothing on this screen can be edited. Occupancy follows active hostel allocations, not a second occupancy store. Fee figures use the shared FeeLedger over Finance payments and refunds.</p>
    </div>

    <form method="GET" action="{{ route('hostel-reports.index') }}" class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div>
            <label class="label" for="report">Report</label>
            <select class="input" id="report" name="report">
                @foreach($reports as $key => $label)
                    <option value="{{ $key }}" @selected($report === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="hostel_id">Hostel</label>
            <select class="input" id="hostel_id" name="hostel_id">
                <option value="">All hostels</option>
                @foreach($hostels as $hostel)
                    <option value="{{ $hostel->id }}" @selected((string) ($filters['hostel_id'] ?? '') === (string) $hostel->id)>{{ $hostel->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="hostel_building_id">Building</label>
            <select class="input" id="hostel_building_id" name="hostel_building_id">
                <option value="">All buildings</option>
                @foreach($buildings as $building)
                    <option value="{{ $building->id }}" @selected((string) ($filters['hostel_building_id'] ?? '') === (string) $building->id)>{{ $building->name }} ({{ $building->hostel?->name ?? '—' }})</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="academic_year_id">Academic year</label>
            <select class="input" id="academic_year_id" name="academic_year_id">
                <option value="">All years</option>
                @foreach($years as $year)
                    <option value="{{ $year->id }}" @selected((string) ($filters['academic_year_id'] ?? '') === (string) $year->id)>{{ $year->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="academic_term_id">Academic term</label>
            <select class="input" id="academic_term_id" name="academic_term_id">
                <option value="">All terms</option>
                @foreach($terms as $term)
                    <option value="{{ $term->id }}" @selected((string) ($filters['academic_term_id'] ?? '') === (string) $term->id)>{{ $term->academicYear?->name ?? '—' }} — {{ $term->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="from">Date from</label>
            <input class="input" id="from" name="from" type="date" value="{{ $filters['from'] }}">
        </div>
        <div>
            <label class="label" for="to">Date to</label>
            <input class="input" id="to" name="to" type="date" value="{{ $filters['to'] }}">
        </div>
        <div>
            <label class="label" for="attendance_status">Attendance status</label>
            <select class="input" id="attendance_status" name="attendance_status">
                <option value="">All statuses</option>
                @foreach($statuses as $status)
                    <option value="{{ $status }}" @selected(($filters['attendance_status'] ?? '') === $status)>{{ ucfirst($status) }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex items-end gap-2 lg:col-span-4">
            <button class="button" type="submit">Run report</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('hostel-reports.index') }}">Reset</a>
        </div>
    </form>

    <div class="mt-8">
        @if($report === 'occupancy')
            <h3 class="panel-title mb-1">Occupancy Summary</h3>
            <p class="panel-subtitle mb-4">Occupied beds are distinct beds with a non-cancelled allocation that is active now, or that overlapped the date window when dates are set. Percentage is omitted when there are no beds.</p>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="stat-card"><p class="stat-label">Hostels</p><p class="stat-value">{{ $occupancy['summary']['hostels'] }}</p></div>
                <div class="stat-card"><p class="stat-label">Buildings / Rooms</p><p class="stat-value">{{ $occupancy['summary']['buildings'] }} / {{ $occupancy['summary']['rooms'] }}</p></div>
                <div class="stat-card"><p class="stat-label">Beds</p><p class="stat-value">{{ $occupancy['summary']['beds'] }}</p><p class="stat-hint">{{ $occupancy['summary']['inactive_beds'] }} inactive</p></div>
                <div class="stat-card"><p class="stat-label">Occupied / Vacant</p><p class="stat-value">{{ $occupancy['summary']['occupied'] }} / {{ $occupancy['summary']['vacant'] }}</p><p class="stat-hint">Occupancy {{ $fmtPct($occupancy['summary']['occupancy_percentage']) }}</p></div>
            </div>

            <h3 class="panel-title mb-2 mt-8">Hostel-wise occupancy</h3>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[640px] text-left text-sm">
                    <thead class="border-b bg-slate-50 text-slate-500"><tr>
                        <th class="px-3 py-3">Hostel</th><th class="px-3 py-3">Beds</th><th class="px-3 py-3">Occupied</th><th class="px-3 py-3">Vacant</th><th class="px-3 py-3">Occupancy</th>
                    </tr></thead>
                    <tbody class="divide-y">
                        @forelse($occupancy['hostels'] as $row)
                            <tr>
                                <td class="px-3 py-3 font-medium">{{ $row['hostel']->name }}<span class="block text-xs text-slate-500">{{ $row['hostel']->code }}</span></td>
                                <td class="px-3 py-3">{{ $row['beds'] }}</td>
                                <td class="px-3 py-3">{{ $row['occupied'] }}</td>
                                <td class="px-3 py-3">{{ $row['vacant'] }}</td>
                                <td class="px-3 py-3">{{ $fmtPct($row['occupancy_percentage']) }}</td>
                            </tr>
                        @empty
                            <tr><td class="px-3 py-8 text-center text-slate-500" colspan="5">No hostels found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <h3 class="panel-title mb-2 mt-8">Building occupancy</h3>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[640px] text-left text-sm">
                    <thead class="border-b bg-slate-50 text-slate-500"><tr>
                        <th class="px-3 py-3">Building</th><th class="px-3 py-3">Hostel</th><th class="px-3 py-3">Beds</th><th class="px-3 py-3">Occupied</th><th class="px-3 py-3">Vacant</th><th class="px-3 py-3">Occupancy</th>
                    </tr></thead>
                    <tbody class="divide-y">
                        @forelse($occupancy['buildings'] as $row)
                            <tr>
                                <td class="px-3 py-3 font-medium">{{ $row['building']->name }}</td>
                                <td class="px-3 py-3">{{ $row['building']->hostel?->name ?? '—' }}</td>
                                <td class="px-3 py-3">{{ $row['beds'] }}</td>
                                <td class="px-3 py-3">{{ $row['occupied'] }}</td>
                                <td class="px-3 py-3">{{ $row['vacant'] }}</td>
                                <td class="px-3 py-3">{{ $fmtPct($row['occupancy_percentage']) }}</td>
                            </tr>
                        @empty
                            <tr><td class="px-3 py-8 text-center text-slate-500" colspan="6">No buildings found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <h3 class="panel-title mb-2 mt-8">Room occupancy</h3>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[720px] text-left text-sm">
                    <thead class="border-b bg-slate-50 text-slate-500"><tr>
                        <th class="px-3 py-3">Room</th><th class="px-3 py-3">Building</th><th class="px-3 py-3">Capacity</th><th class="px-3 py-3">Beds</th><th class="px-3 py-3">Occupied</th><th class="px-3 py-3">Vacant</th><th class="px-3 py-3">Occupancy</th>
                    </tr></thead>
                    <tbody class="divide-y">
                        @forelse($occupancy['rooms'] as $row)
                            <tr>
                                <td class="px-3 py-3 font-medium">{{ $row['room']->room_number }}<span class="block text-xs text-slate-500">{{ $row['room']->hostel?->name ?? '—' }}</span></td>
                                <td class="px-3 py-3">{{ $row['room']->building?->name ?? '—' }}</td>
                                <td class="px-3 py-3">{{ $row['capacity'] }}</td>
                                <td class="px-3 py-3">{{ $row['beds'] }}</td>
                                <td class="px-3 py-3">{{ $row['occupied'] }}</td>
                                <td class="px-3 py-3">{{ $row['vacant'] }}</td>
                                <td class="px-3 py-3">{{ $fmtPct($row['occupancy_percentage']) }}</td>
                            </tr>
                        @empty
                            <tr><td class="px-3 py-8 text-center text-slate-500" colspan="7">No rooms found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

        @elseif($report === 'allocations')
            <h3 class="panel-title mb-1">Active Allocation Summary</h3>
            <p class="panel-subtitle mb-4">Counts include every status in the filters. The register lists active allocations only — Hostel Allocation remains the residency source of truth. A date range filters allocation date.</p>
            <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="stat-card"><p class="stat-label">Active</p><p class="stat-value">{{ $allocationReport['counts']['active'] }}</p></div>
                <div class="stat-card"><p class="stat-label">Vacated</p><p class="stat-value">{{ $allocationReport['counts']['vacated'] }}</p></div>
                <div class="stat-card"><p class="stat-label">Cancelled</p><p class="stat-value">{{ $allocationReport['counts']['cancelled'] }}</p></div>
                <div class="stat-card"><p class="stat-label">Total</p><p class="stat-value">{{ $allocationReport['counts']['total'] }}</p></div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[760px] text-left text-sm">
                    <thead class="border-b bg-slate-50 text-slate-500"><tr>
                        <th class="px-3 py-3">Student</th><th class="px-3 py-3">Year</th><th class="px-3 py-3">Hostel</th><th class="px-3 py-3">Building</th><th class="px-3 py-3">Room / Bed</th><th class="px-3 py-3">Allocated</th>
                    </tr></thead>
                    <tbody class="divide-y">
                        @forelse($allocationReport['rows'] as $allocation)
                            <tr>
                                <td class="px-3 py-3 font-medium">{{ $allocation->studentEnrollment?->student?->first_name }} {{ $allocation->studentEnrollment?->student?->last_name }}<span class="block text-xs text-slate-500">{{ $allocation->studentEnrollment?->enrollment_number }}</span></td>
                                <td class="px-3 py-3">{{ $allocation->academicYear?->name ?? '—' }}</td>
                                <td class="px-3 py-3">{{ $allocation->hostel?->name ?? '—' }}</td>
                                <td class="px-3 py-3">{{ $allocation->building?->name ?? '—' }}</td>
                                <td class="px-3 py-3">{{ $allocation->room?->room_number ?? '—' }} / {{ $allocation->bed?->bed_number ?? '—' }}</td>
                                <td class="px-3 py-3">{{ $allocation->allocation_date?->format('d M Y') }}</td>
                            </tr>
                        @empty
                            <tr><td class="px-3 py-8 text-center text-slate-500" colspan="6">No active allocations match these filters.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $allocationReport['rows']->links() }}</div>

        @elseif($report === 'attendance')
            <h3 class="panel-title mb-1">Attendance Summary</h3>
            <p class="panel-subtitle mb-4">Present, absent and leave counts from hostel attendance. Percentage is present ÷ marked, and is omitted when nothing was marked. The status filter narrows the register only, so the summary percentage stays meaningful.</p>
            <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                <div class="stat-card"><p class="stat-label">Present</p><p class="stat-value">{{ $attendanceReport['summary']['present'] }}</p></div>
                <div class="stat-card"><p class="stat-label">Absent</p><p class="stat-value">{{ $attendanceReport['summary']['absent'] }}</p></div>
                <div class="stat-card"><p class="stat-label">Leave</p><p class="stat-value">{{ $attendanceReport['summary']['leave'] }}</p></div>
                <div class="stat-card"><p class="stat-label">Marked</p><p class="stat-value">{{ $attendanceReport['summary']['total'] }}</p></div>
                <div class="stat-card"><p class="stat-label">Attendance</p><p class="stat-value">{{ $fmtPct($attendanceReport['summary']['attendance_percentage']) }}</p></div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[640px] text-left text-sm">
                    <thead class="border-b bg-slate-50 text-slate-500"><tr>
                        <th class="px-3 py-3">Hostel</th><th class="px-3 py-3">Present</th><th class="px-3 py-3">Absent</th><th class="px-3 py-3">Leave</th><th class="px-3 py-3">Marked</th><th class="px-3 py-3">Attendance</th>
                    </tr></thead>
                    <tbody class="divide-y">
                        @forelse($attendanceReport['hostels'] as $row)
                            <tr>
                                <td class="px-3 py-3 font-medium">{{ $row['hostel']->name }}</td>
                                <td class="px-3 py-3">{{ $row['present'] }}</td>
                                <td class="px-3 py-3">{{ $row['absent'] }}</td>
                                <td class="px-3 py-3">{{ $row['leave'] }}</td>
                                <td class="px-3 py-3">{{ $row['total'] }}</td>
                                <td class="px-3 py-3">{{ $fmtPct($row['attendance_percentage']) }}</td>
                            </tr>
                        @empty
                            <tr><td class="px-3 py-8 text-center text-slate-500" colspan="6">No attendance marks match these filters.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <h3 class="panel-title mb-2 mt-8">Attendance register</h3>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[640px] text-left text-sm">
                    <thead class="border-b bg-slate-50 text-slate-500"><tr>
                        <th class="px-3 py-3">Date</th><th class="px-3 py-3">Student</th><th class="px-3 py-3">Hostel</th><th class="px-3 py-3">Status</th>
                    </tr></thead>
                    <tbody class="divide-y">
                        @forelse($attendanceReport['rows'] as $mark)
                            <tr>
                                <td class="px-3 py-3">{{ $mark->attendance_date?->format('d M Y') }}</td>
                                <td class="px-3 py-3 font-medium">{{ $mark->studentEnrollment?->student?->first_name }} {{ $mark->studentEnrollment?->student?->last_name }}</td>
                                <td class="px-3 py-3">{{ $mark->allocation?->hostel?->name ?? '—' }}</td>
                                <td class="px-3 py-3">{{ ucfirst($mark->attendance_status) }}</td>
                            </tr>
                        @empty
                            <tr><td class="px-3 py-8 text-center text-slate-500" colspan="4">No attendance rows match these filters.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $attendanceReport['rows']->links() }}</div>

        @elseif($report === 'fees')
            <h3 class="panel-title mb-1">Hostel Fee Summary</h3>
            <p class="panel-subtitle mb-4">Assigned, paid and outstanding are derived with the shared FeeLedger from existing Finance payments and refunds. Hostel fees have no concessions. This screen does not record payments.</p>
            <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="stat-card"><p class="stat-label">Assigned</p><p class="stat-value">{{ number_format((float) $feeReport['totals']['assigned'], 2) }}</p></div>
                <div class="stat-card"><p class="stat-label">Paid</p><p class="stat-value">{{ number_format((float) $feeReport['totals']['net_collected'], 2) }}</p><p class="stat-hint">Gross {{ number_format((float) $feeReport['totals']['paid'], 2) }} · refunded {{ number_format((float) $feeReport['totals']['refunded'], 2) }}</p></div>
                <div class="stat-card"><p class="stat-label">Outstanding</p><p class="stat-value">{{ number_format((float) $feeReport['totals']['outstanding'], 2) }}</p></div>
                <div class="stat-card"><p class="stat-label">Assignments</p><p class="stat-value">{{ $feeReport['totals']['assignments'] }}</p><p class="stat-hint">{{ $feeReport['totals']['outstanding_assignments'] }} with a balance</p></div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[640px] text-left text-sm">
                    <thead class="border-b bg-slate-50 text-slate-500"><tr>
                        <th class="px-3 py-3">Hostel</th><th class="px-3 py-3">Assignments</th><th class="px-3 py-3">Assigned</th><th class="px-3 py-3">Paid</th><th class="px-3 py-3">Outstanding</th>
                    </tr></thead>
                    <tbody class="divide-y">
                        @forelse($feeReport['hostels'] as $row)
                            <tr>
                                <td class="px-3 py-3 font-medium">{{ $row['hostel']->name }}</td>
                                <td class="px-3 py-3">{{ $row['assignments'] }}</td>
                                <td class="px-3 py-3">{{ number_format((float) $row['assigned'], 2) }}</td>
                                <td class="px-3 py-3">{{ number_format((float) $row['net_collected'], 2) }}</td>
                                <td class="px-3 py-3">{{ number_format((float) $row['outstanding'], 2) }}</td>
                            </tr>
                        @empty
                            <tr><td class="px-3 py-8 text-center text-slate-500" colspan="5">No hostel fee assignments match these filters.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <h3 class="panel-title mb-2 mt-8">Assignment ledger</h3>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[720px] text-left text-sm">
                    <thead class="border-b bg-slate-50 text-slate-500"><tr>
                        <th class="px-3 py-3">Student</th><th class="px-3 py-3">Hostel</th><th class="px-3 py-3">Assigned</th><th class="px-3 py-3">Paid</th><th class="px-3 py-3">Outstanding</th><th class="px-3 py-3">Status</th>
                    </tr></thead>
                    <tbody class="divide-y">
                        @forelse($feeReport['rows'] as $assignment)
                            @php($summary = $assignment->ledger ?? null)
                            <tr>
                                <td class="px-3 py-3 font-medium">{{ $assignment->hostelAllocation?->studentEnrollment?->student?->first_name }} {{ $assignment->hostelAllocation?->studentEnrollment?->student?->last_name }}</td>
                                <td class="px-3 py-3">{{ $assignment->hostelAllocation?->hostel?->name ?? '—' }}</td>
                                <td class="px-3 py-3">{{ number_format((float) ($summary['assigned'] ?? $assignment->assigned_amount), 2) }}</td>
                                <td class="px-3 py-3">{{ number_format((float) ($summary['net_collected'] ?? 0), 2) }}</td>
                                <td class="px-3 py-3 font-semibold">{{ number_format((float) ($summary['outstanding'] ?? 0), 2) }}</td>
                                <td class="px-3 py-3">{{ ucfirst($summary['status'] ?? $assignment->status) }}</td>
                            </tr>
                        @empty
                            <tr><td class="px-3 py-8 text-center text-slate-500" colspan="6">No hostel fee assignments match these filters.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $feeReport['rows']->links() }}</div>
        @endif
    </div>
</div>
@endsection
