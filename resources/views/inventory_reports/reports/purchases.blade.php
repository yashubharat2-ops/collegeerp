<p class="panel-subtitle">Purchase orders and ordered lines use stored PO values. Goods receipts include both purchase_receipt and manual stock_in movements from the existing ledger; no separate receipt ledger is created.</p>

<h4 class="mt-5 text-sm font-semibold text-slate-800">Purchase order lines</h4>
<div class="mt-3 overflow-x-auto">
    <table class="w-full min-w-[1120px] text-left text-sm">
        <thead class="border-b bg-slate-50 text-slate-500"><tr>
            <th class="px-3 py-3">PO / vendor</th><th class="px-3 py-3">PO date</th><th class="px-3 py-3">Status</th><th class="px-3 py-3">Item</th><th class="px-3 py-3 text-right">Ordered</th><th class="px-3 py-3 text-right">Received</th><th class="px-3 py-3 text-right">Remaining</th><th class="px-3 py-3">Unit</th><th class="px-3 py-3 text-right">Stored unit price</th>
        </tr></thead>
        <tbody class="divide-y">
            @forelse($orders as $line)
                <tr>
                    <td class="px-3 py-3 font-medium">{{ $line->purchaseOrder?->number ?? '—' }}<span class="block text-xs text-slate-500">{{ $line->purchaseOrder?->vendor?->name ?? '—' }}</span></td>
                    <td class="px-3 py-3 whitespace-nowrap">{{ $line->purchaseOrder?->po_date?->format('d M Y') ?? '—' }}</td>
                    <td class="px-3 py-3">{{ ucfirst(str_replace('_', ' ', $line->purchaseOrder?->status ?? 'unknown')) }}</td>
                    <td class="px-3 py-3 font-medium">{{ $line->item?->name ?? 'Unknown item' }}{{ $line->item?->trashed() ? ' (archived)' : '' }}<span class="block text-xs text-slate-500">{{ $line->item?->code ?? '—' }}</span></td>
                    <td class="px-3 py-3 text-right font-mono">{{ $line->quantity }}</td>
                    <td class="px-3 py-3 text-right font-mono">{{ $line->received_quantity }}</td>
                    <td class="px-3 py-3 text-right font-mono">{{ $line->remainingQuantity() }}</td>
                    <td class="px-3 py-3">{{ $line->item?->unit ?? '—' }}</td>
                    <td class="px-3 py-3 text-right font-mono">{{ $line->unit_price }}</td>
                </tr>
            @empty
                <tr><td class="px-3 py-8 text-center text-slate-500" colspan="9">No purchase order lines match these filters.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<div class="no-print mt-3 flex flex-wrap items-center justify-between gap-3 text-xs text-slate-500">
    <span>Showing {{ $orders->firstItem() ?? 0 }}–{{ $orders->lastItem() ?? 0 }} of {{ $orders->total() }} purchase order lines.</span>
    {{ $orders->links() }}
</div>

<h4 class="mt-8 text-sm font-semibold text-slate-800">Goods receipt ledger entries</h4>
<div class="mt-3 overflow-x-auto">
    <table class="w-full min-w-[1120px] text-left text-sm">
        <thead class="border-b bg-slate-50 text-slate-500"><tr>
            <th class="px-3 py-3">Receipt date / reference</th><th class="px-3 py-3">Receipt type</th><th class="px-3 py-3">Purchase order / vendor</th><th class="px-3 py-3">Item</th><th class="px-3 py-3 text-right">Receipt quantity</th><th class="px-3 py-3 text-right">Ordered</th><th class="px-3 py-3 text-right">Received to date</th><th class="px-3 py-3">Order status</th><th class="px-3 py-3">Recorded by</th>
        </tr></thead>
        <tbody class="divide-y">
            @forelse($receipts as $receipt)
                @php($line = $receipt->purchaseOrder?->lines?->firstWhere('item_id', $receipt->item_id))
                <tr>
                    <td class="px-3 py-3 whitespace-nowrap">{{ $receipt->movement_date?->format('d M Y') ?? '—' }}<span class="block text-xs text-slate-500">{{ $receipt->reference ?? '—' }}</span></td>
                    <td class="px-3 py-3">{{ $receipt->type === 'purchase_receipt' ? 'Purchase receipt' : 'Manual stock in' }}</td>
                    <td class="px-3 py-3 font-medium">{{ $receipt->purchaseOrder?->number ?? '—' }}<span class="block text-xs text-slate-500">{{ $receipt->purchaseOrder?->vendor?->name ?? '—' }}</span></td>
                    <td class="px-3 py-3">{{ $receipt->item?->name ?? 'Unknown item' }}{{ $receipt->item?->trashed() ? ' (archived)' : '' }}</td>
                    <td class="px-3 py-3 text-right font-mono">{{ $receipt->quantity }} {{ $receipt->item?->unit }}</td>
                    <td class="px-3 py-3 text-right font-mono">{{ $line?->quantity ?? '—' }}</td>
                    <td class="px-3 py-3 text-right font-mono">{{ $line?->received_quantity ?? '—' }}</td>
                    <td class="px-3 py-3">{{ ucfirst(str_replace('_', ' ', $receipt->purchaseOrder?->status ?? 'unknown')) }}</td>
                    <td class="px-3 py-3">{{ $receipt->creator?->name ?? '—' }}</td>
                </tr>
            @empty
                <tr><td class="px-3 py-8 text-center text-slate-500" colspan="9">No goods receipt movements match these filters.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<div class="no-print mt-3 flex flex-wrap items-center justify-between gap-3 text-xs text-slate-500">
    <span>Showing {{ $receipts->firstItem() ?? 0 }}–{{ $receipts->lastItem() ?? 0 }} of {{ $receipts->total() }} receipt entries.</span>
    {{ $receipts->links() }}
</div>
