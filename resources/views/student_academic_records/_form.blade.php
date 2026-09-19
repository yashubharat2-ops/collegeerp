@csrf
<div class="mt-6 grid gap-5 md:grid-cols-2">
    @if(!isset($record))
        <div>
            <label class="label" for="student_id">Student *</label>
            <select class="input" id="student_id" name="student_id" required>
                <option value="">— Select student —</option>
                @foreach($students as $s)
                    <option value="{{ $s->id }}" @selected((int) old('student_id', $selectedStudentId ?? 0) === $s->id)>{{ $s->student_number }} — {{ $s->first_name }} {{ $s->last_name }}</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-rose-600">@error('student_id'){{ $message }}@enderror</p>
        </div>
    @else
        <div>
            <p class="text-sm"><span class="font-semibold">Student:</span> {{ $record->student?->fullName() }} ({{ $record->student?->student_number }})</p>
            <p class="mt-1 text-xs text-slate-500">The student an academic record belongs to cannot be changed.</p>
        </div>
    @endif

    <div>
        <label class="label" for="academic_year_id">Academic year *</label>
        <select class="input" id="academic_year_id" name="academic_year_id" required>
            <option value="">— Select academic year —</option>
            @foreach($academicYears as $ay)
                <option value="{{ $ay->id }}" @selected((int) old('academic_year_id', $record->academic_year_id ?? 0) === $ay->id)>{{ $ay->name }} ({{ $ay->code }})</option>
            @endforeach
        </select>
        <p class="mt-1 text-xs text-rose-600">@error('academic_year_id'){{ $message }}@enderror</p>
    </div>

    <div>
        <label class="label" for="academic_term_id">Academic term / semester</label>
        <select class="input" id="academic_term_id" name="academic_term_id">
            <option value="">— Whole year (no term) —</option>
            @foreach($academicTerms as $term)
                <option value="{{ $term->id }}"
                        data-academic-year-id="{{ $term->academic_year_id }}"
                        @selected((int) old('academic_term_id', $record->academic_term_id ?? 0) === $term->id)>
                    {{ $term->name }} ({{ $term->code }}) — {{ $term->academicYear?->name }}
                </option>
            @endforeach
        </select>
        <p class="mt-1 text-xs text-rose-600">@error('academic_term_id'){{ $message }}@enderror</p>
        <p class="mt-1 text-xs text-slate-500">A term is only accepted when it belongs to the selected academic year.</p>
    </div>

    <div>
        <label class="label" for="program_id">Program</label>
        <select class="input" id="program_id" name="program_id">
            <option value="">— No program —</option>
            @foreach($programs as $p)
                <option value="{{ $p->id }}" @selected((int) old('program_id', $record->program_id ?? 0) === $p->id)>{{ $p->name }} ({{ $p->code }})</option>
            @endforeach
        </select>
        <p class="mt-1 text-xs text-rose-600">@error('program_id'){{ $message }}@enderror</p>
    </div>

    <div>
        <label class="label" for="section_id">Section / batch</label>
        <select class="input" id="section_id" name="section_id">
            <option value="">— No section —</option>
            @foreach($sections as $section)
                <option value="{{ $section->id }}"
                        data-academic-year-id="{{ $section->academic_year_id }}"
                        data-program-id="{{ $section->program_id }}"
                        @selected((int) old('section_id', $record->section_id ?? 0) === $section->id)>
                    {{ $section->name }} ({{ $section->code }}) — {{ $section->academicYear?->name }} · {{ $section->program?->name }}
                </option>
            @endforeach
        </select>
        <p class="mt-1 text-xs text-rose-600">@error('section_id'){{ $message }}@enderror</p>
        <p class="mt-1 text-xs text-slate-500">A section is only accepted when it belongs to the selected academic year and program.</p>
    </div>

    <div>
        <label class="label" for="enrollment_id">Linked enrollment (optional)</label>
        <select class="input" id="enrollment_id" name="enrollment_id">
            <option value="">— Not linked —</option>
            @foreach($enrollments as $enrollment)
                <option value="{{ $enrollment->id }}"
                        data-student-id="{{ $enrollment->student_id }}"
                        @selected((int) old('enrollment_id', $record->enrollment_id ?? 0) === $enrollment->id)>
                    {{ $enrollment->enrollment_number }} — {{ $enrollment->academicYear?->name ?? '—' }}{{ $enrollment->program ? ' · '.$enrollment->program->name : '' }}
                </option>
            @endforeach
        </select>
        <p class="mt-1 text-xs text-rose-600">@error('enrollment_id'){{ $message }}@enderror</p>
        <p class="mt-1 text-xs text-slate-500">Only enrollments of the selected student are accepted.</p>
    </div>

    <div>
        <label class="label" for="academic_status">Academic status *</label>
        <select class="input" id="academic_status" name="academic_status" required>
            @foreach(App\Models\StudentAcademicRecord::ACADEMIC_STATUSES as $status)
                <option value="{{ $status }}" @selected(old('academic_status', $record->academic_status ?? 'enrolled') === $status)>{{ ucfirst(str_replace('_', ' ', $status)) }}</option>
            @endforeach
        </select>
        <p class="mt-1 text-xs text-rose-600">@error('academic_status'){{ $message }}@enderror</p>
    </div>

    <div>
        <label class="label" for="promotion_status">Promotion status *</label>
        <select class="input" id="promotion_status" name="promotion_status" required>
            @foreach(App\Models\StudentAcademicRecord::PROMOTION_STATUSES as $status)
                <option value="{{ $status }}" @selected(old('promotion_status', $record->promotion_status ?? 'not_applicable') === $status)>{{ ucfirst(str_replace('_', ' ', $status)) }}</option>
            @endforeach
        </select>
        <p class="mt-1 text-xs text-rose-600">@error('promotion_status'){{ $message }}@enderror</p>
    </div>

    <div>
        <label class="label" for="completion_status">Completion status *</label>
        <select class="input" id="completion_status" name="completion_status" required>
            @foreach(App\Models\StudentAcademicRecord::COMPLETION_STATUSES as $status)
                <option value="{{ $status }}" @selected(old('completion_status', $record->completion_status ?? 'pending') === $status)>{{ ucfirst(str_replace('_', ' ', $status)) }}</option>
            @endforeach
        </select>
        <p class="mt-1 text-xs text-rose-600">@error('completion_status'){{ $message }}@enderror</p>
    </div>

    <div class="md:col-span-2">
        <label class="label" for="remarks">Remarks</label>
        <textarea class="input" id="remarks" name="remarks" rows="2" maxlength="2000">{{ old('remarks', $record->remarks ?? '') }}</textarea>
        <p class="mt-1 text-xs text-rose-600">@error('remarks'){{ $message }}@enderror</p>
    </div>

    <div class="md:col-span-2 flex gap-2">
        <button class="button" type="submit">{{ $submitLabel }}</button>
        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('student-academic-records.index') }}">Cancel</a>
    </div>
</div>

@push('scripts')
<script>
    // Convenience only: narrows the term/section options to the chosen year and
    // program. The server re-validates every combination, so bypassing this
    // script cannot create an inconsistent record.
    (function () {
        const student = document.getElementById('student_id');
        const year = document.getElementById('academic_year_id');
        const program = document.getElementById('program_id');
        const term = document.getElementById('academic_term_id');
        const section = document.getElementById('section_id');
        const enrollment = document.getElementById('enrollment_id');

        function filter(select, predicate) {
            if (!select) return;
            const current = select.value;
            Array.from(select.options).forEach(function (option, index) {
                if (index === 0) return;
                const visible = predicate(option);
                option.hidden = !visible;
                option.disabled = !visible;
            });
            if (current && select.selectedOptions[0] && select.selectedOptions[0].hidden) {
                select.value = '';
            }
        }

        function apply() {
            const yearId = year ? year.value : '';
            const programId = program ? program.value : '';
            const studentId = student ? student.value : '';

            filter(term, function (option) {
                return !yearId || option.dataset.academicYearId === yearId;
            });

            filter(section, function (option) {
                const yearOk = !yearId || option.dataset.academicYearId === yearId;
                const programOk = !programId || !option.dataset.programId || option.dataset.programId === programId;
                return yearOk && programOk;
            });

            filter(enrollment, function (option) {
                return !studentId || option.dataset.studentId === studentId;
            });
        }

        if (student) student.addEventListener('change', apply);
        if (year) year.addEventListener('change', apply);
        if (program) program.addEventListener('change', apply);
        apply();
    })();
</script>
@endpush
