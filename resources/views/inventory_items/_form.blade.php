{{--
    Item / asset form (create + edit). One master covers both types.

    XSS safety: every value is echoed through Blade's {{ }} escaping.
--}}
<div class="sm:col-span-2">
    <label class="label" for="name">Name</label>
    <input class="input" id="name" name="name" type="text" value="{{ old('name', $item->name ?? '') }}" required maxlength="255" placeholder="e.g. A4 Ream, Lab Projector">
    <p class="mt-1 text-xs text-rose-600">@error('name'){{ $message }}@enderror</p>
</div>
<div>
    <label class="label" for="code">Code</label>
    <input class="input" id="code" name="code" type="text" value="{{ old('code', $item->code ?? '') }}" required maxlength="50" placeholder="e.g. ITM-001">
    <p class="mt-1 text-xs text-slate-500">Unique among this college's active items. Stored upper-cased.</p>
    <p class="mt-1 text-xs text-rose-600">@error('code'){{ $message }}@enderror</p>
</div>
<div>
    <label class="label" for="category_id">Category</label>
    <select class="input" id="category_id" name="category_id" required>
        <option value="">Select a category</option>
        @foreach($categories as $category)
            <option value="{{ $category->id }}" @selected((string) old('category_id', $item->category_id ?? '') === (string) $category->id)>
                {{ $category->name }} ({{ $category->code }}){{ $category->status === 'inactive' ? ' — inactive' : '' }}
            </option>
        @endforeach
    </select>
    <p class="mt-1 text-xs text-slate-500">Only categories of the active college can be selected.</p>
    <p class="mt-1 text-xs text-rose-600">@error('category_id'){{ $message }}@enderror</p>
</div>
<div>
    <label class="label" for="item_type">Item type</label>
    <select class="input" id="item_type" name="item_type" required>
        @foreach($types as $type)
            <option value="{{ $type }}" @selected(old('item_type', $item->item_type ?? 'consumable') === $type)>{{ ucfirst($type) }}</option>
        @endforeach
    </select>
    <p class="mt-1 text-xs text-slate-500">Consumables and assets share this master. There is no separate asset catalogue.</p>
    <p class="mt-1 text-xs text-rose-600">@error('item_type'){{ $message }}@enderror</p>
</div>
<div>
    <label class="label" for="status">Status</label>
    <select class="input" id="status" name="status" required>
        @foreach($statuses as $status)
            <option value="{{ $status }}" @selected(old('status', $item->status ?? 'active') === $status)>{{ ucfirst($status) }}</option>
        @endforeach
    </select>
    <p class="mt-1 text-xs text-rose-600">@error('status'){{ $message }}@enderror</p>
</div>
<div>
    <label class="label" for="brand">Brand</label>
    <input class="input" id="brand" name="brand" type="text" value="{{ old('brand', $item->brand ?? '') }}" maxlength="255">
    <p class="mt-1 text-xs text-rose-600">@error('brand'){{ $message }}@enderror</p>
</div>
<div>
    <label class="label" for="model">Model</label>
    <input class="input" id="model" name="model" type="text" value="{{ old('model', $item->model ?? '') }}" maxlength="255">
    <p class="mt-1 text-xs text-rose-600">@error('model'){{ $message }}@enderror</p>
</div>
<div>
    <label class="label" for="serial_number">Serial number</label>
    <input class="input" id="serial_number" name="serial_number" type="text" value="{{ old('serial_number', $item->serial_number ?? '') }}" maxlength="100" placeholder="Optional">
    <p class="mt-1 text-xs text-slate-500">When provided, unique among this college's active items. Stored upper-cased.</p>
    <p class="mt-1 text-xs text-rose-600">@error('serial_number'){{ $message }}@enderror</p>
</div>
<div>
    <label class="label" for="unit">Unit</label>
    <input class="input" id="unit" name="unit" type="text" value="{{ old('unit', $item->unit ?? '') }}" required maxlength="30" placeholder="pcs, nos, kg, set">
    <p class="mt-1 text-xs text-rose-600">@error('unit'){{ $message }}@enderror</p>
</div>
<div>
    <label class="label" for="quantity">Quantity</label>
    <input class="input" id="quantity" name="quantity" type="number" min="0" step="0.01" value="{{ old('quantity', isset($item) ? $item->quantity : '0') }}" required>
    <p class="mt-1 text-xs text-rose-600">@error('quantity'){{ $message }}@enderror</p>
</div>
<div class="sm:col-span-2">
    <label class="label" for="description">Description</label>
    <textarea class="input" id="description" name="description" rows="2" maxlength="5000">{{ old('description', $item->description ?? '') }}</textarea>
    <p class="mt-1 text-xs text-rose-600">@error('description'){{ $message }}@enderror</p>
</div>
