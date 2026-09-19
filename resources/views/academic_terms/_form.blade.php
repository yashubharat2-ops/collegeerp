@csrf
<div class="mt-6 grid gap-5 md:grid-cols-2">
    <div>
        <label class="text-sm font-semibold" for="academic_year_id">Academic Year</label>
        <select class="input mt-1" id="academic_year_id" name="academic_year_id" required>
            <option value="">Select academic year</option>
            @foreach($academicYears as $year)
                <option value="{{ $year->id }}" @selected((int) old('academic_year_id', $academicTerm->academic_year_id ?? 0) === $year->id)>
                    {{ $year->name }} ({{ $year->code }})
                </option>
            @endforeach
        </select>
        <p class="mt-1 text-xs text-rose-600">@error('academic_year_id'){{ $message }}@enderror</p>
    </div>

    <div>
        <label class="text-sm font-semibold" for="name">Name</label>
        <input class="input mt-1" id="name" name="name" value="{{ old('name', $academicTerm->name ?? '') }}" placeholder="e.g. Semester 1, Term 1" required>
        <p class="mt-1 text-xs text-rose-600">@error('name'){{ $message }}@enderror</p>
    </div>

    <div>
        <label class="text-sm font-semibold" for="code">Code</label>
        <input class="input mt-1" id="code" name="code" value="{{ old('code', $academicTerm->code ?? '') }}" maxlength="50" placeholder="e.g. SEM1, T1" required>
        <p class="mt-1 text-xs text-rose-600">@error('code'){{ $message }}@enderror</p>
    </div>

    <div>
        <label class="text-sm font-semibold" for="type">Type</label>
        <select class="input mt-1" id="type" name="type" required>
            @foreach($types as $t)
                <option value="{{ $t }}" @selected(old('type', $academicTerm->type ?? 'semester') === $t)>
                    {{ ucfirst($t) }}
                </option>
            @endforeach
        </select>
        <p class="mt-1 text-xs text-rose-600">@error('type'){{ $message }}@enderror</p>
    </div>

    <div>
        <label class="text-sm font-semibold" for="sequence">Sequence / Order</label>
        <input class="input mt-1" id="sequence" name="sequence" type="number" min="1" max="1000" value="{{ old('sequence', $academicTerm->sequence ?? 1) }}" required>
        <p class="mt-1 text-xs text-rose-600">@error('sequence'){{ $message }}@enderror</p>
    </div>

    <div>
        <label class="text-sm font-semibold" for="status">Status</label>
        <select class="input mt-1" id="status" name="status" required>
            <option value="active" @selected(old('status', $academicTerm->status ?? 'active') === 'active')>Active</option>
            <option value="inactive" @selected(old('status', $academicTerm->status ?? 'active') === 'inactive')>Inactive</option>
        </select>
        <p class="mt-1 text-xs text-rose-600">@error('status'){{ $message }}@enderror</p>
    </div>

    <div class="md:col-span-2">
        <label class="text-sm font-semibold" for="description">Description (optional)</label>
        <textarea class="input mt-1" id="description" name="description" rows="3" maxlength="2000">{{ old('description', $academicTerm->description ?? '') }}</textarea>
        <p class="mt-1 text-xs text-rose-600">@error('description'){{ $message }}@enderror</p>
    </div>

    <div class="md:col-span-2 flex gap-2">
        <button class="button" type="submit">{{ $submitLabel }}</button>
        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('academic-terms.index') }}">Cancel</a>
    </div>
</div>
