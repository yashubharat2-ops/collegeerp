@csrf
<div class="mt-6 grid gap-5 md:grid-cols-2">
    <div>
        <label class="text-sm font-semibold" for="employee_code">Employee Code</label>
        <input class="input mt-1" id="employee_code" name="employee_code" value="{{ old('employee_code', $faculty->employee_code ?? '') }}" maxlength="50" placeholder="e.g. EMP-101, FAC001" required>
        <p class="mt-1 text-xs text-rose-600">@error('employee_code'){{ $message }}@enderror</p>
    </div>

    <div>
        <label class="text-sm font-semibold" for="first_name">First Name</label>
        <input class="input mt-1" id="first_name" name="first_name" value="{{ old('first_name', $faculty->first_name ?? '') }}" placeholder="e.g. Jane" required>
        <p class="mt-1 text-xs text-rose-600">@error('first_name'){{ $message }}@enderror</p>
    </div>

    <div>
        <label class="text-sm font-semibold" for="middle_name">Middle Name (optional)</label>
        <input class="input mt-1" id="middle_name" name="middle_name" value="{{ old('middle_name', $faculty->middle_name ?? '') }}" placeholder="e.g. M.">
        <p class="mt-1 text-xs text-rose-600">@error('middle_name'){{ $message }}@enderror</p>
    </div>

    <div>
        <label class="text-sm font-semibold" for="last_name">Last Name</label>
        <input class="input mt-1" id="last_name" name="last_name" value="{{ old('last_name', $faculty->last_name ?? '') }}" placeholder="e.g. Doe" required>
        <p class="mt-1 text-xs text-rose-600">@error('last_name'){{ $message }}@enderror</p>
    </div>

    <div>
        <label class="text-sm font-semibold" for="email">Email (optional)</label>
        <input class="input mt-1" id="email" name="email" type="email" value="{{ old('email', $faculty->email ?? '') }}" placeholder="e.g. jane.doe@example.test">
        <p class="mt-1 text-xs text-rose-600">@error('email'){{ $message }}@enderror</p>
    </div>

    <div>
        <label class="text-sm font-semibold" for="phone">Phone (optional)</label>
        <input class="input mt-1" id="phone" name="phone" value="{{ old('phone', $faculty->phone ?? '') }}" maxlength="50" placeholder="e.g. +1 555-0199">
        <p class="mt-1 text-xs text-rose-600">@error('phone'){{ $message }}@enderror</p>
    </div>

    <div>
        <label class="text-sm font-semibold" for="designation">Designation (optional)</label>
        <input class="input mt-1" id="designation" name="designation" value="{{ old('designation', $faculty->designation ?? '') }}" maxlength="100" placeholder="e.g. Professor, Assistant Professor, Lecturer">
        <p class="mt-1 text-xs text-rose-600">@error('designation'){{ $message }}@enderror</p>
    </div>

    <div>
        <label class="text-sm font-semibold" for="department_id">Department (optional)</label>
        <select class="input mt-1" id="department_id" name="department_id">
            <option value="">College level (no department)</option>
            @foreach($departments as $dept)
                <option value="{{ $dept->id }}" @selected((int) old('department_id', $faculty->department_id ?? 0) === $dept->id)>
                    {{ $dept->name }} ({{ $dept->code }})
                </option>
            @endforeach
        </select>
        <p class="mt-1 text-xs text-rose-600">@error('department_id'){{ $message }}@enderror</p>
    </div>

    <div>
        <label class="text-sm font-semibold" for="employment_type">Employment Type (optional)</label>
        <select class="input mt-1" id="employment_type" name="employment_type">
            <option value="">Select employment type</option>
            @foreach($employmentTypes as $type)
                <option value="{{ $type }}" @selected(old('employment_type', $faculty->employment_type ?? '') === $type)>
                    {{ ucwords(str_replace('_', ' ', $type)) }}
                </option>
            @endforeach
        </select>
        <p class="mt-1 text-xs text-rose-600">@error('employment_type'){{ $message }}@enderror</p>
    </div>

    <div>
        <label class="text-sm font-semibold" for="joining_date">Joining Date (optional)</label>
        <input class="input mt-1" id="joining_date" name="joining_date" type="date" value="{{ old('joining_date', isset($faculty->joining_date) ? $faculty->joining_date?->format('Y-m-d') : '') }}">
        <p class="mt-1 text-xs text-rose-600">@error('joining_date'){{ $message }}@enderror</p>
    </div>

    <div>
        <label class="text-sm font-semibold" for="status">Status</label>
        <select class="input mt-1" id="status" name="status" required>
            <option value="active" @selected(old('status', $faculty->status ?? 'active') === 'active')>Active</option>
            <option value="inactive" @selected(old('status', $faculty->status ?? 'active') === 'inactive')>Inactive</option>
        </select>
        <p class="mt-1 text-xs text-rose-600">@error('status'){{ $message }}@enderror</p>
    </div>

    <div class="md:col-span-2 flex gap-2">
        <button class="button" type="submit">{{ $submitLabel }}</button>
        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('faculties.index') }}">Cancel</a>
    </div>
</div>
