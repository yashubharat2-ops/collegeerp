<p class="panel-subtitle">Active consumables at or below the existing threshold of {{ $filters['threshold'] }}. The same ledger-based balance source powers Current Stock.</p>
<div class="mt-4 overflow-x-auto">
    <table class="w-full min-w-[760px] text-left text-sm">
        <thead class="border-b bg-slate-50 text-slate-500"><tr>
            <th class="px-3 py-3">Item</th><th class="px-3 py-3">Category</th><th class="px-3 py-3">Current stock</th><th class="px-3 py-3">Threshold</th><th class="px-3 py-3">Unit</th><th class="px-3 py-3">Status</th>
        </tr></thead>
        <tbody class="divide-y">
            @forelse($items as $item)
                <tr>
                    <td class="px-3 py-3 font-medium">{{ $item->name }}<span class="block font-mono text-xs text-slate-500">{{ $item->code }}</span></td>
                    <td class="px-3 py-3">{{ $item->category?->name ?? '—' }}</td>
                    <td class="px-3 py-3 font-mono">{{ $item->on_hand }}</td>
                    <td class="px-3 py-3 font-mono">{{ $filters['threshold'] }}</td>
                    <td class="px-3 py-3">{{ $item->unit }}</td>
                    <td class="px-3 py-3">{{ ucfirst($item->status) }}</td>
                </tr>
            @empty
                <tr><td class="px-3 py-8 text-center text-slate-500" colspan="6">No active consumables are at or below this threshold.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<div class="no-print mt-4 flex flex-wrap items-center justify-between gap-3 text-xs text-slate-500">
    <span>Showing {{ $items->firstItem() ?? 0 }}–{{ $items->lastItem() ?? 0 }} of {{ $items->total() }} items.</span>
    {{ $items->links() }}
</div>
