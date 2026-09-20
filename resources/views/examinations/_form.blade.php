@csrf
<div class="mt-6 grid gap-5 md:grid-cols-2">
    <div>
        <label class="text-sm font-semibold" for="name">Examination Name</label>
        <input class="input mt-1" id="name" name="name" value="{{ old('name', $examination->name ?? '') }}" placeholder="e.g. Mid Term Examinations 2026" required>
        <p class="mt-1 text-xs text-rose-600">@error('name'){{ $message }}@enderror</p>
    </div>

    <div>
        <label class="text-sm font-semibold" for="code">Examination Code</label>
        <input class="input mt-1" id="code" name="code" value="{{ old('code', $examination->code ?? '') }}" maxlength="50" placeholder="e.g. EXAM-2026-MID" required>
        <p class="mt-1 text-xs text-rose-600">@error('code'){{ $message }}@enderror</p>
    </div>

    <div>
        <label class="text-sm font-semibold" for="academic_year_id">Academic Year</label>
        <select class="input mt-1" id="academic_year_id" name="academic_year_id" required>
            <option value="">Select academic year</option>
            @foreach($academicYears as $year)
                <option value="{{ $year->id }}" @selected((int) old('academic_year_id', $examination->academic_year_id ?? 0) === $year->id)>
                    {{ $year->name }} ({{ $year->code }})
                </option>
            @endforeach
        </select>
        <p class="mt-1 text-xs text-rose-600">@error('academic_year_id'){{ $message }}@enderror</p>
    </div>

    <div>
        <label class="text-sm font-semibold" for="academic_term_id">Academic Term / Semester</label>
        <select class="input mt-1" id="academic_term_id" name="academic_term_id" required>
            <option value="">Select academic term</option>
            @foreach($academicTerms as $term)
                <option value="{{ $term->id }}" data-year="{{ $term->academic_year_id }}" @selected((int) old('academic_term_id', $examination->academic_term_id ?? 0) === $term->id)>
                    {{ $term->name }} ({{ $term->code }})
                </option>
            @endforeach
        </select>
        <p class="mt-1 text-xs text-rose-600">@error('academic_term_id'){{ $message }}@enderror</p>
    </div>

    <div>
        <label class="text-sm font-semibold" for="exam_type">Examination Type</label>
        <input class="input mt-1" id="exam_type" name="exam_type" list="exam_type_list" value="{{ old('exam_type', $examination->exam_type ?? '') }}" placeholder="e.g. Mid Term, Final, Practical" required>
        <datalist id="exam_type_list">
            @foreach($defaultTypes as $type)
                <option value="{{ $type }}">
            @endforeach
        </datalist>
        <p class="mt-1 text-xs text-rose-600">@error('exam_type'){{ $message }}@enderror</p>
    </div>

    <div>
        <label class="text-sm font-semibold" for="status">Status</label>
        <select class="input mt-1" id="status" name="status" required>
            @foreach($statuses as $st)
                <option value="{{ $st }}" @selected(old('status', $examination->status ?? 'draft') === $st)>{{ ucfirst($st) }}</option>
            @endforeach
        </select>
        <p class="mt-1 text-xs text-rose-600">@error('status'){{ $message }}@enderror</p>
    </div>

    <div>
        <label class="text-sm font-semibold" for="start_date">Start Date</label>
        <input class="input mt-1" id="start_date" name="start_date" type="date" value="{{ old('start_date', isset($examination) && $examination->start_date ? $examination->start_date->format('Y-m-d') : '') }}" required>
        <p class="mt-1 text-xs text-rose-600">@error('start_date'){{ $message }}@enderror</p>
    </div>

    <div>
        <label class="text-sm font-semibold" for="end_date">End Date</label>
        <input class="input mt-1" id="end_date" name="end_date" type="date" value="{{ old('end_date', isset($examination) && $examination->end_date ? $examination->end_date->format('Y-m-d') : '') }}" required>
        <p class="mt-1 text-xs text-rose-600">@error('end_date'){{ $message }}@enderror</p>
    </div>

    <div class="md:col-span-2">
        <label class="text-sm font-semibold" for="description">Description (optional)</label>
        <textarea class="input mt-1" id="description" name="description" rows="3" maxlength="2000" placeholder="Instructions, notes, or examination details">{{ old('description', $examination->description ?? '') }}</textarea>
        <p class="mt-1 text-xs text-rose-600">@error('description'){{ $message }}@enderror</p>
    </div>

    <div class="md:col-span-2 flex gap-2">
        <button class="button" type="submit">{{ $submitLabel }}</button>
        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('examinations.index') }}">Cancel</a>
    </div>
</div>
