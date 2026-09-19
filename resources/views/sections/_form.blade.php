@csrf
<div class="mt-6 grid gap-5 md:grid-cols-2">
    <div>
        <label class="text-sm font-semibold" for="academic_year_id">Academic Year</label>
        <select class="input mt-1" id="academic_year_id" name="academic_year_id" required>
            <option value="">Select academic year</option>
            @foreach($academicYears as $year)
                <option value="{{ $year->id }}" @selected((int) old('academic_year_id', $section->academic_year_id ?? 0) === $year->id)>
                    {{ $year->name }} ({{ $year->code }})
                </option>
            @endforeach
        </select>
        <p class="mt-1 text-xs text-rose-600">@error('academic_year_id'){{ $message }}@enderror</p>
    </div>

    <div>
        <label class="text-sm font-semibold" for="program_id">Program</label>
        <select class="input mt-1" id="program_id" name="program_id" required>
            <option value="">Select program</option>
            @foreach($programs as $program)
                <option value="{{ $program->id }}" @selected((int) old('program_id', $section->program_id ?? 0) === $program->id)>
                    {{ $program->name }} ({{ $program->code }})
                </option>
            @endforeach
        </select>
        <p class="mt-1 text-xs text-rose-600">@error('program_id'){{ $message }}@enderror</p>
    </div>

    <div>
        <label class="text-sm font-semibold" for="name">Name</label>
        <input class="input mt-1" id="name" name="name" value="{{ old('name', $section->name ?? '') }}" placeholder="e.g. Section A, Morning Batch" required>
        <p class="mt-1 text-xs text-rose-600">@error('name'){{ $message }}@enderror</p>
    </div>

    <div>
        <label class="text-sm font-semibold" for="code">Code</label>
        <input class="input mt-1" id="code" name="code" value="{{ old('code', $section->code ?? '') }}" maxlength="50" placeholder="e.g. A, MORNING, SEC-1" required>
        <p class="mt-1 text-xs text-rose-600">@error('code'){{ $message }}@enderror</p>
    </div>

    <div>
        <label class="text-sm font-semibold" for="campus_id">Campus (optional)</label>
        <select class="input mt-1" id="campus_id" name="campus_id">
            <option value="">College level (no campus)</option>
            @foreach($campuses as $campus)
                <option value="{{ $campus->id }}" @selected((int) old('campus_id', $section->campus_id ?? 0) === $campus->id)>
                    {{ $campus->name }} ({{ $campus->code }})
                </option>
            @endforeach
        </select>
        <p class="mt-1 text-xs text-rose-600">@error('campus_id'){{ $message }}@enderror</p>
    </div>

    <div>
        <label class="text-sm font-semibold" for="capacity">Capacity (optional)</label>
        <input class="input mt-1" id="capacity" name="capacity" type="number" min="1" max="100000" value="{{ old('capacity', $section->capacity ?? '') }}" placeholder="e.g. 60">
        <p class="mt-1 text-xs text-rose-600">@error('capacity'){{ $message }}@enderror</p>
    </div>

    <div>
        <label class="text-sm font-semibold" for="status">Status</label>
        <select class="input mt-1" id="status" name="status" required>
            <option value="active" @selected(old('status', $section->status ?? 'active') === 'active')>Active</option>
            <option value="inactive" @selected(old('status', $section->status ?? 'active') === 'inactive')>Inactive</option>
        </select>
        <p class="mt-1 text-xs text-rose-600">@error('status'){{ $message }}@enderror</p>
    </div>

    <div class="md:col-span-2">
        <label class="text-sm font-semibold" for="description">Description (optional)</label>
        <textarea class="input mt-1" id="description" name="description" rows="3" maxlength="2000">{{ old('description', $section->description ?? '') }}</textarea>
        <p class="mt-1 text-xs text-rose-600">@error('description'){{ $message }}@enderror</p>
    </div>

    <div class="md:col-span-2 flex gap-2">
        <button class="button" type="submit">{{ $submitLabel }}</button>
        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('sections.index') }}">Cancel</a>
    </div>
</div>
