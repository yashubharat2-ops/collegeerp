@php
    $attendance = $summary['attendance'];
    $fees = $summary['fees'];
@endphp

<p class="panel-subtitle">
    The hostel position of the active college, taken from the existing Hostel Summary: hostels, buildings, rooms and beds
    from the Hostel dashboard counters, allocation and attendance records, and the hostel fee balances recorded through
    the shared Finance ledger.
</p>

<div class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
    <div class="stat-card"><p class="stat-label">Hostels</p><p class="stat-value">{{ number_format($summary['total_hostels']) }}</p><p class="stat-hint">{{ number_format($summary['total_buildings']) }} buildings / blocks</p></div>
    <div class="stat-card"><p class="stat-label">Rooms</p><p class="stat-value">{{ number_format($summary['total_rooms']) }}</p></div>
    <div class="stat-card"><p class="stat-label">Beds</p><p class="stat-value">{{ number_format($summary['total_beds']) }}</p><p class="stat-hint">{{ number_format($summary['inactive_beds']) }} inactive</p></div>
    <div class="stat-card"><p class="stat-label">Occupied beds</p><p class="stat-value">{{ number_format($summary['occupied_beds']) }}</p><p class="stat-hint">{{ number_format($summary['available_beds']) }} available</p></div>
    <div class="stat-card"><p class="stat-label">Active allocations</p><p class="stat-value">{{ number_format($summary['active_allocations']) }}</p></div>
    <div class="stat-card"><p class="stat-label">Vacated students</p><p class="stat-value">{{ number_format($summary['vacated_students']) }}</p></div>
</div>

<h4 class="mt-8 text-sm font-semibold text-slate-800">Hostel attendance</h4>
<div class="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
    <div class="stat-card"><p class="stat-label">Present</p><p class="stat-value">{{ number_format($attendance['present']) }}</p></div>
    <div class="stat-card"><p class="stat-label">Absent</p><p class="stat-value">{{ number_format($attendance['absent']) }}</p></div>
    <div class="stat-card"><p class="stat-label">Leave</p><p class="stat-value">{{ number_format($attendance['leave']) }}</p></div>
    <div class="stat-card"><p class="stat-label">Marked</p><p class="stat-value">{{ number_format($attendance['total']) }}</p></div>
    <div class="stat-card">
        <p class="stat-label">Present rate</p>
        <p class="stat-value">{{ $attendance['attendance_percentage'] === null ? '—' : number_format((float) $attendance['attendance_percentage'], 2).'%' }}</p>
    </div>
</div>

<h4 class="mt-8 text-sm font-semibold text-slate-800">Hostel fees</h4>
@if($fees['assignments'] > 0)
    <div class="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
        <div class="stat-card"><p class="stat-label">Payable charges</p><p class="stat-value">{{ number_format($fees['assignments']) }}</p></div>
        <div class="stat-card"><p class="stat-label">Assigned</p><p class="stat-value">{{ number_format((float) $fees['assigned'], 2) }}</p></div>
        <div class="stat-card"><p class="stat-label">Net collected</p><p class="stat-value">{{ number_format((float) $fees['net_collected'], 2) }}</p></div>
        <div class="stat-card"><p class="stat-label">Refunded</p><p class="stat-value">{{ number_format((float) $fees['refunded'], 2) }}</p></div>
        <div class="stat-card"><p class="stat-label">Outstanding</p><p class="stat-value">{{ number_format((float) $fees['outstanding'], 2) }}</p></div>
    </div>
@else
    <p class="mt-3 rounded-lg bg-slate-50 px-4 py-4 text-sm text-slate-500">No payable hostel fee charges are recorded.</p>
@endif
