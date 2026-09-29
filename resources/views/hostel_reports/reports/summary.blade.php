<p class="panel-subtitle">College-wide live metrics. Hostel and bed totals reuse HostelDashboardService; allocation status, attendance records and fee balances come from their existing tenant-scoped Hostel / Finance sources.</p>

<div class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
    <div class="stat-card"><p class="stat-label">Total hostels</p><p class="stat-value">{{ $summary['total_hostels'] }}</p></div>
    <div class="stat-card"><p class="stat-label">Total buildings / blocks</p><p class="stat-value">{{ $summary['total_buildings'] }}</p></div>
    <div class="stat-card"><p class="stat-label">Total rooms</p><p class="stat-value">{{ $summary['total_rooms'] }}</p></div>
    <div class="stat-card"><p class="stat-label">Total beds</p><p class="stat-value">{{ $summary['total_beds'] }}</p></div>
    <div class="stat-card"><p class="stat-label">Occupied beds</p><p class="stat-value">{{ $summary['occupied_beds'] }}</p></div>
    <div class="stat-card"><p class="stat-label">Available beds</p><p class="stat-value">{{ $summary['available_beds'] }}</p><p class="stat-hint">{{ $summary['inactive_beds'] }} inactive</p></div>
    <div class="stat-card"><p class="stat-label">Active allocations</p><p class="stat-value">{{ $summary['active_allocations'] }}</p></div>
    <div class="stat-card"><p class="stat-label">Vacated students</p><p class="stat-value">{{ $summary['vacated_students'] }}</p></div>
</div>

<h4 class="mt-8 text-sm font-semibold text-slate-800">Attendance summary</h4>
<div class="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
    <div class="stat-card"><p class="stat-label">Present</p><p class="stat-value">{{ $summary['attendance']['present'] }}</p></div>
    <div class="stat-card"><p class="stat-label">Absent</p><p class="stat-value">{{ $summary['attendance']['absent'] }}</p></div>
    <div class="stat-card"><p class="stat-label">Leave</p><p class="stat-value">{{ $summary['attendance']['leave'] }}</p></div>
    <div class="stat-card"><p class="stat-label">Marked</p><p class="stat-value">{{ $summary['attendance']['total'] }}</p></div>
    <div class="stat-card"><p class="stat-label">Present rate</p><p class="stat-value">{{ $summary['attendance']['attendance_percentage'] === null ? '—' : number_format((float) $summary['attendance']['attendance_percentage'], 2).'%' }}</p></div>
</div>

<h4 class="mt-8 text-sm font-semibold text-slate-800">Hostel fee summary</h4>
@if($summary['fees']['assignments'] > 0)
    <div class="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
        <div class="stat-card"><p class="stat-label">Payable assignments</p><p class="stat-value">{{ $summary['fees']['assignments'] }}</p></div>
        <div class="stat-card"><p class="stat-label">Assigned</p><p class="stat-value">{{ number_format((float) $summary['fees']['assigned'], 2) }}</p></div>
        <div class="stat-card"><p class="stat-label">Paid</p><p class="stat-value">{{ number_format((float) $summary['fees']['net_collected'], 2) }}</p></div>
        <div class="stat-card"><p class="stat-label">Refunded</p><p class="stat-value">{{ number_format((float) $summary['fees']['refunded'], 2) }}</p></div>
        <div class="stat-card"><p class="stat-label">Outstanding / due</p><p class="stat-value">{{ number_format((float) $summary['fees']['outstanding'], 2) }}</p></div>
    </div>
@else
    <p class="mt-3 rounded-lg bg-slate-50 px-4 py-4 text-sm text-slate-500">No payable hostel fee assignments are recorded.</p>
@endif
