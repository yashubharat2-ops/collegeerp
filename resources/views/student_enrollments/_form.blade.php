@csrf
<div class="mt-6 grid gap-5 md:grid-cols-2">
    @if(!isset($enrollment))
        <div>
            <label class="text-sm font-semibold" for="student_id">Student *</label>
            <select class="input mt-1" id="student_id" name="student_id" required>
                <option value="">— Select student —</option>
                @foreach($students as $s)
                    {{-- Student ID + name + Admission Number: enough to identify the
                         person without re-entering the registration form. A student
                         with no admission says so instead of showing a placeholder
                         that looks like a number. --}}
                    <option value="{{ $s->id }}" @selected((int) old('student_id', $selectedStudentId ?? 0) === $s->id)>{{ $s->student_number }} — {{ trim($s->first_name.' '.($s->middle_name ? $s->middle_name.' ' : '').$s->last_name) }} — {{ isset($admissionNumbers[$s->id]) ? 'Adm. '.$admissionNumbers[$s->id] : 'No admission number' }}</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-slate-500">
                Only students with an approved registration are listed. A pending or rejected registration must be
                decided in Approval Management first.
            </p>
            <p class="mt-1 text-xs text-rose-600">@error('student_id'){{ $message }}@enderror</p>
        </div>
        <div>
            <label class="text-sm font-semibold" for="academic_year_id">Academic Year *</label>
            <select class="input mt-1" id="academic_year_id" name="academic_year_id" required>
                <option value="">— Select year —</option>
                @foreach($academicYears as $ay)
                    <option value="{{ $ay->id }}" @selected((int) old('academic_year_id') === $ay->id)>{{ $ay->name }} ({{ $ay->code }})</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-rose-600">@error('academic_year_id'){{ $message }}@enderror</p>
        </div>
        <div>
            <label class="text-sm font-semibold" for="program_id">Program</label>
            <select class="input mt-1" id="program_id" name="program_id">
                <option value="">— Select program —</option>
                @foreach($programs as $prog)
                    <option value="{{ $prog->id }}" @selected((int) old('program_id') === $prog->id)>{{ $prog->name }} ({{ $prog->code }})</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-rose-600">@error('program_id'){{ $message }}@enderror</p>
        </div>
    @else
        <div class="md:col-span-2 grid gap-1">
            <p class="text-sm"><span class="font-semibold">Enrollment Number:</span> {{ $enrollment->enrollment_number }}</p>
            <p class="text-sm"><span class="font-semibold">Student ID:</span> {{ $enrollment->student->student_number }}</p>
            <p class="text-sm"><span class="font-semibold">Student:</span> {{ trim($enrollment->student->first_name.' '.($enrollment->student->middle_name ? $enrollment->student->middle_name.' ' : '').$enrollment->student->last_name) }}</p>
            <p class="text-sm">
                <span class="font-semibold">Admission Number:</span>
                {{ $admissionNumbers[$enrollment->student_id] ?? 'Not applicable — no admission record' }}
            </p>
            <p class="text-sm"><span class="font-semibold">Roll Number:</span> {{ $enrollment->roll_number ?? 'Pending — assigned with a section' }}</p>
            <p class="text-sm"><span class="font-semibold">Academic Year:</span> {{ $enrollment->academicYear->name }} ({{ $enrollment->academicYear->code }})</p>
            <p class="text-sm"><span class="font-semibold">Program:</span> {{ $enrollment->program?->name ?? '—' }}</p>
            <p class="text-sm"><span class="font-semibold">Semester / year:</span> {{ $enrollment->semesterLabel() ?? 'Not set' }}</p>
            <p class="text-sm"><span class="font-semibold">Status:</span> {{ $enrollment->statusLabel() }}</p>
            @if($enrollment->isPendingApproval())
                <p class="text-xs text-amber-700">
                    This enrollment is awaiting approval. It becomes active when an approver decides it in Approval Management;
                    it cannot be set to Active from this form.
                </p>
            @endif
        </div>
    @endif
    {{-- Academic order: Year → Programme → Semester/Year of study → Section.
         The list is the college's configured levels for the chosen academic
         year; the script below re-filters it when the year changes and clears a
         stale choice. The server validates the combination again, so this
         narrowing is convenience, not authority. --}}
    <div>
        <label class="text-sm font-semibold" for="academic_term_id">Semester / Year of study</label>
        <select class="input mt-1" id="academic_term_id" name="academic_term_id">
            <option value="">— Not set —</option>
            @foreach(($terms ?? collect()) as $term)
                <option value="{{ $term->id }}"
                        data-academic-year-id="{{ $term->academic_year_id }}"
                        @selected((int) old('academic_term_id', $enrollment->academic_term_id ?? 0) === (int) $term->id)>
                    {{ $term->name }} ({{ $term->code }})
                </option>
            @endforeach
        </select>
        <p class="mt-1 text-xs text-rose-600">@error('academic_term_id'){{ $message }}@enderror</p>
        <p class="mt-1 text-xs text-slate-500">
            The configured levels of this enrollment's academic year. A year with no configured levels keeps the
            free-text label an older record carries.
        </p>
    </div>
    <div>
        <label class="text-sm font-semibold" for="section_id">Section</label>
        <select class="input mt-1" id="section_id" name="section_id">
            <option value="">— No section —</option>
            @foreach($sections ?? [] as $section)
                <option value="{{ $section->id }}"
                        data-academic-year-id="{{ $section->academic_year_id }}"
                        data-program-id="{{ $section->program_id }}"
                        @selected((int) old('section_id', $enrollment->section_id ?? 0) === $section->id)>
                    {{ $section->name }} ({{ $section->code }}) — {{ $section->academicYear?->name }} · {{ $section->program?->name }}
                </option>
            @endforeach
        </select>
        <p class="mt-1 text-xs text-rose-600">@error('section_id'){{ $message }}@enderror</p>
        <p class="mt-1 text-xs text-slate-500">A section is only accepted when it belongs to the selected academic year and program.</p>
    </div>
    <div>
        <label class="text-sm font-semibold" for="roll_number">Roll Number</label>
        <input class="input mt-1" id="roll_number" name="roll_number" maxlength="30"
               value="{{ old('roll_number', $enrollment->roll_number ?? '') }}">
        <p class="mt-1 text-xs text-rose-600">@error('roll_number'){{ $message }}@enderror</p>
        <p class="mt-1 text-xs text-slate-500">
            Leave blank to allocate the next free number in this academic year, program and section.
            A roll number is per class/section; the Student ID never changes.
        </p>
    </div>
    <div>
        <label class="text-sm font-semibold" for="enrollment_date">Enrollment Date</label>
        <input class="input mt-1" type="date" id="enrollment_date" name="enrollment_date" value="{{ old('enrollment_date', isset($enrollment) ? $enrollment->enrollment_date?->format('Y-m-d') : now()->format('Y-m-d')) }}">
        <p class="mt-1 text-xs text-rose-600">@error('enrollment_date'){{ $message }}@enderror</p>
    </div>
    <div>
        <label class="text-sm font-semibold" for="status">Status *</label>
        <select class="input mt-1" id="status" name="status" required>
            @foreach(\App\Models\StudentEnrollment::formStatuses($enrollment->status ?? null) as $s)
                <option value="{{ $s }}" @selected(old('status', $enrollment->status ?? 'active') === $s)>{{ \App\Models\StudentEnrollment::statusLabelFor($s) }}</option>
            @endforeach
        </select>
        <p class="mt-1 text-xs text-rose-600">@error('status'){{ $message }}@enderror</p>
    </div>
    <div class="md:col-span-2">
        <label class="text-sm font-semibold" for="remarks">Remarks</label>
        <textarea class="input mt-1" id="remarks" name="remarks" rows="2" maxlength="2000">{{ old('remarks', $enrollment->remarks ?? '') }}</textarea>
        <p class="mt-1 text-xs text-rose-600">@error('remarks'){{ $message }}@enderror</p>
    </div>
    <div class="md:col-span-2 flex gap-2">
        <button class="button" type="submit">{{ $submitLabel }}</button>
        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('student-enrollments.index') }}">Cancel</a>
    </div>
</div>

@push('scripts')
<script>
    // Convenience only: narrows the section list to the chosen academic year and
    // program. The server re-validates the combination, so this script can never
    // be relied on for correctness.
    (function () {
        const year = document.getElementById('academic_year_id');
        const program = document.getElementById('program_id');
        const section = document.getElementById('section_id');
        const term = document.getElementById('academic_term_id');
        if (!section && !term) return;

        function narrow(select, yearId, programId) {
            if (!select) return;

            Array.from(select.options).forEach(function (option, index) {
                if (index === 0) return;
                const yearOk = !yearId || option.dataset.academicYearId === yearId;
                const programOk = !programId || !option.dataset.programId || option.dataset.programId === programId;
                const visible = yearOk && programOk;
                option.hidden = !visible;
                option.disabled = !visible;
            });

            // A selection that the new context no longer allows is cleared
            // rather than left invisible-but-selected.
            if (select.selectedOptions[0] && select.selectedOptions[0].hidden) {
                select.value = '';
            }
        }

        function apply() {
            const yearId = year ? year.value : (term && term.value ? term.selectedOptions[0].dataset.academicYearId : '');
            const programId = program ? program.value : '';

            narrow(term, yearId, '');
            narrow(section, yearId, programId);
        }

        if (year) year.addEventListener('change', apply);
        if (program) program.addEventListener('change', apply);
        apply();
    })();
</script>
@endpush
