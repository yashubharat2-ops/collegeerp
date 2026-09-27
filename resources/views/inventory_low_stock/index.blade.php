@extends('layouts.app')

@section('title', 'Low Stock')

@section('content')
<div class="panel">
    <h2 class="panel-title">Low Stock</h2>
    <p class="panel-subtitle">Active consumables at or below the selected on-hand threshold (including zero). Balances come from the stock ledger. The threshold is a view filter, not a saved reorder level; assets and inactive items are excluded.</p>

    <form class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4" method="GET" action="{{ route('inventory-low-stock.index') }}">
        <div>
            <label class="label" for="search">Item / code</label>
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
            <label class="label" for="threshold">Low stock at or below</label>
            <input class="input" id="threshold" name="threshold" type="number" min="0" max="9999999999.99" step="0.01" value="{{ $filters['threshold'] }}" required>
        </div>
        <div class="flex items-end"><button class="button w-full sm:w-auto" type="submit">Filter</button></div>
    </form>

    <div class="mt-6 overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead><tr class="border-b text-slate-500"><th class="py-2">Consumable</th><th>Category</th><th class="text-right">On hand</th></tr></thead>
            <tbody>
                @forelse($items as $item)
                    <tr class="border-b">
                        <td class="py-2 font-medium">{{ $item->name }} <span class="block font-mono text-xs text-slate-500">{{ $item->code }}</span></td>
                        <td>{{ $item->category?->name ?? '—' }}</td>
                        <td class="text-right font-mono {{ (float) $item->on_hand == 0.0 ? 'text-rose-700' : 'text-amber-700' }}">{{ number_format((float) $item->on_hand, 2) }} {{ $item->unit }}</td>
                    </tr>
                @empty
                    <tr><td class="py-4 text-slate-500" colspan="3">No active consumables are at or below this threshold.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-6">{{ $items->links() }}</div>
</div>
@endsection
