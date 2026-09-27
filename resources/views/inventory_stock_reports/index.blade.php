@extends('layouts.app')

@section('title', 'Stock / Transaction Reports')

@section('content')
<div class="panel">
    <h2 class="panel-title">Stock / Transaction Reports</h2>
    <p class="panel-subtitle">Per-item opening stock, transactions and closing stock from the immutable ledger, by movement date. A blank date range covers all history; archived items with ledger history remain in the report. Quantities are never combined across different units.</p>

    <form class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4" method="GET" action="{{ route('inventory-stock-reports.index') }}">
        <div>
            <label class="label" for="item_id">Item</label>
            <select class="input" id="item_id" name="item_id">
                <option value="">All items</option>
                @foreach($items as $item)
                    <option value="{{ $item->id }}" @selected((string) $filters['item_id'] === (string) $item->id)>{{ $item->name }} ({{ $item->code }})</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="from">From</label>
            <input class="input" id="from" name="from" type="date" value="{{ $filters['from'] }}">
        </div>
        <div>
            <label class="label" for="to">To</label>
            <input class="input" id="to" name="to" type="date" value="{{ $filters['to'] }}">
        </div>
        <div class="flex items-end"><button class="button w-full sm:w-auto" type="submit">Filter</button></div>
    </form>

    <div class="mt-6 overflow-x-auto">
        <table class="w-full min-w-[52rem] text-left text-sm">
            <thead><tr class="border-b text-slate-500"><th class="py-2">Item / unit</th><th class="text-right">Opening</th><th class="text-right">In</th><th class="text-right">Out</th><th class="text-right">Transactions</th><th class="text-right">Closing</th></tr></thead>
            <tbody>
                @forelse($rows as $row)
                    <tr class="border-b">
                        <td class="py-2 font-medium">
                            {{ $row->item?->name ?? '—' }}
                            <span class="block font-mono text-xs text-slate-500">{{ $row->item?->code }} · {{ $row->item?->unit }}{{ $row->item?->trashed() ? ' (archived)' : '' }}</span>
                            @can('viewTransactions', App\Models\InventoryStockMovement::class)
                                <a class="text-xs text-indigo-700" href="{{ route('inventory-transactions.index', ['item_id' => $row->item_id, 'from' => $filters['from'], 'to' => $filters['to']]) }}">View transactions</a>
                            @endcan
                        </td>
                        <td class="text-right font-mono">{{ number_format((float) $row->opening, 2) }}</td>
                        <td class="text-right font-mono text-emerald-700">{{ number_format((float) $row->received, 2) }}</td>
                        <td class="text-right font-mono text-rose-700">{{ number_format((float) $row->issued, 2) }}</td>
                        <td class="text-right">{{ $row->transactions }}</td>
                        <td class="text-right font-mono">{{ number_format((float) $row->closing, 2) }}</td>
                    </tr>
                @empty
                    <tr><td class="py-4 text-slate-500" colspan="6">No stock transactions match these filters.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-6">{{ $rows->links() }}</div>
</div>
@endsection
