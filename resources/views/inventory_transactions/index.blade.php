@extends('layouts.app')

@section('title', 'Inventory Transactions')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Inventory Transactions</h2>
            <p class="panel-subtitle">Immutable ledger behind every on-hand quantity. PO → Goods Receipt / Stock In → Inventory Transactions → Current Stock. Stock Adjustment also generates transactions.</p>
        </div>
        <div class="flex gap-2">
            @if(auth()->user()?->hasPermission('inventory_goods_receipts.create') || auth()->user()?->hasPermission('inventory_stock.in'))
                <a class="button !bg-emerald-600" href="{{ route('inventory-goods-receipts.create') }}">+ Goods Receipt</a>
            @endif
            @if(auth()->user()?->hasPermission('inventory_stock_adjustments.create') || auth()->user()?->hasPermission('inventory_stock.adjust') || auth()->user()?->hasPermission('inventory_stock.out'))
                <a class="button !bg-amber-600" href="{{ route('inventory-stock-adjustments.create') }}">+ Adjustment</a>
            @endif
        </div>
    </div>

    <form class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-6" method="GET" action="{{ route('inventory-transactions.index') }}">
        <div class="sm:col-span-2 lg:col-span-2">
            <label class="label" for="item_id">Item</label>
            <select class="input" id="item_id" name="item_id">
                <option value="">All items</option>
                @foreach($items as $item)
                    <option value="{{ $item->id }}" @selected((string) $filters['item_id'] === (string) $item->id)>{{ $item->name }} ({{ $item->code }})</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="type">Type</label>
            <select class="input" id="type" name="type">
                <option value="">All types</option>
                @foreach($types as $typeOption)
                    <option value="{{ $typeOption }}" @selected($filters['type'] === $typeOption)>{{ ucfirst(str_replace('_', ' ', $typeOption)) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="direction">Direction</label>
            <select class="input" id="direction" name="direction">
                <option value="">Both</option>
                @foreach($directions as $directionOption)
                    <option value="{{ $directionOption }}" @selected($filters['direction'] === $directionOption)>{{ ucfirst($directionOption) }}</option>
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
        <div class="sm:col-span-2 lg:col-span-6 flex items-end">
            <button class="button w-full sm:w-auto" type="submit">Filter</button>
        </div>
    </form>

    <div class="mt-6 overflow-x-auto">
        <table class="w-full min-w-[56rem] text-left text-sm">
            <thead>
                <tr class="border-b text-slate-500">
                    <th class="py-2">Date</th>
                    <th>Item</th>
                    <th>Type</th>
                    <th class="text-right">Quantity</th>
                    <th class="text-right">On hand after</th>
                    <th>Reference / reason</th>
                    <th>Recorded by</th>
                </tr>
            </thead>
            <tbody>
                @forelse($movements as $movement)
                    <tr class="border-b">
                        <td class="py-2">{{ $movement->movement_date->format('d M Y') }}</td>
                        <td class="font-medium">
                            {{ $movement->item?->name ?? '—' }}
                            <span class="ml-1 font-mono text-xs text-slate-500">{{ $movement->item?->code }}</span>
                        </td>
                        <td>
                            <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $movement->isIncoming() ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700' }}">
                                {{ ucfirst(str_replace('_', ' ', $movement->type)) }}
                            </span>
                        </td>
                        <td class="text-right font-mono text-xs {{ $movement->isIncoming() ? 'text-emerald-700' : 'text-rose-700' }}">
                            {{ $movement->isIncoming() ? '+' : '−' }}{{ $movement->quantity }}
                            <span class="text-slate-500">{{ $movement->item?->unit }}</span>
                        </td>
                        <td class="text-right font-mono text-xs">{{ $movement->balance_after }}</td>
                        <td class="text-xs text-slate-600">
                            {{ $movement->reference ?? '—' }}
                            @if($movement->reason)
                                <span class="block text-slate-500">{{ $movement->reason }}</span>
                            @endif
                            @if($movement->purchase_order_id)
                                <a class="block text-indigo-700" href="{{ route('inventory-purchase-orders.show', $movement->purchase_order_id) }}">PO #{{ $movement->purchaseOrder?->number ?? $movement->purchase_order_id }}</a>
                            @endif
                        </td>
                        <td class="text-slate-600">{{ $movement->creator?->name ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td class="py-4 text-slate-500" colspan="7">No inventory transactions recorded yet for this college.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-6">{{ $movements->links() }}</div>
</div>
@endsection
