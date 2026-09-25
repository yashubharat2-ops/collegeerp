@extends('layouts.app')

@section('title', 'Receive Goods — '.$order->number)

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Receive Goods — {{ $order->number }}</h2>
            <p class="panel-subtitle">{{ $order->vendor?->name ?? '—' }} · booked as stock in against this order, raising each item's on-hand quantity.</p>
        </div>
        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('inventory-purchase-orders.show', $order) }}">Back</a>
    </div>

    @if($errors->any())
        <div class="alert-error mt-4">
            <ul class="list-inside list-disc space-y-1">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @php
        $outstanding = $order->lines->reject(fn ($line) => $line->isFullyReceived())->values();
    @endphp

    <form method="POST" action="{{ route('inventory-purchase-orders.receive.store', $order) }}" class="mt-6">
        @csrf

        <div class="grid gap-4 sm:grid-cols-3">
            <div>
                <label class="label" for="reference">GRN / challan number (optional)</label>
                <input class="input" id="reference" name="reference" type="text" value="{{ old('reference') }}" maxlength="100" placeholder="e.g. GRN-2026-001">
                <p class="mt-1 text-xs text-slate-500">Stored upper-cased and kept on every movement this receipt writes.</p>
                <p class="mt-1 text-xs text-rose-600">@error('reference'){{ $message }}@enderror</p>
            </div>
            <div>
                <label class="label" for="movement_date">Receipt date</label>
                <input class="input" id="movement_date" name="movement_date" type="date" value="{{ old('movement_date', now()->toDateString()) }}" required>
                <p class="mt-1 text-xs text-rose-600">@error('movement_date'){{ $message }}@enderror</p>
            </div>
            <div>
                <label class="label" for="notes">Notes</label>
                <input class="input" id="notes" name="notes" type="text" value="{{ old('notes') }}" maxlength="2000">
                <p class="mt-1 text-xs text-rose-600">@error('notes'){{ $message }}@enderror</p>
            </div>
        </div>

        <div class="mt-6 overflow-x-auto">
            <table class="w-full min-w-[44rem] text-left text-sm">
                <thead>
                    <tr class="border-b text-slate-500">
                        <th class="py-2">Item</th>
                        <th class="text-right">Ordered</th>
                        <th class="text-right">Received so far</th>
                        <th class="text-right">Outstanding</th>
                        <th class="text-right">Receiving now</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($outstanding as $index => $line)
                        <tr class="border-b">
                            <td class="py-2 font-medium">
                                {{ $line->item?->name ?? '—' }}
                                <span class="ml-1 font-mono text-xs text-slate-500">{{ $line->item?->code }}</span>
                                <input type="hidden" name="receipts[{{ $index }}][line_id]" value="{{ $line->id }}">
                            </td>
                            <td class="text-right">{{ $line->quantity }}</td>
                            <td class="text-right">{{ $line->received_quantity }}</td>
                            <td class="text-right text-amber-700">{{ $line->remainingQuantity() }}</td>
                            <td class="py-2 text-right">
                                <input class="input !w-32 text-right" type="number" step="0.01" min="0.01" max="{{ $line->remainingQuantity() }}"
                                       name="receipts[{{ $index }}][quantity]" value="{{ old("receipts.{$index}.quantity") }}" placeholder="0.00">
                                <p class="mt-1 text-xs text-rose-600">@error("receipts.{$index}.quantity"){{ $message }}@enderror</p>
                            </td>
                        </tr>
                    @empty
                        <tr><td class="py-4 text-slate-500" colspan="5">Every line of this order has already been received in full.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <p class="mt-2 text-xs text-rose-600">@error('receipts'){{ $message }}@enderror</p>
        <p class="mt-2 text-xs text-slate-500">Leave a line blank if nothing arrived on it. A line can never receive more than its outstanding quantity, and the order's status follows the lines.</p>

        <div class="mt-6 flex flex-wrap gap-2">
            <button class="button" type="submit">Book receipt</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('inventory-purchase-orders.show', $order) }}">Cancel</a>
        </div>
    </form>
</div>
@endsection
