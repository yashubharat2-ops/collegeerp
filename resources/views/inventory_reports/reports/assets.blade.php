<p class="panel-subtitle">Assets are the existing inventory_items rows with item_type = asset. The holder comes from the current active assignment. The existing schema has no physical-location field.</p>
<div class="mt-4 overflow-x-auto">
    <table class="w-full min-w-[1120px] text-left text-sm">
        <thead class="border-b bg-slate-50 text-slate-500"><tr>
            <th class="px-3 py-3">Asset / code</th><th class="px-3 py-3">Category</th><th class="px-3 py-3">Serial</th><th class="px-3 py-3">Brand / model</th><th class="px-3 py-3">Status</th><th class="px-3 py-3">Current holder</th><th class="px-3 py-3">Last return</th><th class="px-3 py-3">Last completed service</th><th class="px-3 py-3">Open maintenance</th>
        </tr></thead>
        <tbody class="divide-y">
            @forelse($assets as $asset)
                <tr>
                    <td class="px-3 py-3 font-medium">{{ $asset->name }}<span class="block font-mono text-xs text-slate-500">{{ $asset->code }}</span></td>
                    <td class="px-3 py-3">{{ $asset->category?->name ?? '—' }}</td>
                    <td class="px-3 py-3">{{ $asset->serial_number ?? '—' }}</td>
                    <td class="px-3 py-3">{{ trim(($asset->brand ?? '').' '.($asset->model ?? '')) ?: '—' }}</td>
                    <td class="px-3 py-3">{{ ucfirst($asset->status) }}</td>
                    <td class="px-3 py-3">{{ $asset->activeAssignment?->assigneeName() ?? 'Unassigned' }}</td>
                    <td class="px-3 py-3 whitespace-nowrap">{{ $asset->last_returned_on ? \Illuminate\Support\Carbon::parse($asset->last_returned_on)->format('d M Y') : '—' }}</td>
                    <td class="px-3 py-3 whitespace-nowrap">{{ $asset->last_service_on ? \Illuminate\Support\Carbon::parse($asset->last_service_on)->format('d M Y') : '—' }}</td>
                    <td class="px-3 py-3">{{ $asset->open_maintenance_count }}</td>
                </tr>
            @empty
                <tr><td class="px-3 py-8 text-center text-slate-500" colspan="9">No assets match these filters.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<div class="no-print mt-4 flex flex-wrap items-center justify-between gap-3 text-xs text-slate-500">
    <span>Showing {{ $assets->firstItem() ?? 0 }}–{{ $assets->lastItem() ?? 0 }} of {{ $assets->total() }} assets.</span>
    {{ $assets->links() }}
</div>
