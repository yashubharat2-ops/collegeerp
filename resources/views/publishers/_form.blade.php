{{--
    Publisher form (create + edit).

    XSS safety: every value is echoed through Blade's {{ }} escaping.
--}}
<div>
    <label class="label" for="name">Name</label>
    <input class="input" id="name" name="name" type="text" value="{{ old('name', $publisher->name ?? '') }}" required maxlength="255" placeholder="e.g. Oxford University Press">
    <p class="mt-1 text-xs text-slate-500">Unique among this college's active publishers (case and extra spaces are ignored).</p>
    <p class="mt-1 text-xs text-rose-600">@error('name'){{ $message }}@enderror</p>
</div>
<div>
    <label class="label" for="status">Status</label>
    <select class="input" id="status" name="status" required>
        @foreach($statuses as $status)
            <option value="{{ $status }}" @selected(old('status', $publisher->status ?? 'active') === $status)>{{ ucfirst($status) }}</option>
        @endforeach
    </select>
    <p class="mt-1 text-xs text-rose-600">@error('status'){{ $message }}@enderror</p>
</div>
<div>
    <label class="label" for="email">Email</label>
    <input class="input" id="email" name="email" type="email" value="{{ old('email', $publisher->email ?? '') }}" maxlength="255">
    <p class="mt-1 text-xs text-rose-600">@error('email'){{ $message }}@enderror</p>
</div>
<div>
    <label class="label" for="phone">Phone</label>
    <input class="input" id="phone" name="phone" type="text" value="{{ old('phone', $publisher->phone ?? '') }}" maxlength="30">
    <p class="mt-1 text-xs text-rose-600">@error('phone'){{ $message }}@enderror</p>
</div>
<div>
    <label class="label" for="website">Website</label>
    <input class="input" id="website" name="website" type="url" value="{{ old('website', $publisher->website ?? '') }}" maxlength="255" placeholder="https://">
    <p class="mt-1 text-xs text-rose-600">@error('website'){{ $message }}@enderror</p>
</div>
<div>
    <label class="label" for="address">Address</label>
    <textarea class="input" id="address" name="address" rows="2" maxlength="2000">{{ old('address', $publisher->address ?? '') }}</textarea>
    <p class="mt-1 text-xs text-rose-600">@error('address'){{ $message }}@enderror</p>
</div>
<div class="sm:col-span-2">
    <label class="label" for="description">Notes</label>
    <textarea class="input" id="description" name="description" rows="2" maxlength="2000" placeholder="Imprints, distributor, account notes…">{{ old('description', $publisher->description ?? '') }}</textarea>
    <p class="mt-1 text-xs text-rose-600">@error('description'){{ $message }}@enderror</p>
</div>
