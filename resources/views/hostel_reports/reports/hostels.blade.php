<p class="panel-subtitle">Live hostel and building/block masters from the active college. Inactive rows remain visible with their stored status; soft-deleted rows are excluded.</p>

<h4 class="mt-6 text-sm font-semibold text-slate-800">Hostels</h4>
<div class="mt-3 overflow-x-auto">
    <table class="w-full min-w-[820px] text-left text-sm">
        <thead class="border-b bg-slate-50 text-slate-500"><tr>
            <th class="px-3 py-3">Hostel</th><th class="px-3 py-3">Type / gender</th><th class="px-3 py-3">Status</th><th class="px-3 py-3">Buildings / blocks</th><th class="px-3 py-3">Rooms</th><th class="px-3 py-3">Beds</th>
        </tr></thead>
        <tbody class="divide-y">
            @forelse($hostels as $hostel)
                <tr>
                    <td class="px-3 py-3 font-medium">{{ $hostel->name }}<span class="block text-xs text-slate-500">{{ $hostel->code }}</span></td>
                    <td class="px-3 py-3">{{ ucfirst($hostel->hostel_type) }} / {{ ucfirst($hostel->gender) }}</td>
                    <td class="px-3 py-3">{{ ucfirst($hostel->status) }}</td>
                    <td class="px-3 py-3">{{ $hostel->buildings_count }}</td>
                    <td class="px-3 py-3">{{ $hostel->rooms_count }}</td>
                    <td class="px-3 py-3">{{ $hostel->beds_count }}</td>
                </tr>
            @empty
                <tr><td class="px-3 py-8 text-center text-slate-500" colspan="6">No hostels match these filters.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<div class="no-print mt-3 flex flex-wrap items-center justify-between gap-3 text-xs text-slate-500">
    <span>Showing {{ $hostels->firstItem() ?? 0 }}–{{ $hostels->lastItem() ?? 0 }} of {{ $hostels->total() }} hostels.</span>
    {{ $hostels->links() }}
</div>

<h4 class="mt-8 text-sm font-semibold text-slate-800">Buildings / blocks</h4>
<div class="mt-3 overflow-x-auto">
    <table class="w-full min-w-[820px] text-left text-sm">
        <thead class="border-b bg-slate-50 text-slate-500"><tr>
            <th class="px-3 py-3">Building / block</th><th class="px-3 py-3">Hostel</th><th class="px-3 py-3">Floors</th><th class="px-3 py-3">Status</th><th class="px-3 py-3">Rooms</th><th class="px-3 py-3">Beds</th>
        </tr></thead>
        <tbody class="divide-y">
            @forelse($buildings as $building)
                <tr>
                    <td class="px-3 py-3 font-medium">{{ $building->name }}<span class="block text-xs text-slate-500">{{ $building->code }}</span></td>
                    <td class="px-3 py-3">{{ $building->hostel?->name ?? '—' }}</td>
                    <td class="px-3 py-3">{{ $building->floors ?? '—' }}</td>
                    <td class="px-3 py-3">{{ ucfirst($building->status) }}</td>
                    <td class="px-3 py-3">{{ $building->rooms_count }}</td>
                    <td class="px-3 py-3">{{ $building->beds_count }}</td>
                </tr>
            @empty
                <tr><td class="px-3 py-8 text-center text-slate-500" colspan="6">No buildings / blocks match these filters.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<div class="no-print mt-3 flex flex-wrap items-center justify-between gap-3 text-xs text-slate-500">
    <span>Showing {{ $buildings->firstItem() ?? 0 }}–{{ $buildings->lastItem() ?? 0 }} of {{ $buildings->total() }} buildings / blocks.</span>
    {{ $buildings->links() }}
</div>
