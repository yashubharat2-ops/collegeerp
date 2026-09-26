@extends('layouts.app')

@section('title', 'Record Goods Receipt')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Record Goods Receipt / Stock In</h2>
            <p class="panel-subtitle">Manual stock-in without a purchase order. For PO receipts, use Purchase Orders → Receive goods. Stock can never go negative and the ledger snapshots the balance.</p>
        </div>
        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('inventory-goods-receipts.index') }}">Back</a>
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

    <form method="POST" action="{{ route('inventory-goods-receipts.store') }}" class="mt-6 grid gap-4 sm:grid-cols-2">
        @csrf

        <div class="sm:col-span-2">
            <label class="label" for="item_id">Item</label>
            <select class="input" id="item_id" name="item_id" required>
                <option value="">Select an item</option>
                @foreach($items as $item)
                    <option value="{{ $item->id }}" data-unit="{{ $item->unit }}" data-quantity="{{ $item->quantity }}" @selected($selectedItem === (int) $item->id)>
                        {{ $item->name }} ({{ $item->code }}) — {{ $item->quantity }} {{ $item->unit }} on hand{{ $item->status === 'inactive' ? ' — inactive' : '' }}
                    </option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-slate-500">Only items of the active college can be selected.</p>
            <p class="mt-1 text-xs text-rose-600">@error('item_id'){{ $message }}@enderror</p>
        </div>

        <div>
            <label class="label" for="quantity">Quantity</label>
            <input class="input" id="quantity" name="quantity" type="number" step="0.01" min="0.01" value="{{ old('quantity') }}" required>
            <p class="mt-1 text-xs text-slate-500">Positive number added to stock.</p>
            <p class="mt-1 text-xs text-rose-600">@error('quantity'){{ $message }}@enderror</p>
        </div>

        <div>
            <label class="label" for="unit_price">Unit price (optional)</label>
            <input class="input" id="unit_price" name="unit_price" type="number" step="0.01" min="0" value="{{ old('unit_price') }}">
            <p class="mt-1 text-xs text-rose-600">@error('unit_price'){{ $message }}@enderror</p>
        </div>

        <div>
            <label class="label" for="reference">Reference (optional)</label>
            <input class="input" id="reference" name="reference" type="text" value="{{ old('reference') }}" maxlength="100" placeholder="e.g. GRN-2026-001">
            <p class="mt-1 text-xs text-slate-500">Stored upper-cased.</p>
            <p class="mt-1 text-xs text-rose-600">@error('reference'){{ $message }}@enderror</p>
        </div>

        <div>
            <label class="label" for="movement_date">Movement date</label>
            <input class="input" id="movement_date" name="movement_date" type="date" value="{{ old('movement_date', now()->toDateString()) }}" required>
            <p class="mt-1 text-xs text-rose-600">@error('movement_date'){{ $message }}@enderror</p>
        </div>

        <div>
            <label class="label" for="reason">Reason (optional)</label>
            <input class="input" id="reason" name="reason" type="text" value="{{ old('reason') }}" maxlength="255" placeholder="Why stock was received">
            <p class="mt-1 text-xs text-rose-600">@error('reason'){{ $message }}@enderror</p>
        </div>

        <div class="sm:col-span-2">
            <label class="label" for="notes">Notes</label>
            <textarea class="input" id="notes" name="notes" rows="2" maxlength="2000">{{ old('notes') }}</textarea>
            <p class="mt-1 text-xs text-rose-600">@error('notes'){{ $message }}@enderror</p>
        </div>

        <div class="sm:col-span-2 flex flex-wrap gap-2">
            <button class="button" type="submit">Record goods receipt</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('inventory-goods-receipts.index') }}">Cancel</a>
        </div>
    </form>
</div>
@endsection
