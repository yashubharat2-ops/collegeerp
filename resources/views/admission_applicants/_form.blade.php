@csrf
<div class="mt-6 grid gap-5 md:grid-cols-2">
    <div>
        <label class="text-sm font-semibold" for="first_name">First Name *</label>
        <input class="input mt-1" id="first_name" name="first_name" value="{{ old('first_name', $applicant->first_name ?? '') }}" required maxlength="255">
        <p class="mt-1 text-xs text-rose-600">@error('first_name'){{ $message }}@enderror</p>
    </div>
    <div>
        <label class="text-sm font-semibold" for="middle_name">Middle Name</label>
        <input class="input mt-1" id="middle_name" name="middle_name" value="{{ old('middle_name', $applicant->middle_name ?? '') }}" maxlength="255">
        <p class="mt-1 text-xs text-rose-600">@error('middle_name'){{ $message }}@enderror</p>
    </div>
    <div>
        <label class="text-sm font-semibold" for="last_name">Last Name</label>
        <input class="input mt-1" id="last_name" name="last_name" value="{{ old('last_name', $applicant->last_name ?? '') }}" maxlength="255" placeholder="Optional for walk-in enquiry">
        <p class="mt-1 text-xs text-rose-600">@error('last_name'){{ $message }}@enderror</p>
    </div>
    <div>
        <label class="text-sm font-semibold" for="email">Email</label>
        <input class="input mt-1" id="email" name="email" type="email" value="{{ old('email', $applicant->email ?? '') }}" maxlength="255">
        <p class="mt-1 text-xs text-rose-600">@error('email'){{ $message }}@enderror</p>
    </div>
    <div>
        <label class="text-sm font-semibold" for="phone">Phone</label>
        <input class="input mt-1" id="phone" name="phone" value="{{ old('phone', $applicant->phone ?? '') }}" maxlength="30" placeholder="e.g. 9999999999">
        <p class="mt-1 text-xs text-rose-600">@error('phone'){{ $message }}@enderror</p>
    </div>
    <div>
        <label class="text-sm font-semibold" for="alternate_phone">Alternate Phone</label>
        <input class="input mt-1" id="alternate_phone" name="alternate_phone" value="{{ old('alternate_phone', $applicant->alternate_phone ?? '') }}" maxlength="30">
        <p class="mt-1 text-xs text-rose-600">@error('alternate_phone'){{ $message }}@enderror</p>
    </div>
    <div>
        <label class="text-sm font-semibold" for="gender">Gender</label>
        <select class="input mt-1" id="gender" name="gender">
            <option value="">— Select —</option>
            <option value="male" @selected(old('gender', $applicant->gender ?? '') === 'male')>Male</option>
            <option value="female" @selected(old('gender', $applicant->gender ?? '') === 'female')>Female</option>
            <option value="other" @selected(old('gender', $applicant->gender ?? '') === 'other')>Other</option>
            <option value="prefer_not_to_say" @selected(old('gender', $applicant->gender ?? '') === 'prefer_not_to_say')>Prefer not to say</option>
        </select>
        <p class="mt-1 text-xs text-rose-600">@error('gender'){{ $message }}@enderror</p>
    </div>
    <div>
        <label class="text-sm font-semibold" for="date_of_birth">Date of Birth</label>
        <input class="input mt-1" id="date_of_birth" name="date_of_birth" type="date" value="{{ old('date_of_birth', isset($applicant->date_of_birth) ? $applicant->date_of_birth->format('Y-m-d') : '') }}">
        <p class="mt-1 text-xs text-rose-600">@error('date_of_birth'){{ $message }}@enderror</p>
    </div>
    <div>
        <label class="text-sm font-semibold" for="status">Status *</label>
        <select class="input mt-1" id="status" name="status" required>
            <option value="active" @selected(old('status', $applicant->status ?? 'active') === 'active')>Active</option>
            <option value="inactive" @selected(old('status', $applicant->status ?? 'active') === 'inactive')>Inactive</option>
        </select>
        <p class="mt-1 text-xs text-rose-600">@error('status'){{ $message }}@enderror</p>
    </div>
    <div class="md:col-span-2">
        <label class="text-sm font-semibold" for="address">Address</label>
        <textarea class="input mt-1" id="address" name="address" rows="3" maxlength="2000">{{ old('address', $applicant->address ?? '') }}</textarea>
        <p class="mt-1 text-xs text-rose-600">@error('address'){{ $message }}@enderror</p>
    </div>
    <div class="md:col-span-2 flex gap-2">
        <button class="button" type="submit">{{ $submitLabel }}</button>
        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('admission-applicants.index') }}">Cancel</a>
    </div>
</div>
