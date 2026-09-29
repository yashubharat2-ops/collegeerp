<p class="panel-subtitle">Issue quantities and recipients come from existing immutable InventoryIssue rows; corresponding stock-outs remain in the stock ledger.</p>
<div class="mt-4 overflow-x-auto">
    <table class="w-full min-w-[1120px] text-left text-sm">
        <thead class="border-b bg-slate-50 text-slate-500"><tr>
            <th class="px-3 py-3">Issue / reference</th><th class="px-3 py-3">Date</th><th class="px-3 py-3">Item / category</th><th class="px-3 py-3 text-right">Quantity</th><th class="px-3 py-3">Recipient</th><th class="px-3 py-3">Purpose / notes</th><th class="px-3 py-3">Recorded by</th>
        </tr></thead>
        <tbody class="divide-y">
            @forelse($issues as $issue)
                <tr>
                    <td class="px-3 py-3 font-medium">{{ $issue->number }}<span class="block text-xs text-slate-500">{{ $issue->reference ?? '—' }}</span></td>
                    <td class="px-3 py-3 whitespace-nowrap">{{ $issue->movement_date?->format('d M Y') ?? '—' }}</td>
                    <td class="px-3 py-3">{{ $issue->item?->name ?? 'Unknown item' }}{{ $issue->item?->trashed() ? ' (archived)' : '' }}<span class="block text-xs text-slate-500">{{ $issue->item?->category?->name ?? '—' }}</span></td>
                    <td class="px-3 py-3 text-right font-mono">{{ $issue->quantity }} {{ $issue->item?->unit }}</td>
                    <td class="px-3 py-3">{{ ucfirst($issue->issued_to_type) }}: {{ $issue->recipientName() }}</td>
                    <td class="px-3 py-3">{{ $issue->purpose ?? '—' }}<span class="block text-xs text-slate-500">{{ $issue->notes ?? '—' }}</span></td>
                    <td class="px-3 py-3">{{ $issue->creator?->name ?? '—' }}</td>
                </tr>
            @empty
                <tr><td class="px-3 py-8 text-center text-slate-500" colspan="7">No item issues match these filters.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<div class="no-print mt-4 flex flex-wrap items-center justify-between gap-3 text-xs text-slate-500">
    <span>Showing {{ $issues->firstItem() ?? 0 }}–{{ $issues->lastItem() ?? 0 }} of {{ $issues->total() }} issues.</span>
    {{ $issues->links() }}
</div>
