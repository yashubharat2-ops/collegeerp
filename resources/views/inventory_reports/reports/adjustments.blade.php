<p class="panel-subtitle">Existing adjustment and manual stock-out ledger entries. Quantities and balance-after values are read from the original stock movement rows.</p>
<div class="mt-4 overflow-x-auto">
    <table class="w-full min-w-[1080px] text-left text-sm">
        <thead class="border-b bg-slate-50 text-slate-500"><tr>
            <th class="px-3 py-3">Date</th><th class="px-3 py-3">Adjustment type</th><th class="px-3 py-3">Item / category</th><th class="px-3 py-3">Direction</th><th class="px-3 py-3 text-right">Quantity</th><th class="px-3 py-3 text-right">Balance after</th><th class="px-3 py-3">Reason / reference</th><th class="px-3 py-3">Recorded by</th>
        </tr></thead>
        <tbody class="divide-y">
            @forelse($movements as $movement)
                <tr>
                    <td class="px-3 py-3 whitespace-nowrap">{{ $movement->movement_date?->format('d M Y') ?? '—' }}</td>
                    <td class="px-3 py-3">{{ ucfirst(str_replace('_', ' ', $movement->type)) }}</td>
                    <td class="px-3 py-3 font-medium">{{ $movement->item?->name ?? 'Unknown item' }}{{ $movement->item?->trashed() ? ' (archived)' : '' }}<span class="block text-xs text-slate-500">{{ $movement->item?->code ?? '—' }} · {{ $movement->item?->category?->name ?? '—' }}</span></td>
                    <td class="px-3 py-3">{{ ucfirst($movement->direction) }}</td>
                    <td class="px-3 py-3 text-right font-mono">{{ $movement->quantity }} {{ $movement->item?->unit }}</td>
                    <td class="px-3 py-3 text-right font-mono">{{ $movement->balance_after }}</td>
                    <td class="px-3 py-3">{{ $movement->reason ?? '—' }}<span class="block text-xs text-slate-500">{{ $movement->reference ?? '—' }}</span></td>
                    <td class="px-3 py-3">{{ $movement->creator?->name ?? '—' }}</td>
                </tr>
            @empty
                <tr><td class="px-3 py-8 text-center text-slate-500" colspan="8">No stock adjustments match these filters.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<div class="no-print mt-4 flex flex-wrap items-center justify-between gap-3 text-xs text-slate-500">
    <span>Showing {{ $movements->firstItem() ?? 0 }}–{{ $movements->lastItem() ?? 0 }} of {{ $movements->total() }} adjustment entries.</span>
    {{ $movements->links() }}
</div>
