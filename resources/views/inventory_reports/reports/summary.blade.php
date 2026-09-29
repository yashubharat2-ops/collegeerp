<p class="panel-subtitle">Live metrics from the existing inventory tables. Stock is grouped by unit so quantities from unlike units are never added together.</p>

<div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
    <div class="stat-card"><p class="stat-label">Total items</p><p class="stat-value">{{ $summary['metrics']['total_items'] }}</p></div>
    <div class="stat-card"><p class="stat-label">Total assets</p><p class="stat-value">{{ $summary['metrics']['total_assets'] }}</p></div>
    <div class="stat-card"><p class="stat-label">Total categories</p><p class="stat-value">{{ $summary['metrics']['total_categories'] }}</p></div>
    <div class="stat-card"><p class="stat-label">Low-stock items</p><p class="stat-value">{{ $summary['metrics']['low_stock_items'] }}</p><span class="stat-label">At or below {{ $filters['threshold'] }}</span></div>
    <div class="stat-card"><p class="stat-label">Stock transactions</p><p class="stat-value">{{ $summary['metrics']['stock_transactions'] }}</p></div>
    <div class="stat-card"><p class="stat-label">Purchase orders</p><p class="stat-value">{{ $summary['metrics']['purchase_orders'] }}</p></div>
    <div class="stat-card"><p class="stat-label">Stored purchase-order total</p><p class="stat-value">{{ $summary['metrics']['purchase_order_value'] }}</p></div>
    <div class="stat-card"><p class="stat-label">Goods receipts</p><p class="stat-value">{{ $summary['metrics']['goods_receipts'] }}</p></div>
    <div class="stat-card"><p class="stat-label">Item issues</p><p class="stat-value">{{ $summary['metrics']['item_issues'] }}</p></div>
    <div class="stat-card"><p class="stat-label">Assets assigned</p><p class="stat-value">{{ $summary['metrics']['assets_assigned'] }}</p></div>
    <div class="stat-card"><p class="stat-label">Assets returned</p><p class="stat-value">{{ $summary['metrics']['assets_returned'] }}</p></div>
    <div class="stat-card"><p class="stat-label">Assets under maintenance</p><p class="stat-value">{{ $summary['metrics']['assets_under_maintenance'] }}</p></div>
</div>

<h4 class="mt-8 text-sm font-semibold text-slate-800">Current stock by unit</h4>
<p class="mt-1 text-xs text-slate-500">Ledger-derived balance; quantities are shown separately for each stored unit.</p>
<div class="mt-3 overflow-x-auto">
    <table class="w-full max-w-xl text-left text-sm">
        <thead class="border-b bg-slate-50 text-slate-500"><tr><th class="px-3 py-3">Unit</th><th class="px-3 py-3 text-right">Current stock</th></tr></thead>
        <tbody class="divide-y">
            @forelse($summary['stock_by_unit'] as $unitRow)
                <tr><td class="px-3 py-3">{{ $unitRow->unit }}</td><td class="px-3 py-3 text-right font-mono">{{ $unitRow->stock_quantity }}</td></tr>
            @empty
                <tr><td class="px-3 py-6 text-slate-500" colspan="2">No inventory items are recorded.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<h4 class="mt-8 text-sm font-semibold text-slate-800">Category breakdown</h4>
<p class="mt-1 text-xs text-slate-500">Catalogue, custody and low-stock counts use existing category relationships. Low stock counts active consumables at or below {{ $filters['threshold'] }}.</p>
<div class="mt-3 overflow-x-auto">
    <table class="w-full min-w-[880px] text-left text-sm">
        <thead class="border-b bg-slate-50 text-slate-500"><tr><th class="px-3 py-3">Category</th><th class="px-3 py-3 text-right">Items / assets</th><th class="px-3 py-3 text-right">Assets</th><th class="px-3 py-3 text-right">Assigned assets</th><th class="px-3 py-3 text-right">Assets with open maintenance</th><th class="px-3 py-3 text-right">Low-stock consumables</th></tr></thead>
        <tbody class="divide-y">
            @forelse($rows as $row)
                <tr>
                    <td class="px-3 py-3 font-medium">{{ $row->name }}<span class="block font-mono text-xs text-slate-500">{{ $row->code }}</span></td>
                    <td class="px-3 py-3 text-right">{{ $row->items_count }}</td>
                    <td class="px-3 py-3 text-right">{{ $row->assets_count }}</td>
                    <td class="px-3 py-3 text-right">{{ $row->assigned_assets_count }}</td>
                    <td class="px-3 py-3 text-right">{{ $row->open_maintenance_assets_count }}</td>
                    <td class="px-3 py-3 text-right">{{ $row->low_count }}</td>
                </tr>
            @empty
                <tr><td class="px-3 py-8 text-center text-slate-500" colspan="6">No categories match these filters.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<div class="no-print mt-4 flex flex-wrap items-center justify-between gap-3 text-xs text-slate-500">
    <span>Showing {{ $rows->firstItem() ?? 0 }}–{{ $rows->lastItem() ?? 0 }} of {{ $rows->total() }} categories.</span>
    {{ $rows->links() }}
</div>
