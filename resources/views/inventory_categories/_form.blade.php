{{--
    Item category form (create + edit).

    XSS safety: every value is echoed through Blade's {{ }} escaping.
--}}
<div>
    <label class="label" for="name">Name</label>
    <input class="input" id="name" name="name" type="text" value="{{ old('name', $category->name ?? '') }}" required maxlength="255" placeholder="e.g. Stationery">
    <p class="mt-1 text-xs text-rose-600">@error('name'){{ $message }}@enderror</p>
</div>
<div>
    <label class="label" for="code">Code</label>
    <input class="input" id="code" name="code" type="text" value="{{ old('code', $category->code ?? '') }}" required maxlength="50" placeholder="e.g. STN, FUR, LAB">
    <p class="mt-1 text-xs text-slate-500">Unique among this college's active categories. Stored upper-cased.</p>
    <p class="mt-1 text-xs text-rose-600">@error('code'){{ $message }}@enderror</p>
</div>
<div>
    <label class="label" for="status">Status</label>
    <select class="input" id="status" name="status" required>
        @foreach($statuses as $status)
            <option value="{{ $status }}" @selected(old('status', $category->status ?? 'active') === $status)>{{ ucfirst($status) }}</option>
        @endforeach
    </select>
    <p class="mt-1 text-xs text-slate-500">Inactive categories stay on the items that already use them.</p>
    <p class="mt-1 text-xs text-rose-600">@error('status'){{ $message }}@enderror</p>
</div>
<div class="sm:col-span-2">
    <label class="label" for="description">Description</label>
    <textarea class="input" id="description" name="description" rows="2" maxlength="2000">{{ old('description', $category->description ?? '') }}</textarea>
    <p class="mt-1 text-xs text-rose-600">@error('description'){{ $message }}@enderror</p>
</div>
