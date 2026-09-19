@csrf
<div class="mt-6 grid gap-5 md:grid-cols-2">
    @if(!isset($enrollment))
        <div>
            <label class="text-sm font-semibold" for="student_id">Student *</label>
            <select class="input mt-1" id="student_id" name="student_id" required>
                <option value="">— Select student —</option>
                @foreach($students as $s)
                    <option value="{{ $s->id }}" @selected((int) old('student_id', $selectedStudentId ?? 0) === $s->id)>{{ $s->student_number }} — {{ $s->first_name }} {{ $s->last_name }}</option>
                @endforeach
            </select>
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
        <div class="md:col-span-2">
            <p class="text-sm"><span class="font-semibold">Enrollment Number:</span> {{ $enrollment->enrollment_number }}</p>
            <p class="text-sm"><span class="font-semibold">Student:</span> {{ $enrollment->student->first_name }} {{ $enrollment->student->last_name }} ({{ $enrollment->student->student_number }})</p>
            <p class="text-sm"><span class="font-semibold">Academic Year:</span> {{ $enrollment->academicYear->name }}</p>
            <p class="text-sm"><span class="font-semibold">Program:</span> {{ $enrollment->program?->name ?? '—' }}</p>
        </div>
    @endif
    <div>
        <label class="text-sm font-semibold" for="section_id">Section / Batch</label>
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
        <label class="text-sm font-semibold" for="enrollment_date">Enrollment Date</label>
        <input class="input mt-1" type="date" id="enrollment_date" name="enrollment_date" value="{{ old('enrollment_date', isset($enrollment) ? $enrollment->enrollment_date?->format('Y-m-d') : now()->format('Y-m-d')) }}">
        <p class="mt-1 text-xs text-rose-600">@error('enrollment_date'){{ $message }}@enderror</p>
    </div>
    <div>
        <label class="text-sm font-semibold" for="status">Status *</label>
        <select class="input mt-1" id="status" name="status" required>
            @foreach(\App\Models\StudentEnrollment::STATUSES as $s)
                <option value="{{ $s }}" @selected(old('status', $enrollment->status ?? 'active') === $s)>{{ ucfirst($s) }}</option>
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
        if (!section) return;

        function apply() {
            const yearId = year ? year.value : '';
            const programId = program ? program.value : '';

            Array.from(section.options).forEach(function (option, index) {
                if (index === 0) return;
                const yearOk = !yearId || option.dataset.academicYearId === yearId;
                const programOk = !programId || !option.dataset.programId || option.dataset.programId === programId;
                const visible = yearOk && programOk;
                option.hidden = !visible;
                option.disabled = !visible;
            });

            if (section.selectedOptions[0] && section.selectedOptions[0].hidden) {
                section.value = '';
            }
        }

        if (year) year.addEventListener('change', apply);
        if (program) program.addEventListener('change', apply);
        apply();
    })();
</script>
@endpush
