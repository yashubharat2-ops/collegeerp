@extends('layouts.app')

@section('title', 'Inventory Reports')

@section('content')
<div class="panel">
    <h2 class="panel-title">Inventory Reports</h2>
    <p class="panel-subtitle">Live category summary of the existing item / asset master, ledger-based low stock, active asset custody and open maintenance. Counts include inactive catalogue entries unless marked active; archived records are excluded.</p>

    <form class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-3" method="GET" action="{{ route('inventory-reports.index') }}">
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
        <table class="w-full min-w-[52rem] text-left text-sm">
            <thead><tr class="border-b text-slate-500"><th class="py-2">Category</th><th class="text-right">Items / assets</th><th class="text-right">Assets</th><th class="text-right">Assigned assets</th><th class="text-right">Assets with open maintenance</th><th class="text-right">Low-stock consumables</th></tr></thead>
            <tbody>
                @forelse($rows as $row)
                    <tr class="border-b">
                        <td class="py-2 font-medium">{{ $row->name }} <span class="block font-mono text-xs text-slate-500">{{ $row->code }}</span></td>
                        <td class="text-right">{{ $row->items_count }}</td>
                        <td class="text-right">{{ $row->assets_count }}</td>
                        <td class="text-right">{{ $row->assigned_assets_count }}</td>
                        <td class="text-right">{{ $row->open_maintenance_assets_count }}</td>
                        <td class="text-right">{{ $row->low_count }}</td>
                    </tr>
                @empty
                    <tr><td class="py-4 text-slate-500" colspan="6">No categories match these filters.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <p class="mt-3 text-xs text-slate-500">Low stock counts active consumables with a ledger balance at or below {{ $filters['threshold'] }}. Open maintenance counts distinct assets with scheduled or in-progress work.</p>
    <div class="mt-6">{{ $rows->links() }}</div>
</div>
@endsection
