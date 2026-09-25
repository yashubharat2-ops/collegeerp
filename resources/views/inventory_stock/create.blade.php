@extends('layouts.app')

@section('title', 'Record Stock Movement')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Record Stock Movement</h2>
            <p class="panel-subtitle">Stock in, stock out or a correction. The item's on-hand quantity moves in the same transaction as the ledger row, and stock can never go negative.</p>
        </div>
        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('inventory-stock.index') }}">Back</a>
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

    <form method="POST" action="{{ route('inventory-stock.store') }}" class="mt-6 grid gap-4 sm:grid-cols-2">
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
            <label class="label" for="type">Movement type</label>
            <select class="input" id="type" name="type" required>
                @foreach($types as $typeOption)
                    <option value="{{ $typeOption }}" data-direction="{{ $typeOption === 'adjustment' ? '' : ($typeOption === 'stock_out' ? 'out' : 'in') }}" @selected(old('type', 'stock_in') === $typeOption)>
                        {{ ucfirst(str_replace('_', ' ', $typeOption)) }}
                    </option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-slate-500">Stock in and stock out have a fixed direction; a correction has to say which way it goes.</p>
            <p class="mt-1 text-xs text-rose-600">@error('type'){{ $message }}@enderror</p>
        </div>

        <div>
            {{-- The submitted value lives in the hidden input: the visible
                 select is locked (and therefore unsubmitted) whenever the type
                 fixes the direction, so a disabled select must not be the one
                 carrying the name. --}}
            <input id="direction" name="direction" type="hidden" value="{{ old('direction', 'in') }}">
            <label class="label" for="direction_display">Direction</label>
            <select class="input" id="direction_display">
                @foreach($directions as $directionOption)
                    <option value="{{ $directionOption }}">{{ ucfirst($directionOption) }}</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-slate-500">Follows the movement type; only a correction chooses.</p>
            <p class="mt-1 text-xs text-rose-600">@error('direction'){{ $message }}@enderror</p>
        </div>

        <div>
            <label class="label" for="quantity">Quantity</label>
            <input class="input" id="quantity" name="quantity" type="number" step="0.01" min="0.01" value="{{ old('quantity') }}" required>
            <p class="mt-1 text-xs text-slate-500">Always a positive number — the direction decides whether it is added or removed.</p>
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
            <label class="label" for="reason">Reason</label>
            <input class="input" id="reason" name="reason" type="text" value="{{ old('reason') }}" maxlength="255" placeholder="Required for stock out and corrections">
            <p class="mt-1 text-xs text-slate-500">Why the stock moved — required when stock leaves or is corrected.</p>
            <p class="mt-1 text-xs text-rose-600">@error('reason'){{ $message }}@enderror</p>
        </div>

        <div class="sm:col-span-2">
            <label class="label" for="notes">Notes</label>
            <textarea class="input" id="notes" name="notes" rows="2" maxlength="2000">{{ old('notes') }}</textarea>
            <p class="mt-1 text-xs text-rose-600">@error('notes'){{ $message }}@enderror</p>
        </div>

        <div class="sm:col-span-2 flex flex-wrap gap-2">
            <button class="button" type="submit">Record movement</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('inventory-stock.index') }}">Cancel</a>
        </div>
    </form>
</div>
@endsection

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const type = document.getElementById('type');
        const direction = document.getElementById('direction');
        const display = document.getElementById('direction_display');

        // A stock in only ever goes in and a stock out only ever goes out, so
        // the direction follows the type; only a correction is a free choice.
        // The hidden input is what is submitted, so the visible select can be
        // locked without losing the value.
        function syncDirection() {
            if (!type || !direction || !display) {
                return;
            }

            const fixed = type.selectedOptions[0]?.getAttribute('data-direction') || '';

            if (fixed !== '') {
                direction.value = fixed;
            }

            display.value = direction.value;
            display.disabled = fixed !== '';
        }

        if (display) {
            display.addEventListener('change', function () {
                if (direction) {
                    direction.value = display.value;
                }
            });
        }

        if (type) {
            type.addEventListener('change', syncDirection);
            syncDirection();
        }
    });
</script>
@endpush
