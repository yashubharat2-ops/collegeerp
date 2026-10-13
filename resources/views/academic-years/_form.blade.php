@csrf
<div class="mt-6 grid gap-5 md:grid-cols-2">
    <div>
        <label class="text-sm font-semibold" for="name">Name</label>
        <input class="input mt-1" id="name" name="name" value="{{ old('name', $academicYear->name) }}" placeholder="e.g. 2026–2027" required>
        <p class="mt-1 text-xs text-rose-600">@error('name'){{ $message }}@enderror</p>
    </div>

    <div>
        <label class="text-sm font-semibold" for="code">Code</label>
        <input class="input mt-1" id="code" name="code" value="{{ old('code', $academicYear->code) }}" maxlength="50" placeholder="e.g. 2026-27" required>
        <p class="mt-1 text-xs text-rose-600">@error('code'){{ $message }}@enderror</p>
    </div>

    <div>
        <label class="text-sm font-semibold" for="starts_on">Starts on</label>
        <input class="input mt-1" id="starts_on" name="starts_on" type="date" value="{{ old('starts_on', $academicYear->starts_on?->toDateString()) }}" required>
        <p class="mt-1 text-xs text-rose-600">@error('starts_on'){{ $message }}@enderror</p>
    </div>

    <div>
        <label class="text-sm font-semibold" for="ends_on">Ends on</label>
        <input class="input mt-1" id="ends_on" name="ends_on" type="date" value="{{ old('ends_on', $academicYear->ends_on?->toDateString()) }}" required>
        <p class="mt-1 text-xs text-rose-600">@error('ends_on'){{ $message }}@enderror</p>
    </div>

    <div>
        <label class="text-sm font-semibold" for="status">Status</label>
        <select class="input mt-1" id="status" name="status" required>
            <option value="active" @selected(old('status', $academicYear->status) === 'active')>Active</option>
            <option value="inactive" @selected(old('status', $academicYear->status) === 'inactive')>Inactive</option>
        </select>
        <p class="mt-1 text-xs text-rose-600">@error('status'){{ $message }}@enderror</p>
        <p class="mt-1 text-xs text-slate-500">
            Only one academic year can be active. Making this year active deactivates the
            currently active one. Deactivating the last active year is allowed — enrollment,
            promotion and historical records are unaffected either way.
        </p>
    </div>

    <div class="md:col-span-2 flex gap-2">
        <button class="button" type="submit">{{ $submitLabel }}</button>
        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('academic-years.index') }}">Cancel</a>
    </div>
</div>
