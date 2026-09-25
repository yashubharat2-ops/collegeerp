{{--
    Vendor form (create + edit).

    XSS safety: every value is echoed through Blade's {{ }} escaping.
--}}
<div>
    <label class="label" for="name">Name</label>
    <input class="input" id="name" name="name" type="text" value="{{ old('name', $vendor->name ?? '') }}" required maxlength="255" placeholder="e.g. Campus Supplies Co.">
    <p class="mt-1 text-xs text-rose-600">@error('name'){{ $message }}@enderror</p>
</div>
<div>
    <label class="label" for="code">Code</label>
    <input class="input" id="code" name="code" type="text" value="{{ old('code', $vendor->code ?? '') }}" required maxlength="50" placeholder="e.g. VEN-01">
    <p class="mt-1 text-xs text-slate-500">Unique among this college's active vendors. Stored upper-cased.</p>
    <p class="mt-1 text-xs text-rose-600">@error('code'){{ $message }}@enderror</p>
</div>
<div>
    <label class="label" for="contact_person">Contact person</label>
    <input class="input" id="contact_person" name="contact_person" type="text" value="{{ old('contact_person', $vendor->contact_person ?? '') }}" maxlength="255">
    <p class="mt-1 text-xs text-rose-600">@error('contact_person'){{ $message }}@enderror</p>
</div>
<div>
    <label class="label" for="phone">Phone</label>
    <input class="input" id="phone" name="phone" type="text" value="{{ old('phone', $vendor->phone ?? '') }}" maxlength="30">
    <p class="mt-1 text-xs text-rose-600">@error('phone'){{ $message }}@enderror</p>
</div>
<div>
    <label class="label" for="email">Email</label>
    <input class="input" id="email" name="email" type="email" value="{{ old('email', $vendor->email ?? '') }}" maxlength="255">
    <p class="mt-1 text-xs text-rose-600">@error('email'){{ $message }}@enderror</p>
</div>
<div>
    <label class="label" for="gst_number">GST number</label>
    <input class="input" id="gst_number" name="gst_number" type="text" value="{{ old('gst_number', $vendor->gst_number ?? '') }}" maxlength="20" placeholder="Optional">
    <p class="mt-1 text-xs text-slate-500">Stored upper-cased, without spaces. Not required to be unique.</p>
    <p class="mt-1 text-xs text-rose-600">@error('gst_number'){{ $message }}@enderror</p>
</div>
<div>
    <label class="label" for="status">Status</label>
    <select class="input" id="status" name="status" required>
        @foreach($statuses as $status)
            <option value="{{ $status }}" @selected(old('status', $vendor->status ?? 'active') === $status)>{{ ucfirst($status) }}</option>
        @endforeach
    </select>
    <p class="mt-1 text-xs text-rose-600">@error('status'){{ $message }}@enderror</p>
</div>
<div class="sm:col-span-2">
    <label class="label" for="address">Address</label>
    <textarea class="input" id="address" name="address" rows="2" maxlength="2000">{{ old('address', $vendor->address ?? '') }}</textarea>
    <p class="mt-1 text-xs text-rose-600">@error('address'){{ $message }}@enderror</p>
</div>
