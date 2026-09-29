<p class="panel-subtitle">Every vehicle of the active college exactly as the Vehicles screen stores it — registration, type, make / model, seating capacity, purchase and expiry dates and fleet status. Read-only: fleet details are maintained on the Vehicles screen.</p>
<div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
    <div class="rounded-xl bg-indigo-50 p-4"><p class="text-sm text-indigo-700">Vehicles</p><p class="text-2xl font-bold text-indigo-900">{{ number_format($totals['total']) }}</p></div>
    <div class="rounded-xl bg-emerald-50 p-4"><p class="text-sm text-emerald-700">Active</p><p class="text-2xl font-bold text-emerald-900">{{ number_format($totals['active']) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Inactive</p><p class="text-2xl font-bold text-slate-900">{{ number_format($totals['inactive']) }}</p></div>
    <div class="rounded-xl bg-amber-50 p-4"><p class="text-sm text-amber-700">In maintenance</p><p class="text-2xl font-bold text-amber-900">{{ number_format($totals['maintenance']) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Retired</p><p class="text-2xl font-bold text-slate-900">{{ number_format($totals['retired']) }}</p></div>
</div>
<div class="mt-5 overflow-x-auto">
    <table class="w-full text-left text-sm">
        <thead><tr class="border-b text-slate-500">
            <th class="py-3 pr-4">Registration</th><th class="pr-4">Type</th><th class="pr-4">Make / Model</th>
            <th class="pr-4 text-right">Capacity</th><th class="pr-4">Purchased</th><th class="pr-4">Insurance expiry</th>
            <th class="pr-4">Fitness expiry</th><th class="pr-4">Permit expiry</th><th class="text-right">Status</th>
        </tr></thead>
        <tbody>
        @forelse($rows as $vehicle)
            <tr class="border-b align-top">
                <td class="py-3 pr-4 font-medium">{{ $vehicle->registration_number }}</td>
                <td class="pr-4">{{ $vehicle->vehicle_type ?: '—' }}</td>
                <td class="pr-4">{{ trim(($vehicle->make ?? '').' '.($vehicle->model ?? '')) !== '' ? trim(($vehicle->make ?? '').' '.($vehicle->model ?? '')) : '—' }}</td>
                <td class="pr-4 text-right">{{ number_format((int) $vehicle->seating_capacity) }}</td>
                <td class="pr-4">{{ $vehicle->purchase_date?->format('d M Y') ?? '—' }}</td>
                <td class="pr-4">{{ $vehicle->insurance_expiry?->format('d M Y') ?? '—' }}</td>
                <td class="pr-4">{{ $vehicle->fitness_expiry?->format('d M Y') ?? '—' }}</td>
                <td class="pr-4">{{ $vehicle->permit_expiry?->format('d M Y') ?? '—' }}</td>
                <td class="text-right">{{ ucfirst($vehicle->status) }}</td>
            </tr>
        @empty
            <tr><td colspan="9" class="py-6 text-slate-500">No vehicles match these filters.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@include('transport.reports._pagination', ['subject' => 'vehicles'])
