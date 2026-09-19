@csrf
<div class="mt-6 grid gap-5 md:grid-cols-2">
    <div>
        <label class="text-sm font-semibold" for="name">Name</label>
        <input class="input mt-1" id="name" name="name" value="{{ old('name', $subject->name ?? '') }}" placeholder="e.g. Data Structures, General Psychology" required>
        <p class="mt-1 text-xs text-rose-600">@error('name'){{ $message }}@enderror</p>
    </div>

    <div>
        <label class="text-sm font-semibold" for="code">Code</label>
        <input class="input mt-1" id="code" name="code" value="{{ old('code', $subject->code ?? '') }}" maxlength="50" placeholder="e.g. CS101, PSY200" required>
        <p class="mt-1 text-xs text-rose-600">@error('code'){{ $message }}@enderror</p>
    </div>

    <div>
        <label class="text-sm font-semibold" for="short_name">Short Name (optional)</label>
        <input class="input mt-1" id="short_name" name="short_name" value="{{ old('short_name', $subject->short_name ?? '') }}" maxlength="50" placeholder="e.g. DS, Psych">
        <p class="mt-1 text-xs text-rose-600">@error('short_name'){{ $message }}@enderror</p>
    </div>

    <div>
        <label class="text-sm font-semibold" for="department_id">Department (optional)</label>
        <select class="input mt-1" id="department_id" name="department_id">
            <option value="">College level (no department)</option>
            @foreach($departments as $dept)
                <option value="{{ $dept->id }}" @selected((int) old('department_id', $subject->department_id ?? 0) === $dept->id)>
                    {{ $dept->name }} ({{ $dept->code }})
                </option>
            @endforeach
        </select>
        <p class="mt-1 text-xs text-rose-600">@error('department_id'){{ $message }}@enderror</p>
    </div>

    <div>
        <label class="text-sm font-semibold" for="subject_type">Type (optional)</label>
        <select class="input mt-1" id="subject_type" name="subject_type">
            <option value="">Select type</option>
            @foreach($types as $t)
                <option value="{{ $t }}" @selected(old('subject_type', $subject->subject_type ?? '') === $t)>
                    {{ ucfirst($t) }}
                </option>
            @endforeach
        </select>
        <p class="mt-1 text-xs text-rose-600">@error('subject_type'){{ $message }}@enderror</p>
    </div>

    <div>
        <label class="text-sm font-semibold" for="credits">Credits (optional)</label>
        <input class="input mt-1" id="credits" name="credits" type="number" step="0.5" min="0" max="100" value="{{ old('credits', $subject->credits ?? '') }}" placeholder="e.g. 4.0">
        <p class="mt-1 text-xs text-rose-600">@error('credits'){{ $message }}@enderror</p>
    </div>

    <div>
        <label class="text-sm font-semibold" for="max_marks">Max Marks (optional)</label>
        <input class="input mt-1" id="max_marks" name="max_marks" type="number" step="0.5" min="0" max="10000" value="{{ old('max_marks', $subject->max_marks ?? '') }}" placeholder="e.g. 100">
        <p class="mt-1 text-xs text-rose-600">@error('max_marks'){{ $message }}@enderror</p>
    </div>

    <div>
        <label class="text-sm font-semibold" for="passing_marks">Passing Marks (optional)</label>
        <input class="input mt-1" id="passing_marks" name="passing_marks" type="number" step="0.5" min="0" max="10000" value="{{ old('passing_marks', $subject->passing_marks ?? '') }}" placeholder="e.g. 40">
        <p class="mt-1 text-xs text-rose-600">@error('passing_marks'){{ $message }}@enderror</p>
    </div>

    <div>
        <label class="text-sm font-semibold" for="status">Status</label>
        <select class="input mt-1" id="status" name="status" required>
            <option value="active" @selected(old('status', $subject->status ?? 'active') === 'active')>Active</option>
            <option value="inactive" @selected(old('status', $subject->status ?? 'active') === 'inactive')>Inactive</option>
        </select>
        <p class="mt-1 text-xs text-rose-600">@error('status'){{ $message }}@enderror</p>
    </div>

    <div class="md:col-span-2">
        <label class="text-sm font-semibold" for="description">Description (optional)</label>
        <textarea class="input mt-1" id="description" name="description" rows="3" maxlength="2000">{{ old('description', $subject->description ?? '') }}</textarea>
        <p class="mt-1 text-xs text-rose-600">@error('description'){{ $message }}@enderror</p>
    </div>

    <div class="md:col-span-2 flex gap-2">
        <button class="button" type="submit">{{ $submitLabel }}</button>
        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('subjects.index') }}">Cancel</a>
    </div>
</div>
