<p class="panel-subtitle">Every driver of the active college exactly as the Drivers screen stores it — the referenced staff record's name and contact details, license number / type / expiry, joining date and status. Read-only: driver records are maintained on the Drivers screen.</p>
<div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
    <div class="rounded-xl bg-indigo-50 p-4"><p class="text-sm text-indigo-700">Drivers</p><p class="text-2xl font-bold text-indigo-900">{{ number_format($totals['total']) }}</p></div>
    <div class="rounded-xl bg-emerald-50 p-4"><p class="text-sm text-emerald-700">Active</p><p class="text-2xl font-bold text-emerald-900">{{ number_format($totals['active']) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Inactive</p><p class="text-2xl font-bold text-slate-900">{{ number_format($totals['inactive']) }}</p></div>
</div>
<div class="mt-5 overflow-x-auto">
    <table class="w-full text-left text-sm">
        <thead><tr class="border-b text-slate-500">
            <th class="py-3 pr-4">Driver</th><th class="pr-4">Employee code</th><th class="pr-4">Contact</th>
            <th class="pr-4">License no.</th><th class="pr-4">License type</th><th class="pr-4">License expiry</th>
            <th class="pr-4">Joined</th><th class="pr-4">Assigned vehicle</th><th class="text-right">Status</th>
        </tr></thead>
        <tbody>
        @forelse($rows as $driver)
            <tr class="border-b align-top">
                <td class="py-3 pr-4 font-medium">{{ $driver->faculty?->full_name ?? 'Archived staff' }}</td>
                <td class="pr-4">{{ $driver->faculty?->employee_code ?? '—' }}</td>
                <td class="pr-4">
                    @if($driver->faculty && ($driver->faculty->email || $driver->faculty->phone))
                        {{ $driver->faculty->email ?: '—' }}{{ $driver->faculty->phone ? ' · '.$driver->faculty->phone : '' }}
                    @else
                        —
                    @endif
                </td>
                <td class="pr-4">{{ $driver->license_number }}</td>
                <td class="pr-4">{{ $driver->license_type ?: '—' }}</td>
                <td class="pr-4">{{ $driver->license_expiry ? \Illuminate\Support\Carbon::parse($driver->license_expiry)->format('d M Y') : '—' }}</td>
                <td class="pr-4">{{ $driver->joining_date ? \Illuminate\Support\Carbon::parse($driver->joining_date)->format('d M Y') : '—' }}</td>
                <td class="pr-4">—</td>
                <td class="text-right">{{ ucfirst($driver->status) }}</td>
            </tr>
        @empty
            <tr><td colspan="9" class="py-6 text-slate-500">No drivers match these filters.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<p class="mt-3 text-xs text-slate-500">The existing Transport schema keeps drivers, vehicles and routes as independent masters — it records no driver → vehicle assignment — so the Assigned vehicle column stays “—” and the report never invents a pairing.</p>
@include('transport.reports._pagination', ['subject' => 'drivers'])
