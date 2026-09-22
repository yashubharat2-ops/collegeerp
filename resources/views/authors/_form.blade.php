{{--
    Author form (create + edit).

    XSS safety: every value is echoed through Blade's {{ }} escaping.
--}}
<div>
    <label class="label" for="name">Name</label>
    <input class="input" id="name" name="name" type="text" value="{{ old('name', $author->name ?? '') }}" required maxlength="255" placeholder="e.g. Jane Austen">
    <p class="mt-1 text-xs text-slate-500">Unique among this college's active authors (case and extra spaces are ignored).</p>
    <p class="mt-1 text-xs text-rose-600">@error('name'){{ $message }}@enderror</p>
</div>
<div>
    <label class="label" for="status">Status</label>
    <select class="input" id="status" name="status" required>
        @foreach($statuses as $status)
            <option value="{{ $status }}" @selected(old('status', $author->status ?? 'active') === $status)>{{ ucfirst($status) }}</option>
        @endforeach
    </select>
    <p class="mt-1 text-xs text-rose-600">@error('status'){{ $message }}@enderror</p>
</div>
<div class="sm:col-span-2">
    <label class="label" for="description">About</label>
    <textarea class="input" id="description" name="description" rows="3" maxlength="2000" placeholder="Short biography, pen names, notes for cataloguers…">{{ old('description', $author->description ?? '') }}</textarea>
    <p class="mt-1 text-xs text-rose-600">@error('description'){{ $message }}@enderror</p>
</div>
