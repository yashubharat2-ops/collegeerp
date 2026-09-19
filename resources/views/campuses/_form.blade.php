@csrf
<div class="mt-6 grid gap-5 md:grid-cols-2">
    <div>
        <label class="text-sm font-semibold" for="name">Name</label>
        <input class="input mt-1" id="name" name="name" value="{{ old('name', $campus->name ?? '') }}" required>
        <p class="mt-1 text-xs text-rose-600">@error('name'){{ $message }}@enderror</p>
    </div>
    <div>
        <label class="text-sm font-semibold" for="code">Code</label>
        <input class="input mt-1" id="code" name="code" value="{{ old('code', $campus->code ?? '') }}" maxlength="50" required>
        <p class="mt-1 text-xs text-rose-600">@error('code'){{ $message }}@enderror</p>
    </div>
    <div>
        <label class="text-sm font-semibold" for="short_name">Short name</label>
        <input class="input mt-1" id="short_name" name="short_name" value="{{ old('short_name', $campus->short_name ?? '') }}" maxlength="50">
        <p class="mt-1 text-xs text-rose-600">@error('short_name'){{ $message }}@enderror</p>
    </div>
    <div>
        <label class="text-sm font-semibold" for="status">Status</label>
        <select class="input mt-1" id="status" name="status" required>
            <option value="active" @selected(old('status', $campus->status ?? 'active') === 'active')>Active</option>
            <option value="inactive" @selected(old('status', $campus->status ?? 'active') === 'inactive')>Inactive</option>
        </select>
        <p class="mt-1 text-xs text-rose-600">@error('status'){{ $message }}@enderror</p>
    </div>
    <div class="md:col-span-2">
        <label class="text-sm font-semibold" for="address">Address</label>
        <input class="input mt-1" id="address" name="address" value="{{ old('address', $campus->address ?? '') }}" maxlength="1000">
        <p class="mt-1 text-xs text-rose-600">@error('address'){{ $message }}@enderror</p>
    </div>
    <div>
        <label class="text-sm font-semibold" for="city">City</label>
        <input class="input mt-1" id="city" name="city" value="{{ old('city', $campus->city ?? '') }}" maxlength="100">
        <p class="mt-1 text-xs text-rose-600">@error('city'){{ $message }}@enderror</p>
    </div>
    <div>
        <label class="text-sm font-semibold" for="state">State</label>
        <input class="input mt-1" id="state" name="state" value="{{ old('state', $campus->state ?? '') }}" maxlength="100">
        <p class="mt-1 text-xs text-rose-600">@error('state'){{ $message }}@enderror</p>
    </div>
    <div>
        <label class="text-sm font-semibold" for="pincode">Pincode</label>
        <input class="input mt-1" id="pincode" name="pincode" value="{{ old('pincode', $campus->pincode ?? '') }}" maxlength="20">
        <p class="mt-1 text-xs text-rose-600">@error('pincode'){{ $message }}@enderror</p>
    </div>
    <div>
        <label class="text-sm font-semibold" for="phone">Phone</label>
        <input class="input mt-1" id="phone" name="phone" value="{{ old('phone', $campus->phone ?? '') }}" maxlength="30">
        <p class="mt-1 text-xs text-rose-600">@error('phone'){{ $message }}@enderror</p>
    </div>
    <div>
        <label class="text-sm font-semibold" for="email">Email</label>
        <input class="input mt-1" id="email" name="email" type="email" value="{{ old('email', $campus->email ?? '') }}" maxlength="255">
        <p class="mt-1 text-xs text-rose-600">@error('email'){{ $message }}@enderror</p>
    </div>
    <div class="md:col-span-2">
        <label class="text-sm font-semibold" for="description">Description (optional)</label>
        <textarea class="input mt-1" id="description" name="description" rows="3" maxlength="2000">{{ old('description', $campus->description ?? '') }}</textarea>
        <p class="mt-1 text-xs text-rose-600">@error('description'){{ $message }}@enderror</p>
    </div>
    <div class="md:col-span-2 flex gap-2">
        <button class="button" type="submit">{{ $submitLabel }}</button>
        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('campuses.index') }}">Cancel</a>
    </div>
</div>
