@extends('layouts.app')

@section('title', 'Purchase Orders')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Purchase Orders</h2>
            <p class="panel-subtitle">Orders raised with the active college's vendors. A draft is still editable; once submitted it can only be received against or cancelled.</p>
        </div>
        @can('create', App\Models\InventoryPurchaseOrder::class)
            <a class="button" href="{{ route('inventory-purchase-orders.create') }}">+ New purchase order</a>
        @endcan
    </div>

    <form class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4" method="GET" action="{{ route('inventory-purchase-orders.index') }}">
        <div>
            <label class="label" for="search">Search</label>
            <input class="input" id="search" name="search" type="text" value="{{ $search }}" placeholder="Order number or vendor">
        </div>
        <div>
            <label class="label" for="vendor_id">Vendor</label>
            <select class="input" id="vendor_id" name="vendor_id">
                <option value="">All vendors</option>
                @foreach($vendors as $vendor)
                    <option value="{{ $vendor->id }}" @selected((string) $filters['vendor_id'] === (string) $vendor->id)>{{ $vendor->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="status">Status</label>
            <select class="input" id="status" name="status">
                <option value="">All statuses</option>
                @foreach($statuses as $statusOption)
                    <option value="{{ $statusOption }}" @selected($status === $statusOption)>{{ ucfirst(str_replace('_', ' ', $statusOption)) }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex items-end">
            <button class="button w-full sm:w-auto" type="submit">Filter</button>
        </div>
    </form>

    <div class="mt-6 overflow-x-auto">
        <table class="w-full min-w-[52rem] text-left text-sm">
            <thead>
                <tr class="border-b text-slate-500">
                    <th class="py-2">Order</th>
                    <th>Date</th>
                    <th>Vendor</th>
                    <th>Status</th>
                    <th class="text-right">Total</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($orders as $order)
                    <tr class="border-b">
                        <td class="py-2">
                            <a class="font-mono text-xs font-semibold text-indigo-700" href="{{ route('inventory-purchase-orders.show', $order) }}">{{ $order->number }}</a>
                        </td>
                        <td>{{ $order->po_date->format('d M Y') }}</td>
                        <td class="text-slate-600">{{ $order->vendor?->name ?? '—' }}</td>
                        <td>
                            <span class="rounded-full px-3 py-1 text-xs font-semibold {{ match ($order->status) {
                                'draft' => 'bg-slate-100 text-slate-600',
                                'submitted' => 'bg-indigo-100 text-indigo-700',
                                'partially_received' => 'bg-amber-100 text-amber-800',
                                'received' => 'bg-emerald-100 text-emerald-700',
                                default => 'bg-rose-100 text-rose-700',
                            } }}">{{ ucfirst(str_replace('_', ' ', $order->status)) }}</span>
                        </td>
                        <td class="text-right font-mono text-xs">{{ $order->total_amount }}</td>
                        <td class="py-2">
                            <div class="flex justify-end gap-2">
                                <a class="button !bg-slate-200 !text-slate-700" href="{{ route('inventory-purchase-orders.show', $order) }}">View</a>
                                @can('update', $order)
                                    @if($order->isEditable())
                                        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('inventory-purchase-orders.edit', $order) }}">Edit</a>
                                    @endif
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td class="py-4 text-slate-500" colspan="6">No purchase orders recorded yet for this college.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-6">{{ $orders->links() }}</div>
</div>
@endsection
