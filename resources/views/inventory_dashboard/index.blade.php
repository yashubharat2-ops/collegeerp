@extends('layouts.app')

@section('title', 'Inventory Dashboard')

@section('content')
<div class="space-y-6">
    <div class="panel">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 class="panel-title">Inventory Dashboard</h2>
                <p class="panel-subtitle">Live, read-only overview of the active college's catalogue — categories, items / assets and vendors. Figures are computed from the masters themselves; nothing here is stored separately. Archived records are excluded.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @can('viewAny', App\Models\InventoryCategory::class)
                    <a class="button !bg-slate-200 !text-slate-700" href="{{ route('inventory-categories.index') }}">Item Categories</a>
                @endcan
                @can('viewAny', App\Models\InventoryItem::class)
                    <a class="button !bg-slate-200 !text-slate-700" href="{{ route('inventory-items.index') }}">Items / Assets</a>
                @endcan
                @can('viewAny', App\Models\InventoryVendor::class)
                    <a class="button !bg-slate-200 !text-slate-700" href="{{ route('inventory-vendors.index') }}">Vendors</a>
                @endcan
            </div>
        </div>

        <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="stat-card">
                <p class="stat-label">Categories</p>
                <p class="stat-value">{{ $totalCategories }}</p>
                <p class="stat-hint">{{ $totals['active_categories'] }} active</p>
            </div>
            <div class="stat-card">
                <p class="stat-label">Items / Assets</p>
                <p class="stat-value">{{ $totalItems }}</p>
                <p class="stat-hint">{{ $totals['consumable_items'] }} consumable · {{ $totals['asset_items'] }} asset</p>
            </div>
            <div class="stat-card">
                <p class="stat-label">Active Items</p>
                <p class="stat-value">{{ $activeItems }}</p>
                <p class="stat-hint">{{ $totalItems - $activeItems }} inactive</p>
            </div>
            <div class="stat-card">
                <p class="stat-label">Vendors</p>
                <p class="stat-value">{{ $totalVendors }}</p>
                <p class="stat-hint">{{ $totals['active_vendors'] }} active</p>
            </div>
        </div>
    </div>

    @if($totalCategories === 0 && $totalItems === 0 && $totalVendors === 0)
        <div class="panel">
            <h3 class="font-semibold">Getting started</h3>
            <p class="mt-2 text-sm text-slate-600">No inventory recorded yet for this college. A typical set-up order is: create <strong>Item Categories</strong>, then <strong>Items / Assets</strong> (consumables and assets share one master), and record <strong>Vendors</strong> for later procurement. Purchase orders, stock in/out, issue/return, asset assignment, maintenance and reports are separate later phases and are not part of this screen.</p>
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-2">
        <div class="panel">
            <h3 class="font-semibold">Items per category</h3>
            <p class="panel-subtitle">Every category of the active college with its live item count.</p>
            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b text-slate-500">
                            <th class="py-2">Category</th>
                            <th>Code</th>
                            <th>Status</th>
                            <th class="text-right">Items</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($itemsPerCategory as $category)
                            <tr class="border-b">
                                <td class="py-2 font-medium">{{ $category->name }}</td>
                                <td><span class="font-mono text-xs">{{ $category->code }}</span></td>
                                <td>{{ ucfirst($category->status) }}</td>
                                <td class="text-right">{{ $category->items_count }}</td>
                            </tr>
                        @empty
                            <tr><td class="py-4 text-slate-500" colspan="4">No categories recorded yet for this college.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="panel">
            <h3 class="font-semibold">Recently added</h3>
            <p class="panel-subtitle">The latest items and assets on the active college's catalogue.</p>
            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b text-slate-500">
                            <th class="py-2">Item</th>
                            <th>Type</th>
                            <th>Category</th>
                            <th class="text-right">Qty</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($recentItems as $item)
                            <tr class="border-b">
                                <td class="py-2">
                                    <div class="font-medium">{{ $item->name }}</div>
                                    <div class="font-mono text-xs text-slate-500">{{ $item->code }}</div>
                                </td>
                                <td>{{ ucfirst($item->item_type) }}</td>
                                <td class="text-slate-600">{{ $item->category?->name ?? '—' }}</td>
                                <td class="text-right">{{ $item->quantity }} {{ $item->unit }}</td>
                            </tr>
                        @empty
                            <tr><td class="py-4 text-slate-500" colspan="4">No items recorded yet for this college.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
