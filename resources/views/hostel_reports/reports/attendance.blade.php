<p class="panel-subtitle">Attendance marks are read from existing Hostel Attendance rows and their allocations. The status filter narrows the register only; the summary remains complete for the selected date and resident filters.</p>

<h4 class="mt-5 text-sm font-semibold text-slate-800">Attendance Summary</h4>
<div class="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
    <div class="stat-card"><p class="stat-label">Present</p><p class="stat-value">{{ $attendanceReport['summary']['present'] }}</p></div>
    <div class="stat-card"><p class="stat-label">Absent</p><p class="stat-value">{{ $attendanceReport['summary']['absent'] }}</p></div>
    <div class="stat-card"><p class="stat-label">Leave</p><p class="stat-value">{{ $attendanceReport['summary']['leave'] }}</p></div>
    <div class="stat-card"><p class="stat-label">Marked</p><p class="stat-value">{{ $attendanceReport['summary']['total'] }}</p></div>
    <div class="stat-card"><p class="stat-label">Present rate</p><p class="stat-value">{{ $attendanceReport['summary']['attendance_percentage'] === null ? '—' : number_format((float) $attendanceReport['summary']['attendance_percentage'], 2).'%' }}</p></div>
</div>

<h4 class="mt-8 text-sm font-semibold text-slate-800">Attendance by hostel</h4>
<div class="mt-3 overflow-x-auto">
    <table class="w-full min-w-[720px] text-left text-sm">
        <thead class="border-b bg-slate-50 text-slate-500"><tr>
            <th class="px-3 py-3">Hostel</th><th class="px-3 py-3">Present</th><th class="px-3 py-3">Absent</th><th class="px-3 py-3">Leave</th><th class="px-3 py-3">Marked</th><th class="px-3 py-3">Present rate</th>
        </tr></thead>
        <tbody class="divide-y">
            @forelse($attendanceReport['hostels'] as $row)
                <tr>
                    <td class="px-3 py-3 font-medium">{{ $row['hostel']->name }}</td>
                    <td class="px-3 py-3">{{ $row['present'] }}</td>
                    <td class="px-3 py-3">{{ $row['absent'] }}</td>
                    <td class="px-3 py-3">{{ $row['leave'] }}</td>
                    <td class="px-3 py-3">{{ $row['total'] }}</td>
                    <td class="px-3 py-3">{{ $row['attendance_percentage'] === null ? '—' : number_format((float) $row['attendance_percentage'], 2).'%' }}</td>
                </tr>
            @empty
                <tr><td class="px-3 py-8 text-center text-slate-500" colspan="6">No attendance marks match these filters.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<h4 class="mt-8 text-sm font-semibold text-slate-800">Attendance register</h4>
<div class="mt-3 overflow-x-auto">
    <table class="w-full min-w-[1160px] text-left text-sm">
        <thead class="border-b bg-slate-50 text-slate-500"><tr>
            <th class="px-3 py-3">Date</th><th class="px-3 py-3">Resident</th><th class="px-3 py-3">Class / section</th><th class="px-3 py-3">Hostel</th><th class="px-3 py-3">Building / block</th><th class="px-3 py-3">Room / bed</th><th class="px-3 py-3">Attendance status</th><th class="px-3 py-3">Remarks</th>
        </tr></thead>
        <tbody class="divide-y">
            @forelse($attendanceReport['rows'] as $mark)
                @php($student = $mark->studentEnrollment?->student)
                @php($allocation = $mark->allocation)
                <tr>
                    <td class="px-3 py-3 whitespace-nowrap">{{ $mark->attendance_date?->format('d M Y') ?? '—' }}</td>
                    <td class="px-3 py-3 font-medium">{{ $student?->student_number ?? '—' }}<span class="block">{{ $student?->fullName() ?? 'Unknown student' }}</span><span class="block text-xs text-slate-500">{{ $mark->studentEnrollment?->enrollment_number ?? '—' }}</span></td>
                    <td class="px-3 py-3">{{ $mark->studentEnrollment?->program?->name ?? '—' }} / {{ $mark->studentEnrollment?->section?->name ?? '—' }}</td>
                    <td class="px-3 py-3">{{ $allocation?->hostel?->name ?? '—' }}</td>
                    <td class="px-3 py-3">{{ $allocation?->building?->name ?? '—' }}</td>
                    <td class="px-3 py-3">{{ $allocation?->room?->room_number ?? '—' }} / {{ $allocation?->bed?->bed_number ?? '—' }}</td>
                    <td class="px-3 py-3">{{ ucfirst($mark->attendance_status) }}</td>
                    <td class="px-3 py-3">{{ $mark->remarks ?: '—' }}</td>
                </tr>
            @empty
                <tr><td class="px-3 py-8 text-center text-slate-500" colspan="8">No attendance rows match these filters.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<div class="no-print mt-4 flex flex-wrap items-center justify-between gap-3 text-xs text-slate-500">
    <span>Showing {{ $attendanceReport['rows']->firstItem() ?? 0 }}–{{ $attendanceReport['rows']->lastItem() ?? 0 }} of {{ $attendanceReport['rows']->total() }} attendance marks.</span>
    {{ $attendanceReport['rows']->links() }}
</div>
