@extends('layouts.app')

@section('title', 'Purchase Order '.$order->number)

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Purchase Order {{ $order->number }}</h2>
            <p class="panel-subtitle">
                {{ $order->vendor?->name ?? '—' }} · ordered {{ $order->po_date->format('d M Y') }}
                @if($order->expected_date)
                    · expected {{ $order->expected_date->format('d M Y') }}
                @endif
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <span class="rounded-full px-3 py-1 text-xs font-semibold {{ match ($order->status) {
                'draft' => 'bg-slate-100 text-slate-600',
                'submitted' => 'bg-indigo-100 text-indigo-700',
                'partially_received' => 'bg-amber-100 text-amber-800',
                'received' => 'bg-emerald-100 text-emerald-700',
                default => 'bg-rose-100 text-rose-700',
            } }}">{{ ucfirst(str_replace('_', ' ', $order->status)) }}</span>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('inventory-purchase-orders.index') }}">Back</a>
        </div>
    </div>

    <div class="mt-4 flex flex-wrap gap-2">
        @can('update', $order)
            @if($order->isEditable())
                <a class="button !bg-slate-200 !text-slate-700" href="{{ route('inventory-purchase-orders.edit', $order) }}">Edit draft</a>
                <form method="POST" action="{{ route('inventory-purchase-orders.submit', $order) }}" onsubmit="return confirm('Submit &quot;{{ $order->number }}&quot; to the vendor? The order can no longer be edited afterwards.');">
                    @csrf
                    <button class="button" type="submit">Submit to vendor</button>
                </form>
            @endif
            @if($order->isCancellable())
                <form method="POST" action="{{ route('inventory-purchase-orders.cancel', $order) }}" onsubmit="return confirm('Cancel &quot;{{ $order->number }}&quot;? Anything already received stays received.');">
                    @csrf
                    <button class="button !bg-rose-100 !text-rose-700" type="submit">Cancel order</button>
                </form>
            @endif
        @endcan
        @can('receive', $order)
            @if($order->isReceivable())
                <a class="button" href="{{ route('inventory-purchase-orders.receive.create', $order) }}">Receive goods</a>
            @endif
        @endcan
        @can('delete', $order)
            @if($order->isEditable())
                <form method="POST" action="{{ route('inventory-purchase-orders.destroy', $order) }}" onsubmit="return confirm('Delete the draft &quot;{{ $order->number }}&quot;?');">
                    @csrf
                    @method('DELETE')
                    <button class="button !bg-rose-100 !text-rose-700" type="submit">Delete</button>
                </form>
            @endif
        @endcan
    </div>

    @if($order->notes)
        <p class="mt-4 rounded-lg bg-slate-50 p-3 text-sm text-slate-600">{{ $order->notes }}</p>
    @endif

    <div class="mt-6 overflow-x-auto">
        <table class="w-full min-w-[52rem] text-left text-sm">
            <thead>
                <tr class="border-b text-slate-500">
                    <th class="py-2">Item</th>
                    <th class="text-right">Ordered</th>
                    <th class="text-right">Received</th>
                    <th class="text-right">Outstanding</th>
                    <th class="text-right">Unit price</th>
                    <th class="text-right">Line total</th>
                </tr>
            </thead>
            <tbody>
                @forelse($order->lines as $line)
                    <tr class="border-b">
                        <td class="py-2 font-medium">
                            {{ $line->item?->name ?? '—' }}
                            <span class="ml-1 font-mono text-xs text-slate-500">{{ $line->item?->code }}</span>
                        </td>
                        <td class="text-right">{{ $line->quantity }}</td>
                        <td class="text-right">{{ $line->received_quantity }}</td>
                        <td class="text-right {{ $line->isFullyReceived() ? 'text-emerald-700' : 'text-amber-700' }}">{{ $line->remainingQuantity() }}</td>
                        <td class="text-right font-mono text-xs">{{ $line->unit_price }}</td>
                        <td class="text-right font-mono text-xs">{{ $line->lineTotal() }}</td>
                    </tr>
                @empty
                    <tr><td class="py-4 text-slate-500" colspan="6">This order has no lines.</td></tr>
                @endforelse
            </tbody>
            <tfoot>
                <tr class="border-t font-semibold">
                    <td class="py-2" colspan="5">Order total</td>
                    <td class="text-right font-mono text-xs">{{ $order->total_amount }}</td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<div class="panel mt-6">
    <h2 class="panel-title">Goods receipts</h2>
    <p class="panel-subtitle">Stock booked against this order. Each receipt is a row of the stock ledger and cannot be edited — a correction is a new movement.</p>

    <div class="mt-4 overflow-x-auto">
        <table class="w-full min-w-[44rem] text-left text-sm">
            <thead>
                <tr class="border-b text-slate-500">
                    <th class="py-2">Date</th>
                    <th>Item</th>
                    <th class="text-right">Quantity</th>
                    <th>Reference</th>
                    <th>Recorded by</th>
                </tr>
            </thead>
            <tbody>
                @forelse($order->movements as $movement)
                    <tr class="border-b">
                        <td class="py-2">{{ $movement->movement_date->format('d M Y') }}</td>
                        <td>{{ $movement->item?->name ?? '—' }}</td>
                        <td class="text-right text-emerald-700">+{{ $movement->quantity }}</td>
                        <td class="font-mono text-xs text-slate-600">{{ $movement->reference ?? '—' }}</td>
                        <td class="text-slate-600">{{ $movement->creator?->name ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td class="py-4 text-slate-500" colspan="5">Nothing has been received against this order yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
