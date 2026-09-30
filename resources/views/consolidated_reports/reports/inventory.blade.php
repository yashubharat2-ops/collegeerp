@php
    $metrics = $summary['metrics'];
@endphp

<p class="panel-subtitle">
    The inventory / asset position of the active college, taken from the existing Inventory Summary. Stock balances are
    projected from the authoritative movement ledger and are shown separately per stored unit, so quantities of unlike
    units are never added together. Purchase values are the stored purchase-order totals; no amount is recomputed.
</p>

<div class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
    <div class="stat-card"><p class="stat-label">Items / assets</p><p class="stat-value">{{ number_format($metrics['total_items']) }}</p></div>
    <div class="stat-card"><p class="stat-label">Assets</p><p class="stat-value">{{ number_format($metrics['total_assets']) }}</p><p class="stat-hint">{{ number_format($metrics['total_categories']) }} categories</p></div>
    <div class="stat-card"><p class="stat-label">Low-stock items</p><p class="stat-value">{{ number_format($metrics['low_stock_items']) }}</p><p class="stat-hint">At or below {{ $filters['threshold'] ?? '' }}</p></div>
    <div class="stat-card"><p class="stat-label">Stored purchase-order total</p><p class="stat-value">{{ number_format((float) $metrics['purchase_order_value'], 2) }}</p><p class="stat-hint">{{ number_format($metrics['purchase_orders']) }} purchase orders</p></div>
    <div class="stat-card"><p class="stat-label">Stock transactions</p><p class="stat-value">{{ number_format($metrics['stock_transactions']) }}</p></div>
    <div class="stat-card"><p class="stat-label">Goods receipts</p><p class="stat-value">{{ number_format($metrics['goods_receipts']) }}</p></div>
    <div class="stat-card"><p class="stat-label">Item issues</p><p class="stat-value">{{ number_format($metrics['item_issues']) }}</p></div>
    <div class="stat-card"><p class="stat-label">Assets assigned</p><p class="stat-value">{{ number_format($metrics['assets_assigned']) }}</p><p class="stat-hint">{{ number_format($metrics['assets_returned']) }} returned</p></div>
    <div class="stat-card"><p class="stat-label">Assets under maintenance</p><p class="stat-value">{{ number_format($metrics['assets_under_maintenance']) }}</p></div>
</div>

<h4 class="mt-8 text-sm font-semibold text-slate-800">Current stock by unit</h4>
<p class="mt-1 text-xs text-slate-500">Ledger-derived balance; quantities are shown separately for each stored unit.</p>
<div class="mt-3 overflow-x-auto">
    <table class="w-full max-w-xl text-left text-sm">
        <thead class="border-b bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
            <tr><th class="px-3 py-3">Unit</th><th class="px-3 py-3 text-right">Current stock</th></tr>
        </thead>
        <tbody class="divide-y">
            @forelse($summary['stock_by_unit'] as $unitRow)
                <tr>
                    <td class="px-3 py-3">{{ $unitRow->unit }}</td>
                    <td class="px-3 py-3 text-right font-mono">{{ $unitRow->stock_quantity }}</td>
                </tr>
            @empty
                <tr><td colspan="2" class="px-3 py-6 text-slate-500">No inventory items are recorded.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
