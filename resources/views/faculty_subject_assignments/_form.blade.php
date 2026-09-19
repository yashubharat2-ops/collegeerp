@csrf
<div class="mt-6 grid gap-5 md:grid-cols-2">
    <div>
        <label class="text-sm font-semibold" for="academic_year_id">Academic Year</label>
        <select class="input mt-1" id="academic_year_id" name="academic_year_id" required>
            <option value="">Select academic year</option>
            @foreach($academicYears as $year)
                <option value="{{ $year->id }}" @selected((int) old('academic_year_id', $assignment->academic_year_id ?? 0) === $year->id)>
                    {{ $year->name }} ({{ $year->code }})
                </option>
            @endforeach
        </select>
        <p class="mt-1 text-xs text-rose-600">@error('academic_year_id'){{ $message }}@enderror</p>
    </div>

    <div>
        <label class="text-sm font-semibold" for="academic_term_id">Academic Term / Semester (optional)</label>
        <select class="input mt-1" id="academic_term_id" name="academic_term_id">
            <option value="">No specific term (Full year)</option>
            @foreach($academicTerms as $term)
                <option value="{{ $term->id }}" data-year-id="{{ $term->academic_year_id }}" @selected((int) old('academic_term_id', $assignment->academic_term_id ?? 0) === $term->id)>
                    {{ $term->name }} ({{ $term->code }})
                </option>
            @endforeach
        </select>
        <p class="mt-1 text-xs text-rose-600">@error('academic_term_id'){{ $message }}@enderror</p>
    </div>

    <div>
        <label class="text-sm font-semibold" for="program_id">Program (optional)</label>
        <select class="input mt-1" id="program_id" name="program_id">
            <option value="">No specific program</option>
            @foreach($programs as $prog)
                <option value="{{ $prog->id }}" @selected((int) old('program_id', $assignment->program_id ?? 0) === $prog->id)>
                    {{ $prog->name }} ({{ $prog->code }})
                </option>
            @endforeach
        </select>
        <p class="mt-1 text-xs text-rose-600">@error('program_id'){{ $message }}@enderror</p>
    </div>

    <div>
        <label class="text-sm font-semibold" for="section_id">Section / Batch (optional)</label>
        <select class="input mt-1" id="section_id" name="section_id">
            <option value="">All sections / No specific section</option>
            @foreach($sections as $sec)
                <option value="{{ $sec->id }}" data-year-id="{{ $sec->academic_year_id }}" data-program-id="{{ $sec->program_id }}" @selected((int) old('section_id', $assignment->section_id ?? 0) === $sec->id)>
                    {{ $sec->name }} ({{ $sec->code }})
                </option>
            @endforeach
        </select>
        <p class="mt-1 text-xs text-rose-600">@error('section_id'){{ $message }}@enderror</p>
    </div>

    <div>
        <label class="text-sm font-semibold" for="subject_id">Subject</label>
        <select class="input mt-1" id="subject_id" name="subject_id" required>
            <option value="">Select subject</option>
            @foreach($subjects as $sub)
                <option value="{{ $sub->id }}" @selected((int) old('subject_id', $assignment->subject_id ?? 0) === $sub->id)>
                    {{ $sub->name }} ({{ $sub->code }})
                </option>
            @endforeach
        </select>
        <p class="mt-1 text-xs text-rose-600">@error('subject_id'){{ $message }}@enderror</p>
    </div>

    <div>
        <label class="text-sm font-semibold" for="faculty_id">Faculty / Staff Member</label>
        <select class="input mt-1" id="faculty_id" name="faculty_id" required>
            <option value="">Select faculty member</option>
            @foreach($faculties as $fac)
                <option value="{{ $fac->id }}" @selected((int) old('faculty_id', $assignment->faculty_id ?? 0) === $fac->id)>
                    {{ $fac->full_name }} ({{ $fac->employee_code }})
                </option>
            @endforeach
        </select>
        <p class="mt-1 text-xs text-rose-600">@error('faculty_id'){{ $message }}@enderror</p>
    </div>

    <div>
        <label class="text-sm font-semibold" for="status">Status</label>
        <select class="input mt-1" id="status" name="status" required>
            <option value="active" @selected(old('status', $assignment->status ?? 'active') === 'active')>Active</option>
            <option value="inactive" @selected(old('status', $assignment->status ?? 'active') === 'inactive')>Inactive</option>
        </select>
        <p class="mt-1 text-xs text-rose-600">@error('status'){{ $message }}@enderror</p>
    </div>

    <div class="md:col-span-2">
        <label class="text-sm font-semibold" for="remarks">Remarks (optional)</label>
        <textarea class="input mt-1" id="remarks" name="remarks" rows="3" maxlength="2000">{{ old('remarks', $assignment->remarks ?? '') }}</textarea>
        <p class="mt-1 text-xs text-rose-600">@error('remarks'){{ $message }}@enderror</p>
    </div>

    <div class="md:col-span-2 flex gap-2">
        <button class="button" type="submit">{{ $submitLabel }}</button>
        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('faculty-subject-assignments.index') }}">Cancel</a>
    </div>
</div>

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const yearSelect = document.getElementById('academic_year_id');
        const termSelect = document.getElementById('academic_term_id');
        const progSelect = document.getElementById('program_id');
        const sectionSelect = document.getElementById('section_id');

        function filterOptions() {
            const selectedYear = yearSelect ? yearSelect.value : '';
            const selectedProg = progSelect ? progSelect.value : '';

            if (termSelect) {
                Array.from(termSelect.options).forEach(opt => {
                    if (!opt.value) return;
                    const yearId = opt.getAttribute('data-year-id');
                    opt.hidden = (selectedYear && yearId && yearId !== selectedYear);
                });
            }

            if (sectionSelect) {
                Array.from(sectionSelect.options).forEach(opt => {
                    if (!opt.value) return;
                    const yearId = opt.getAttribute('data-year-id');
                    const progId = opt.getAttribute('data-program-id');
                    const matchYear = !selectedYear || !yearId || yearId === selectedYear;
                    const matchProg = !selectedProg || !progId || progId === selectedProg;
                    opt.hidden = !(matchYear && matchProg);
                });
            }
        }

        if (yearSelect) yearSelect.addEventListener('change', filterOptions);
        if (progSelect) progSelect.addEventListener('change', filterOptions);
        filterOptions();
    });
</script>
@endpush
