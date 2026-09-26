@extends('layouts.app')

@section('title', 'Goods Receipt / Stock In')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Goods Receipt / Stock In</h2>
            <p class="panel-subtitle">Incoming stock: purchase receipts booked against purchase orders and manual stock-in. Architecture: PO → Goods Receipt → Inventory Transactions → Current Stock.</p>
        </div>
        @if(auth()->user()?->hasPermission('inventory_goods_receipts.create') || auth()->user()?->hasPermission('inventory_stock.in'))
            <a class="button" href="{{ route('inventory-goods-receipts.create') }}">+ Record stock in</a>
        @endif
    </div>

    <form class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-5" method="GET" action="{{ route('inventory-goods-receipts.index') }}">
        <div class="lg:col-span-2">
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
        <div class="flex items-end">
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
                    <th>Reference / PO</th>
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
                            <span class="rounded-full px-2 py-0.5 text-xs font-semibold bg-emerald-100 text-emerald-700">
                                {{ ucfirst(str_replace('_', ' ', $movement->type)) }}
                            </span>
                        </td>
                        <td class="text-right font-mono text-xs text-emerald-700">
                            +{{ $movement->quantity }}
                            <span class="text-slate-500">{{ $movement->item?->unit }}</span>
                        </td>
                        <td class="text-right font-mono text-xs">{{ $movement->balance_after }}</td>
                        <td class="text-xs text-slate-600">
                            {{ $movement->reference ?? '—' }}
                            @if($movement->purchase_order_id)
                                <a class="block text-indigo-700" href="{{ route('inventory-purchase-orders.show', $movement->purchase_order_id) }}">PO #{{ $movement->purchaseOrder?->number ?? $movement->purchase_order_id }}</a>
                            @endif
                            @if($movement->reason)
                                <span class="block text-slate-500">{{ $movement->reason }}</span>
                            @endif
                        </td>
                        <td class="text-slate-600">{{ $movement->creator?->name ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td class="py-4 text-slate-500" colspan="7">No goods receipts recorded yet for this college.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-6">{{ $movements->links() }}</div>
</div>
@endsection
