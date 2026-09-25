{{--
    Purchase order form (create + edit).

    Order lines are posted as an indexed array; InventoryPurchaseOrderService
    re-validates the rows server-side (an item of the active college per line,
    a positive quantity, no item twice), so the browser is never trusted. The
    order total is computed from these lines and is never posted.

    A submitted order cannot be edited at all — the service refuses it, and the
    edit screen is only ever reached for a draft.

    XSS safety: every value is echoed through Blade's {{ }} escaping.
--}}
<div>
    <label class="label" for="number">Order number</label>
    <input class="input" id="number" name="number" type="text" value="{{ old('number', $order->number ?? '') }}" required maxlength="50" placeholder="e.g. PO-2026-001">
    <p class="mt-1 text-xs text-slate-500">Unique among this college's active orders. Stored upper-cased.</p>
    <p class="mt-1 text-xs text-rose-600">@error('number'){{ $message }}@enderror</p>
</div>
<div>
    <label class="label" for="vendor_id">Vendor</label>
    <select class="input" id="vendor_id" name="vendor_id" required>
        <option value="">Select a vendor</option>
        @foreach($vendors as $vendor)
            <option value="{{ $vendor->id }}" @selected((string) old('vendor_id', $order->vendor_id ?? '') === (string) $vendor->id)>
                {{ $vendor->name }} ({{ $vendor->code }}){{ $vendor->status === 'inactive' ? ' — inactive' : '' }}
            </option>
        @endforeach
    </select>
    <p class="mt-1 text-xs text-slate-500">Only vendors of the active college can be selected.</p>
    <p class="mt-1 text-xs text-rose-600">@error('vendor_id'){{ $message }}@enderror</p>
</div>
<div>
    <label class="label" for="po_date">Order date</label>
    <input class="input" id="po_date" name="po_date" type="date" value="{{ old('po_date', isset($order) ? $order->po_date->toDateString() : now()->toDateString()) }}" required>
    <p class="mt-1 text-xs text-rose-600">@error('po_date'){{ $message }}@enderror</p>
</div>
<div>
    <label class="label" for="expected_date">Expected delivery (optional)</label>
    <input class="input" id="expected_date" name="expected_date" type="date" value="{{ old('expected_date', isset($order) && $order->expected_date ? $order->expected_date->toDateString() : '') }}">
    <p class="mt-1 text-xs text-slate-500">Must be on or after the order date.</p>
    <p class="mt-1 text-xs text-rose-600">@error('expected_date'){{ $message }}@enderror</p>
</div>
<div class="sm:col-span-2">
    <label class="label" for="notes">Notes</label>
    <textarea class="input" id="notes" name="notes" rows="2" maxlength="5000">{{ old('notes', $order->notes ?? '') }}</textarea>
    <p class="mt-1 text-xs text-rose-600">@error('notes'){{ $message }}@enderror</p>
</div>

<div class="sm:col-span-2">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h3 class="text-sm font-semibold text-slate-900">Ordered items</h3>
            <p class="mt-1 text-xs text-slate-500">
                One row per item. An item may appear only once on an order, the quantity must be greater than zero,
                and the order total is the sum of these lines.
            </p>
        </div>
        <button class="button !bg-slate-200 !text-slate-700" id="purchase-order-add-line" type="button">+ Add line</button>
    </div>

    @php
        $rows = old('lines', isset($order) ? $order->lines->map(fn ($line) => [
            'item_id' => $line->item_id,
            'quantity' => $line->quantity,
            'unit_price' => $line->unit_price,
        ])->all() : [['item_id' => '', 'quantity' => '', 'unit_price' => '']]);
    @endphp

    <p class="mt-2 text-xs text-rose-600">@error('lines'){{ $message }}@enderror</p>

    <div class="mt-3 overflow-x-auto">
        <table class="w-full text-left text-sm" id="purchase-order-lines">
            <thead>
                <tr class="border-b text-slate-500">
                    <th class="py-2">Item</th>
                    <th>Ordered quantity</th>
                    <th>Unit price</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach($rows as $index => $row)
                    <tr class="border-b">
                        <td class="py-2 pr-2">
                            <select class="input" name="lines[{{ $index }}][item_id]" required>
                                <option value="">Select an item</option>
                                @foreach($items as $item)
                                    <option value="{{ $item->id }}" @selected((string) ($row['item_id'] ?? '') === (string) $item->id)>
                                        {{ $item->name }} ({{ $item->code }}){{ $item->status === 'inactive' ? ' — inactive' : '' }}
                                    </option>
                                @endforeach
                            </select>
                            <p class="mt-1 text-xs text-rose-600">@error("lines.{$index}.item_id"){{ $message }}@enderror</p>
                        </td>
                        <td class="py-2 pr-2">
                            <input class="input !w-36" type="number" step="0.01" min="0.01" name="lines[{{ $index }}][quantity]" required value="{{ $row['quantity'] ?? '' }}">
                            <p class="mt-1 text-xs text-rose-600">@error("lines.{$index}.quantity"){{ $message }}@enderror</p>
                        </td>
                        <td class="py-2 pr-2">
                            <input class="input !w-36" type="number" step="0.01" min="0" name="lines[{{ $index }}][unit_price]" required value="{{ $row['unit_price'] ?? '' }}">
                            <p class="mt-1 text-xs text-rose-600">@error("lines.{$index}.unit_price"){{ $message }}@enderror</p>
                        </td>
                        <td class="py-2 text-right">
                            <button class="button !bg-rose-100 !text-rose-700" type="button" data-remove-line>Remove</button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <template id="purchase-order-line-template">
        <tr class="border-b">
            <td class="py-2 pr-2">
                <select class="input" name="lines[__INDEX__][item_id]" required>
                    <option value="">Select an item</option>
                    @foreach($items as $item)
                        <option value="{{ $item->id }}">{{ $item->name }} ({{ $item->code }}){{ $item->status === 'inactive' ? ' — inactive' : '' }}</option>
                    @endforeach
                </select>
            </td>
            <td class="py-2 pr-2"><input class="input !w-36" type="number" step="0.01" min="0.01" name="lines[__INDEX__][quantity]" required></td>
            <td class="py-2 pr-2"><input class="input !w-36" type="number" step="0.01" min="0" name="lines[__INDEX__][unit_price]" required></td>
            <td class="py-2 text-right"><button class="button !bg-rose-100 !text-rose-700" type="button" data-remove-line>Remove</button></td>
        </tr>
    </template>
</div>

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const table = document.getElementById('purchase-order-lines');
        const addButton = document.getElementById('purchase-order-add-line');
        const template = document.getElementById('purchase-order-line-template');

        if (table && addButton && template) {
            let nextIndex = {{ count($rows) }};

            addButton.addEventListener('click', function () {
                const markup = template.innerHTML.replace(/__INDEX__/g, nextIndex++);
                const row = document.createElement('tbody');
                row.innerHTML = markup.trim();
                table.querySelector('tbody').appendChild(row.firstElementChild);
            });

            table.addEventListener('click', function (event) {
                const remove = event.target.closest('[data-remove-line]');

                // The last line stays so an order can never be saved without one.
                if (remove && table.querySelectorAll('tbody tr').length > 1) {
                    remove.closest('tr').remove();
                }
            });
        }
    });
</script>
@endpush
