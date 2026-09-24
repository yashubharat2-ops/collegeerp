{{--
    Hostel form (create + edit).

    XSS safety: every value is echoed through Blade's {{ }} escaping.
--}}
<div>
    <label class="label" for="name">Name</label>
    <input class="input" id="name" name="name" type="text" value="{{ old('name', $hostel->name ?? '') }}" required maxlength="255" placeholder="e.g. Boys Hostel A">
    <p class="mt-1 text-xs text-slate-500">Unique among this college's active hostels.</p>
    <p class="mt-1 text-xs text-rose-600">@error('name'){{ $message }}@enderror</p>
</div>
<div>
    <label class="label" for="code">Code</label>
    <input class="input" id="code" name="code" type="text" value="{{ old('code', $hostel->code ?? '') }}" required maxlength="50" placeholder="e.g. BHA, GHB-01">
    <p class="mt-1 text-xs text-slate-500">Unique per college, including archived records. Stored upper-cased.</p>
    <p class="mt-1 text-xs text-rose-600">@error('code'){{ $message }}@enderror</p>
</div>
<div>
    <label class="label" for="hostel_type">Hostel type</label>
    <select class="input" id="hostel_type" name="hostel_type" required>
        @foreach($types as $type)
            <option value="{{ $type }}" @selected(old('hostel_type', $hostel->hostel_type ?? 'mixed') === $type)>{{ ucfirst($type) }}</option>
        @endforeach
    </select>
    <p class="mt-1 text-xs text-slate-500">The physical category of the hostel.</p>
    <p class="mt-1 text-xs text-rose-600">@error('hostel_type'){{ $message }}@enderror</p>
</div>
<div>
    <label class="label" for="gender">Gender (eligibility)</label>
    <select class="input" id="gender" name="gender" required>
        @foreach($genders as $gender)
            <option value="{{ $gender }}" @selected(old('gender', $hostel->gender ?? 'any') === $gender)>{{ $gender === 'any' ? 'Any' : ucfirst($gender) }}</option>
        @endforeach
    </select>
    <p class="mt-1 text-xs text-slate-500">Which students the hostel is for.</p>
    <p class="mt-1 text-xs text-rose-600">@error('gender'){{ $message }}@enderror</p>
</div>
<div>
    <label class="label" for="status">Status</label>
    <select class="input" id="status" name="status" required>
        @foreach($statuses as $status)
            <option value="{{ $status }}" @selected(old('status', $hostel->status ?? 'active') === $status)>{{ ucfirst($status) }}</option>
        @endforeach
    </select>
    <p class="mt-1 text-xs text-slate-500">Inactive hostels stay on record but are flagged as not in active use.</p>
    <p class="mt-1 text-xs text-rose-600">@error('status'){{ $message }}@enderror</p>
</div>
<div class="sm:col-span-2">
    <label class="label" for="address">Address</label>
    <textarea class="input" id="address" name="address" rows="2" maxlength="2000" placeholder="Optional">{{ old('address', $hostel->address ?? '') }}</textarea>
    <p class="mt-1 text-xs text-rose-600">@error('address'){{ $message }}@enderror</p>
</div>
<div class="sm:col-span-2">
    <label class="label" for="description">Description</label>
    <textarea class="input" id="description" name="description" rows="2" maxlength="2000" placeholder="Facilities, warden notes, cap remarks…">{{ old('description', $hostel->description ?? '') }}</textarea>
    <p class="mt-1 text-xs text-rose-600">@error('description'){{ $message }}@enderror</p>
</div>
