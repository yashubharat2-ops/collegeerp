@csrf
@php($routePrefix = ($isHr ?? false) ? 'employees' : 'faculties')
<div class="mt-6 grid gap-5 md:grid-cols-2">
    <div>
        <label class="text-sm font-semibold" for="employee_code">Employee Code</label>
        <input class="input mt-1" id="employee_code" name="employee_code" value="{{ old('employee_code', $faculty->employee_code ?? '') }}" maxlength="50" placeholder="e.g. EMP-101" required>
        <p class="mt-1 text-xs text-rose-600">@error('employee_code'){{ $message }}@enderror</p>
    </div>
    <div>
        <label class="text-sm font-semibold" for="status">Status</label>
        <select class="input mt-1" id="status" name="status" required>
            <option value="active" @selected(old('status', $faculty->status ?? 'active') === 'active')>Active</option>
            <option value="inactive" @selected(old('status', $faculty->status ?? 'active') === 'inactive')>Inactive</option>
        </select>
        <p class="mt-1 text-xs text-rose-600">@error('status'){{ $message }}@enderror</p>
    </div>

    <div><label class="text-sm font-semibold" for="first_name">First Name</label><input class="input mt-1" id="first_name" name="first_name" value="{{ old('first_name', $faculty->first_name ?? '') }}" required><p class="mt-1 text-xs text-rose-600">@error('first_name'){{ $message }}@enderror</p></div>
    <div><label class="text-sm font-semibold" for="last_name">Last Name</label><input class="input mt-1" id="last_name" name="last_name" value="{{ old('last_name', $faculty->last_name ?? '') }}" required><p class="mt-1 text-xs text-rose-600">@error('last_name'){{ $message }}@enderror</p></div>
    <div><label class="text-sm font-semibold" for="middle_name">Middle Name (optional)</label><input class="input mt-1" id="middle_name" name="middle_name" value="{{ old('middle_name', $faculty->middle_name ?? '') }}"><p class="mt-1 text-xs text-rose-600">@error('middle_name'){{ $message }}@enderror</p></div>

    <div><label class="text-sm font-semibold" for="email">Email (optional)</label><input class="input mt-1" id="email" name="email" type="email" value="{{ old('email', $faculty->email ?? '') }}"><p class="mt-1 text-xs text-rose-600">@error('email'){{ $message }}@enderror</p></div>
    <div><label class="text-sm font-semibold" for="phone">Phone (optional)</label><input class="input mt-1" id="phone" name="phone" value="{{ old('phone', $faculty->phone ?? '') }}" maxlength="50"><p class="mt-1 text-xs text-rose-600">@error('phone'){{ $message }}@enderror</p></div>
    <div><label class="text-sm font-semibold" for="alternate_phone">Alternate Phone (optional)</label><input class="input mt-1" id="alternate_phone" name="alternate_phone" value="{{ old('alternate_phone', $faculty->alternate_phone ?? '') }}" maxlength="50"><p class="mt-1 text-xs text-rose-600">@error('alternate_phone'){{ $message }}@enderror</p></div>
    <div><label class="text-sm font-semibold" for="gender">Gender (optional)</label><input class="input mt-1" id="gender" name="gender" value="{{ old('gender', $faculty->gender ?? '') }}" maxlength="30"><p class="mt-1 text-xs text-rose-600">@error('gender'){{ $message }}@enderror</p></div>
    <div><label class="text-sm font-semibold" for="date_of_birth">Date of Birth (optional)</label><input class="input mt-1" id="date_of_birth" name="date_of_birth" type="date" value="{{ old('date_of_birth', isset($faculty->date_of_birth) ? $faculty->date_of_birth?->format('Y-m-d') : '') }}"><p class="mt-1 text-xs text-rose-600">@error('date_of_birth'){{ $message }}@enderror</p></div>

    <div>
        <label class="text-sm font-semibold" for="department_id">Department (optional)</label>
        <select class="input mt-1" id="department_id" name="department_id"><option value="">College level (no department)</option>@foreach($departments as $dept)<option value="{{ $dept->id }}" @selected((int) old('department_id', $faculty->department_id ?? 0) === $dept->id)>{{ $dept->name }} ({{ $dept->code }})</option>@endforeach</select>
        <p class="mt-1 text-xs text-rose-600">@error('department_id'){{ $message }}@enderror</p>
    </div>
    <div>
        <label class="text-sm font-semibold" for="designation_id">Designation (optional)</label>
        <select class="input mt-1" id="designation_id" name="designation_id"><option value="">Select designation</option>@foreach($designations as $designation)<option value="{{ $designation->id }}" @selected((int) old('designation_id', $faculty->designation_id ?? 0) === $designation->id)>{{ $designation->name }} ({{ $designation->code }}){{ $designation->status === 'inactive' ? ' — inactive' : '' }}</option>@endforeach</select>
        <p class="mt-1 text-xs text-rose-600">@error('designation_id'){{ $message }}@enderror</p>
    </div>
    <div>
        <label class="text-sm font-semibold" for="designation">Legacy/custom designation label (optional)</label>
        <input class="input mt-1" id="designation" name="designation" value="{{ old('designation', $faculty->designation ?? '') }}" maxlength="100" placeholder="Used only when no master is selected">
        <p class="mt-1 text-xs text-rose-600">@error('designation'){{ $message }}@enderror</p>
    </div>
    <div>
        <label class="text-sm font-semibold" for="employment_type">Employment Type (optional)</label>
        <select class="input mt-1" id="employment_type" name="employment_type"><option value="">Select employment type</option>@foreach($employmentTypes as $type)<option value="{{ $type }}" @selected(old('employment_type', $faculty->employment_type ?? '') === $type)>{{ ucwords(str_replace('_', ' ', $type)) }}</option>@endforeach</select>
        <p class="mt-1 text-xs text-rose-600">@error('employment_type'){{ $message }}@enderror</p>
    </div>
    <div><label class="text-sm font-semibold" for="joining_date">Joining Date (optional)</label><input class="input mt-1" id="joining_date" name="joining_date" type="date" value="{{ old('joining_date', isset($faculty->joining_date) ? $faculty->joining_date?->format('Y-m-d') : '') }}"><p class="mt-1 text-xs text-rose-600">@error('joining_date'){{ $message }}@enderror</p></div>
    <div><label class="text-sm font-semibold" for="employment_end_date">Employment End Date (optional)</label><input class="input mt-1" id="employment_end_date" name="employment_end_date" type="date" value="{{ old('employment_end_date', isset($faculty->employment_end_date) ? $faculty->employment_end_date?->format('Y-m-d') : '') }}"><p class="mt-1 text-xs text-rose-600">@error('employment_end_date'){{ $message }}@enderror</p></div>

    <div class="md:col-span-2 border-t border-slate-200 pt-4"><h3 class="font-semibold">Address</h3></div>
    <div><label class="text-sm font-semibold" for="address_line_1">Address line 1</label><input class="input mt-1" id="address_line_1" name="address_line_1" value="{{ old('address_line_1', $faculty->address_line_1 ?? '') }}"></div>
    <div><label class="text-sm font-semibold" for="address_line_2">Address line 2</label><input class="input mt-1" id="address_line_2" name="address_line_2" value="{{ old('address_line_2', $faculty->address_line_2 ?? '') }}"></div>
    <div><label class="text-sm font-semibold" for="city">City</label><input class="input mt-1" id="city" name="city" value="{{ old('city', $faculty->city ?? '') }}"></div>
    <div><label class="text-sm font-semibold" for="state">State / Province</label><input class="input mt-1" id="state" name="state" value="{{ old('state', $faculty->state ?? '') }}"></div>
    <div><label class="text-sm font-semibold" for="postal_code">Postal Code</label><input class="input mt-1" id="postal_code" name="postal_code" value="{{ old('postal_code', $faculty->postal_code ?? '') }}"></div>
    <div><label class="text-sm font-semibold" for="country">Country</label><input class="input mt-1" id="country" name="country" value="{{ old('country', $faculty->country ?? '') }}"></div>

    <div class="md:col-span-2 border-t border-slate-200 pt-4"><h3 class="font-semibold">Emergency contact</h3></div>
    <div><label class="text-sm font-semibold" for="emergency_contact_name">Contact name</label><input class="input mt-1" id="emergency_contact_name" name="emergency_contact_name" value="{{ old('emergency_contact_name', $faculty->emergency_contact_name ?? '') }}"></div>
    <div><label class="text-sm font-semibold" for="emergency_contact_phone">Contact phone</label><input class="input mt-1" id="emergency_contact_phone" name="emergency_contact_phone" value="{{ old('emergency_contact_phone', $faculty->emergency_contact_phone ?? '') }}"></div>
    <div class="md:col-span-2"><label class="text-sm font-semibold" for="notes">Notes (optional)</label><textarea class="input mt-1" id="notes" name="notes" rows="3" maxlength="5000">{{ old('notes', $faculty->notes ?? '') }}</textarea><p class="mt-1 text-xs text-rose-600">@error('notes'){{ $message }}@enderror</p></div>

    <div class="md:col-span-2 flex gap-2"><button class="button" type="submit">{{ $submitLabel }}</button><a class="button !bg-slate-200 !text-slate-700" href="{{ route($routePrefix.'.index') }}">Cancel</a></div>
</div>
