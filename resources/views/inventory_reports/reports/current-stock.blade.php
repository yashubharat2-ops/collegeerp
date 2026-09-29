<p class="panel-subtitle">Balances are derived from the existing stock ledger. Item quantity caches are intentionally not used.</p>
<div class="mt-4 overflow-x-auto">
    <table class="w-full min-w-[900px] text-left text-sm">
        <thead class="border-b bg-slate-50 text-slate-500"><tr>
            <th class="px-3 py-3">Item / asset</th><th class="px-3 py-3">Category</th><th class="px-3 py-3">Type</th><th class="px-3 py-3">Current quantity</th><th class="px-3 py-3">Unit</th><th class="px-3 py-3">Brand / model</th><th class="px-3 py-3">Serial</th><th class="px-3 py-3">Status</th>
        </tr></thead>
        <tbody class="divide-y">
            @forelse($items as $item)
                <tr>
                    <td class="px-3 py-3 font-medium">{{ $item->name }}<span class="block font-mono text-xs text-slate-500">{{ $item->code }}</span></td>
                    <td class="px-3 py-3">{{ $item->category?->name ?? '—' }}</td>
                    <td class="px-3 py-3">{{ ucfirst($item->item_type) }}</td>
                    <td class="px-3 py-3 font-mono">{{ $item->on_hand }}</td>
                    <td class="px-3 py-3">{{ $item->unit }}</td>
                    <td class="px-3 py-3">{{ trim(($item->brand ?? '').' '.($item->model ?? '')) ?: '—' }}</td>
                    <td class="px-3 py-3">{{ $item->serial_number ?? '—' }}</td>
                    <td class="px-3 py-3">{{ ucfirst($item->status) }}</td>
                </tr>
            @empty
                <tr><td class="px-3 py-8 text-center text-slate-500" colspan="8">No inventory items match these filters.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<div class="no-print mt-4 flex flex-wrap items-center justify-between gap-3 text-xs text-slate-500">
    <span>Showing {{ $items->firstItem() ?? 0 }}–{{ $items->lastItem() ?? 0 }} of {{ $items->total() }} items.</span>
    {{ $items->links() }}
</div>
