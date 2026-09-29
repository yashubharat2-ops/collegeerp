<p class="panel-subtitle">Maintenance type, status, schedule, completion date and cost are the values stored on the existing maintenance work orders.</p>
<div class="mt-4 overflow-x-auto">
    <table class="w-full min-w-[1200px] text-left text-sm">
        <thead class="border-b bg-slate-50 text-slate-500"><tr>
            <th class="px-3 py-3">Asset</th><th class="px-3 py-3">Category</th><th class="px-3 py-3">Maintenance</th><th class="px-3 py-3">Type</th><th class="px-3 py-3">Status</th><th class="px-3 py-3">Scheduled</th><th class="px-3 py-3">Completed</th><th class="px-3 py-3 text-right">Stored cost</th><th class="px-3 py-3">Vendor / performed by</th><th class="px-3 py-3">Description</th>
        </tr></thead>
        <tbody class="divide-y">
            @forelse($maintenances as $maintenance)
                <tr>
                    <td class="px-3 py-3 font-medium">{{ $maintenance->item?->name ?? 'Unknown asset' }}{{ $maintenance->item?->trashed() ? ' (archived)' : '' }}<span class="block font-mono text-xs text-slate-500">{{ $maintenance->item?->serial_number ?? $maintenance->item?->code ?? '—' }}</span></td>
                    <td class="px-3 py-3">{{ $maintenance->item?->category?->name ?? '—' }}</td>
                    <td class="px-3 py-3">{{ $maintenance->title }}</td>
                    <td class="px-3 py-3">{{ ucfirst($maintenance->maintenance_type) }}</td>
                    <td class="px-3 py-3">{{ ucfirst(str_replace('_', ' ', $maintenance->status)) }}</td>
                    <td class="px-3 py-3 whitespace-nowrap">{{ $maintenance->scheduled_on?->format('d M Y') ?? '—' }}</td>
                    <td class="px-3 py-3 whitespace-nowrap">{{ $maintenance->completed_on?->format('d M Y') ?? '—' }}</td>
                    <td class="px-3 py-3 text-right font-mono">{{ $maintenance->cost ?? '—' }}</td>
                    <td class="px-3 py-3">{{ $maintenance->vendor?->name ?? '—' }}<span class="block text-xs text-slate-500">{{ $maintenance->performed_by ?? '—' }}</span></td>
                    <td class="px-3 py-3">{{ $maintenance->description ?? '—' }}</td>
                </tr>
            @empty
                <tr><td class="px-3 py-8 text-center text-slate-500" colspan="10">No asset maintenance records match these filters.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<div class="no-print mt-4 flex flex-wrap items-center justify-between gap-3 text-xs text-slate-500">
    <span>Showing {{ $maintenances->firstItem() ?? 0 }}–{{ $maintenances->lastItem() ?? 0 }} of {{ $maintenances->total() }} maintenance records.</span>
    {{ $maintenances->links() }}
</div>
