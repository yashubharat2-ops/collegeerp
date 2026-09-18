@csrf
<div class="mt-6 grid gap-5 md:grid-cols-2">
    <div>
        <label class="text-sm font-semibold" for="first_name">First Name *</label>
        <input class="input mt-1" id="first_name" name="first_name" value="{{ old('first_name', $student->first_name ?? '') }}" required maxlength="255">
        <p class="mt-1 text-xs text-rose-600">@error('first_name'){{ $message }}@enderror</p>
    </div>
    <div>
        <label class="text-sm font-semibold" for="middle_name">Middle Name</label>
        <input class="input mt-1" id="middle_name" name="middle_name" value="{{ old('middle_name', $student->middle_name ?? '') }}" maxlength="255">
        <p class="mt-1 text-xs text-rose-600">@error('middle_name'){{ $message }}@enderror</p>
    </div>
    <div>
        <label class="text-sm font-semibold" for="last_name">Last Name</label>
        <input class="input mt-1" id="last_name" name="last_name" value="{{ old('last_name', $student->last_name ?? '') }}" maxlength="255">
        <p class="mt-1 text-xs text-rose-600">@error('last_name'){{ $message }}@enderror</p>
    </div>
    <div>
        <label class="text-sm font-semibold" for="email">Email</label>
        <input class="input mt-1" id="email" name="email" type="email" value="{{ old('email', $student->email ?? '') }}" maxlength="255">
        <p class="mt-1 text-xs text-rose-600">@error('email'){{ $message }}@enderror</p>
    </div>
    <div>
        <label class="text-sm font-semibold" for="phone">Phone</label>
        <input class="input mt-1" id="phone" name="phone" value="{{ old('phone', $student->phone ?? '') }}" maxlength="30">
        <p class="mt-1 text-xs text-rose-600">@error('phone'){{ $message }}@enderror</p>
    </div>
    <div>
        <label class="text-sm font-semibold" for="alternate_phone">Alternate Phone</label>
        <input class="input mt-1" id="alternate_phone" name="alternate_phone" value="{{ old('alternate_phone', $student->alternate_phone ?? '') }}" maxlength="30">
        <p class="mt-1 text-xs text-rose-600">@error('alternate_phone'){{ $message }}@enderror</p>
    </div>
    <div>
        <label class="text-sm font-semibold" for="gender">Gender</label>
        <select class="input mt-1" id="gender" name="gender">
            <option value="">— Select —</option>
            <option value="male" @selected(old('gender', $student->gender ?? '') === 'male')>Male</option>
            <option value="female" @selected(old('gender', $student->gender ?? '') === 'female')>Female</option>
            <option value="other" @selected(old('gender', $student->gender ?? '') === 'other')>Other</option>
            <option value="prefer_not_to_say" @selected(old('gender', $student->gender ?? '') === 'prefer_not_to_say')>Prefer not to say</option>
        </select>
        <p class="mt-1 text-xs text-rose-600">@error('gender'){{ $message }}@enderror</p>
    </div>
    <div>
        <label class="text-sm font-semibold" for="date_of_birth">Date of Birth</label>
        <input class="input mt-1" id="date_of_birth" name="date_of_birth" type="date" value="{{ old('date_of_birth', isset($student->date_of_birth) ? $student->date_of_birth->format('Y-m-d') : '') }}">
        <p class="mt-1 text-xs text-rose-600">@error('date_of_birth'){{ $message }}@enderror</p>
    </div>
    <div>
        <label class="text-sm font-semibold" for="admission_date">Admission Date</label>
        <input class="input mt-1" id="admission_date" name="admission_date" type="date" value="{{ old('admission_date', isset($student->admission_date) ? $student->admission_date->format('Y-m-d') : now()->format('Y-m-d')) }}">
        <p class="mt-1 text-xs text-rose-600">@error('admission_date'){{ $message }}@enderror</p>
    </div>
    <div>
        <label class="text-sm font-semibold" for="status">Status *</label>
        <select class="input mt-1" id="status" name="status" required>
            <option value="">— Select —</option>
            @foreach(['active','inactive','graduated','suspended','withdrawn'] as $s)
                <option value="{{ $s }}" @selected(old('status', $student->status ?? 'active') === $s)>{{ ucfirst($s) }}</option>
            @endforeach
        </select>
        <p class="mt-1 text-xs text-rose-600">@error('status'){{ $message }}@enderror</p>
    </div>
    <div class="md:col-span-2">
        <label class="text-sm font-semibold" for="address_line_1">Address Line 1</label>
        <input class="input mt-1" id="address_line_1" name="address_line_1" value="{{ old('address_line_1', $student->address_line_1 ?? '') }}" maxlength="2000">
        <p class="mt-1 text-xs text-rose-600">@error('address_line_1'){{ $message }}@enderror</p>
    </div>
    <div class="md:col-span-2">
        <label class="text-sm font-semibold" for="address_line_2">Address Line 2</label>
        <input class="input mt-1" id="address_line_2" name="address_line_2" value="{{ old('address_line_2', $student->address_line_2 ?? '') }}" maxlength="2000">
        <p class="mt-1 text-xs text-rose-600">@error('address_line_2'){{ $message }}@enderror</p>
    </div>
    <div>
        <label class="text-sm font-semibold" for="city">City</label>
        <input class="input mt-1" id="city" name="city" value="{{ old('city', $student->city ?? '') }}" maxlength="100">
        <p class="mt-1 text-xs text-rose-600">@error('city'){{ $message }}@enderror</p>
    </div>
    <div>
        <label class="text-sm font-semibold" for="state">State</label>
        <input class="input mt-1" id="state" name="state" value="{{ old('state', $student->state ?? '') }}" maxlength="100">
        <p class="mt-1 text-xs text-rose-600">@error('state'){{ $message }}@enderror</p>
    </div>
    <div>
        <label class="text-sm font-semibold" for="postal_code">Postal Code</label>
        <input class="input mt-1" id="postal_code" name="postal_code" value="{{ old('postal_code', $student->postal_code ?? '') }}" maxlength="20">
        <p class="mt-1 text-xs text-rose-600">@error('postal_code'){{ $message }}@enderror</p>
    </div>
    <div>
        <label class="text-sm font-semibold" for="country">Country</label>
        <input class="input mt-1" id="country" name="country" value="{{ old('country', $student->country ?? '') }}" maxlength="100">
        <p class="mt-1 text-xs text-rose-600">@error('country'){{ $message }}@enderror</p>
    </div>
    <div class="md:col-span-2 flex gap-2">
        <button class="button" type="submit">{{ $submitLabel }}</button>
        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('students.index') }}">Cancel</a>
    </div>
</div>
