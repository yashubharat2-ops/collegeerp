@extends('layouts.app')

@section('title', 'Current Stock')

@section('content')
<div class="panel">
    <h2 class="panel-title">Current Stock</h2>
    <p class="panel-subtitle">On-hand balances calculated from all incoming and outgoing inventory transactions for the active college. Items without movements have zero stock; inactive items can still carry stock.</p>

    <form class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-6" method="GET" action="{{ route('inventory-current-stock.index') }}">
        <div class="lg:col-span-2">
            <label class="label" for="search">Item / code / serial</label>
            <input class="input" id="search" name="search" value="{{ $filters['search'] }}">
        </div>
        <div>
            <label class="label" for="category_id">Category</label>
            <select class="input" id="category_id" name="category_id">
                <option value="">All categories</option>
                @foreach($categories as $category)
                    <option value="{{ $category->id }}" @selected((string) $filters['category_id'] === (string) $category->id)>{{ $category->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="item_type">Type</label>
            <select class="input" id="item_type" name="item_type">
                <option value="">All types</option>
                @foreach($types as $type)
                    <option value="{{ $type }}" @selected($filters['item_type'] === $type)>{{ ucfirst($type) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="status">Status</label>
            <select class="input" id="status" name="status">
                <option value="">All statuses</option>
                @foreach($statuses as $status)
                    <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ ucfirst($status) }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex items-end"><button class="button w-full sm:w-auto" type="submit">Filter</button></div>
    </form>

    <div class="mt-6 overflow-x-auto">
        <table class="w-full min-w-[48rem] text-left text-sm">
            <thead><tr class="border-b text-slate-500"><th class="py-2">Item / asset</th><th>Category</th><th>Type</th><th>Status</th><th class="text-right">On hand</th><th></th></tr></thead>
            <tbody>
                @forelse($items as $item)
                    <tr class="border-b">
                        <td class="py-2 font-medium">{{ $item->name }} <span class="block font-mono text-xs text-slate-500">{{ $item->code }}{{ $item->serial_number ? ' / '.$item->serial_number : '' }}</span></td>
                        <td>{{ $item->category?->name ?? '—' }}</td>
                        <td>{{ ucfirst($item->item_type) }}</td>
                        <td>{{ ucfirst($item->status) }}</td>
                        <td class="text-right font-mono">{{ number_format((float) $item->on_hand, 2) }} {{ $item->unit }}</td>
                        <td class="text-right">
                            @can('viewTransactions', App\Models\InventoryStockMovement::class)
                                <a class="text-indigo-700" href="{{ route('inventory-transactions.index', ['item_id' => $item->id]) }}">Transactions</a>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr><td class="py-4 text-slate-500" colspan="6">No items match these filters.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-6">{{ $items->links() }}</div>
</div>
@endsection
