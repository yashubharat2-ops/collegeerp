@extends('layouts.app')

@section('title', 'Items / Assets')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Items / Assets</h2>
            <p class="panel-subtitle">One catalogue for the active college. Mark each row as a consumable or an asset — there is no separate asset master.</p>
        </div>
        @can('create', App\Models\InventoryItem::class)
            <a class="button" href="{{ route('inventory-items.create') }}">+ Add item / asset</a>
        @endcan
    </div>

    <form class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-5" method="GET" action="{{ route('inventory-items.index') }}">
        <div class="sm:col-span-2 lg:col-span-1">
            <label class="label" for="search">Search</label>
            <input class="input" id="search" name="search" type="text" value="{{ $search }}" placeholder="Name, code, brand, model or serial">
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
                    <option value="{{ $type }}" @selected($itemType === $type)>{{ ucfirst($type) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="status">Status</label>
            <select class="input" id="status" name="status">
                <option value="">All statuses</option>
                @foreach($statuses as $statusOption)
                    <option value="{{ $statusOption }}" @selected($status === $statusOption)>{{ ucfirst($statusOption) }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex items-end">
            <button class="button w-full sm:w-auto" type="submit">Filter</button>
        </div>
    </form>

    <div class="mt-6 overflow-x-auto">
        <table class="w-full min-w-[56rem] text-left text-sm">
            <thead>
                <tr class="border-b text-slate-500">
                    <th class="py-2">Name</th>
                    <th>Code</th>
                    <th>Category</th>
                    <th>Type</th>
                    <th>Brand / model</th>
                    <th>Serial</th>
                    <th class="text-right">Qty</th>
                    <th>Status</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($items as $item)
                    <tr class="border-b">
                        <td class="py-2 font-medium">{{ $item->name }}</td>
                        <td><span class="font-mono text-xs">{{ $item->code }}</span></td>
                        <td class="text-slate-600">{{ $item->category?->name ?? '—' }}</td>
                        <td>
                            <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $item->item_type === 'asset' ? 'bg-indigo-100 text-indigo-700' : 'bg-amber-100 text-amber-800' }}">{{ ucfirst($item->item_type) }}</span>
                        </td>
                        <td class="text-slate-600">
                            {{ $item->brand ?? '—' }}
                            @if($item->model)
                                <span class="text-xs text-slate-500">/ {{ $item->model }}</span>
                            @endif
                        </td>
                        <td class="font-mono text-xs text-slate-600">{{ $item->serial_number ?? '—' }}</td>
                        <td class="text-right">{{ $item->quantity }} <span class="text-xs text-slate-500">{{ $item->unit }}</span></td>
                        <td>
                            <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $item->status === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">{{ ucfirst($item->status) }}</span>
                        </td>
                        <td class="py-2">
                            <div class="flex justify-end gap-2">
                                @can('update', $item)
                                    <a class="button !bg-slate-200 !text-slate-700" href="{{ route('inventory-items.edit', $item) }}">Edit</a>
                                @endcan
                                @can('delete', $item)
                                    <form method="POST" action="{{ route('inventory-items.destroy', $item) }}" onsubmit="return confirm('Delete the item &quot;{{ $item->name }}&quot;?');">
                                        @csrf
                                        @method('DELETE')
                                        <button class="button !bg-rose-100 !text-rose-700" type="submit">Delete</button>
                                    </form>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td class="py-4 text-slate-500" colspan="9">No items or assets recorded yet for this college.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-6">{{ $items->links() }}</div>
</div>
@endsection
