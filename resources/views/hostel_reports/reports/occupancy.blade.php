<p class="panel-subtitle">Occupied beds are distinct beds with a non-cancelled allocation active now, or allocations overlapping the selected date / academic window. Available excludes beds marked inactive. Room and bed rows are deterministically paginated.</p>

<h4 class="mt-5 text-sm font-semibold text-slate-800">Occupancy Summary</h4>
<div class="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
    <div class="stat-card"><p class="stat-label">Total beds</p><p class="stat-value">{{ $summary['beds'] }}</p></div>
    <div class="stat-card"><p class="stat-label">Occupied beds</p><p class="stat-value">{{ $summary['occupied'] }}</p></div>
    <div class="stat-card"><p class="stat-label">Available beds</p><p class="stat-value">{{ $summary['available'] }}</p></div>
    <div class="stat-card"><p class="stat-label">Inactive beds</p><p class="stat-value">{{ $summary['inactive_beds'] }}</p></div>
    <div class="stat-card"><p class="stat-label">Occupancy</p><p class="stat-value">{{ $summary['occupancy_percentage'] === null ? '—' : number_format((float) $summary['occupancy_percentage'], 2).'%' }}</p></div>
</div>

<h4 class="mt-8 text-sm font-semibold text-slate-800">Room occupancy</h4>
<div class="mt-3 overflow-x-auto">
    <table class="w-full min-w-[1000px] text-left text-sm">
        <thead class="border-b bg-slate-50 text-slate-500"><tr>
            <th class="px-3 py-3">Hostel</th><th class="px-3 py-3">Building / block</th><th class="px-3 py-3">Room</th><th class="px-3 py-3">Room status</th><th class="px-3 py-3">Capacity</th><th class="px-3 py-3">Total beds</th><th class="px-3 py-3">Occupied</th><th class="px-3 py-3">Available</th><th class="px-3 py-3">Inactive</th><th class="px-3 py-3">Occupancy status</th>
        </tr></thead>
        <tbody class="divide-y">
            @forelse($rooms as $room)
                <tr>
                    <td class="px-3 py-3">{{ $room->hostel?->name ?? '—' }}</td>
                    <td class="px-3 py-3">{{ $room->building?->name ?? '—' }}</td>
                    <td class="px-3 py-3 font-medium">{{ $room->room_number }}</td>
                    <td class="px-3 py-3">{{ ucfirst($room->status) }}</td>
                    <td class="px-3 py-3">{{ $room->capacity }}</td>
                    <td class="px-3 py-3">{{ $room->total_beds_count }}</td>
                    <td class="px-3 py-3">{{ $room->occupied_beds_count }}</td>
                    <td class="px-3 py-3">{{ $room->available_beds_count }}</td>
                    <td class="px-3 py-3">{{ $room->inactive_beds_count }}</td>
                    <td class="px-3 py-3">{{ $room->occupancy_status }}</td>
                </tr>
            @empty
                <tr><td class="px-3 py-8 text-center text-slate-500" colspan="10">No rooms match these filters.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<div class="no-print mt-3 flex flex-wrap items-center justify-between gap-3 text-xs text-slate-500">
    <span>Showing {{ $rooms->firstItem() ?? 0 }}–{{ $rooms->lastItem() ?? 0 }} of {{ $rooms->total() }} rooms.</span>
    {{ $rooms->links() }}
</div>

<h4 class="mt-8 text-sm font-semibold text-slate-800">Bed occupancy</h4>
<div class="mt-3 overflow-x-auto">
    <table class="w-full min-w-[900px] text-left text-sm">
        <thead class="border-b bg-slate-50 text-slate-500"><tr>
            <th class="px-3 py-3">Hostel</th><th class="px-3 py-3">Building / block</th><th class="px-3 py-3">Room</th><th class="px-3 py-3">Bed</th><th class="px-3 py-3">Bed status</th><th class="px-3 py-3">Occupancy status</th><th class="px-3 py-3">Resident</th>
        </tr></thead>
        <tbody class="divide-y">
            @forelse($beds as $bed)
                @php($student = $bed->occupancyAllocation?->studentEnrollment?->student)
                <tr>
                    <td class="px-3 py-3">{{ $bed->hostel?->name ?? '—' }}</td>
                    <td class="px-3 py-3">{{ $bed->building?->name ?? '—' }}</td>
                    <td class="px-3 py-3">{{ $bed->room?->room_number ?? '—' }}</td>
                    <td class="px-3 py-3 font-medium">{{ $bed->bed_number }}</td>
                    <td class="px-3 py-3">{{ ucfirst($bed->status) }}</td>
                    <td class="px-3 py-3">{{ $bed->occupancy_status }}</td>
                    <td class="px-3 py-3">{{ $student?->student_number ?? '—' }}{{ $student ? ' — '.$student->fullName() : '' }}</td>
                </tr>
            @empty
                <tr><td class="px-3 py-8 text-center text-slate-500" colspan="7">No beds match these filters.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<div class="no-print mt-3 flex flex-wrap items-center justify-between gap-3 text-xs text-slate-500">
    <span>Showing {{ $beds->firstItem() ?? 0 }}–{{ $beds->lastItem() ?? 0 }} of {{ $beds->total() }} beds.</span>
    {{ $beds->links() }}
</div>
