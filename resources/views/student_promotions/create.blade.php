@extends('layouts.app')
@section('title','New Promotion')
@section('content')
<div class="panel max-w-4xl">
    <h2 class="panel-title">New promotion request</h2>
    <p class="panel-subtitle">
        Choose the source enrollment and the target academic year / program / term / section. The request is recorded as
        pending; approving it creates the new enrollment and preserves the previous one.
    </p>

    <form method="POST" action="{{ route('student-promotions.store') }}">
        @csrf
        <div class="mt-6 grid gap-5 md:grid-cols-2">
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

            <div>
                <label class="label" for="source_enrollment_id">Source enrollment *</label>
                <select class="input" id="source_enrollment_id" name="source_enrollment_id" required>
                    <option value="">— Select the enrollment being promoted out of —</option>
                    @foreach($enrollments as $enrollment)
                        <option value="{{ $enrollment->id }}"
                                data-student-id="{{ $enrollment->student_id }}"
                                data-academic-year-id="{{ $enrollment->academic_year_id }}"
                                @selected((int) old('source_enrollment_id') === $enrollment->id)>
                            {{ $enrollment->enrollment_number }} — {{ $enrollment->academicYear?->name ?? '—' }}{{ $enrollment->program ? ' · '.$enrollment->program->name : '' }}{{ $enrollment->section ? ' · Section '.$enrollment->section->name : '' }} ({{ $enrollment->status }})
                        </option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-rose-600">@error('source_enrollment_id'){{ $message }}@enderror</p>
            </div>

            <div>
                <label class="label" for="target_academic_year_id">Target academic year *</label>
                <select class="input" id="target_academic_year_id" name="target_academic_year_id" required>
                    <option value="">— Select target year —</option>
                    @foreach($academicYears as $ay)
                        <option value="{{ $ay->id }}" @selected((int) old('target_academic_year_id') === $ay->id)>{{ $ay->name }} ({{ $ay->code }})</option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-rose-600">@error('target_academic_year_id'){{ $message }}@enderror</p>
                <p class="mt-1 text-xs text-slate-500">Must differ from the source enrollment's academic year.</p>
            </div>

            <div>
                <label class="label" for="target_program_id">Target program</label>
                <select class="input" id="target_program_id" name="target_program_id">
                    <option value="">— Keep without program —</option>
                    @foreach($programs as $p)
                        <option value="{{ $p->id }}" @selected((int) old('target_program_id') === $p->id)>{{ $p->name }} ({{ $p->code }})</option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-rose-600">@error('target_program_id'){{ $message }}@enderror</p>
            </div>

            <div>
                <label class="label" for="target_academic_term_id">Target academic term</label>
                <select class="input" id="target_academic_term_id" name="target_academic_term_id">
                    <option value="">— No term —</option>
                    @foreach($academicTerms as $term)
                        <option value="{{ $term->id }}"
                                data-academic-year-id="{{ $term->academic_year_id }}"
                                @selected((int) old('target_academic_term_id') === $term->id)>
                            {{ $term->name }} ({{ $term->code }}) — {{ $term->academicYear?->name }}
                        </option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-rose-600">@error('target_academic_term_id'){{ $message }}@enderror</p>
            </div>

            <div>
                <label class="label" for="target_section_id">Target section / batch</label>
                <select class="input" id="target_section_id" name="target_section_id">
                    <option value="">— No section —</option>
                    @foreach($sections as $section)
                        <option value="{{ $section->id }}"
                                data-academic-year-id="{{ $section->academic_year_id }}"
                                data-program-id="{{ $section->program_id }}"
                                @selected((int) old('target_section_id') === $section->id)>
                            {{ $section->name }} ({{ $section->code }}) — {{ $section->academicYear?->name }} · {{ $section->program?->name }}
                        </option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-rose-600">@error('target_section_id'){{ $message }}@enderror</p>
            </div>

            <div>
                <label class="label" for="effective_date">Effective date</label>
                <input class="input" type="date" id="effective_date" name="effective_date" value="{{ old('effective_date', now()->format('Y-m-d')) }}">
                <p class="mt-1 text-xs text-rose-600">@error('effective_date'){{ $message }}@enderror</p>
            </div>

            <div class="md:col-span-2">
                <label class="label" for="remarks">Remarks</label>
                <textarea class="input" id="remarks" name="remarks" rows="2" maxlength="2000">{{ old('remarks') }}</textarea>
                <p class="mt-1 text-xs text-rose-600">@error('remarks'){{ $message }}@enderror</p>
            </div>

            <div class="md:col-span-2 flex gap-2">
                <button class="button" type="submit">Record promotion request</button>
                <a class="button !bg-slate-200 !text-slate-700" href="{{ route('student-promotions.index') }}">Cancel</a>
            </div>
        </div>
    </form>
</div>
@endsection

@push('scripts')
<script>
    // Convenience filtering only. Every combination is re-validated server-side
    // (tenant ownership + term/year + section/year/program + student/enrollment),
    // so bypassing this script cannot create an inconsistent promotion.
    (function () {
        const student = document.getElementById('student_id');
        const source = document.getElementById('source_enrollment_id');
        const year = document.getElementById('target_academic_year_id');
        const program = document.getElementById('target_program_id');
        const term = document.getElementById('target_academic_term_id');
        const section = document.getElementById('target_section_id');

        function filter(select, predicate) {
            if (!select) return;
            Array.from(select.options).forEach(function (option, index) {
                if (index === 0) return;
                const visible = predicate(option);
                option.hidden = !visible;
                option.disabled = !visible;
            });
            if (select.selectedOptions[0] && select.selectedOptions[0].hidden) {
                select.value = '';
            }
        }

        function apply() {
            const studentId = student ? student.value : '';
            const yearId = year ? year.value : '';
            const programId = program ? program.value : '';

            filter(source, function (option) {
                return !studentId || option.dataset.studentId === studentId;
            });

            filter(term, function (option) {
                return !yearId || option.dataset.academicYearId === yearId;
            });

            filter(section, function (option) {
                const yearOk = !yearId || option.dataset.academicYearId === yearId;
                const programOk = !programId || !option.dataset.programId || option.dataset.programId === programId;
                return yearOk && programOk;
            });
        }

        if (student) student.addEventListener('change', apply);
        if (year) year.addEventListener('change', apply);
        if (program) program.addEventListener('change', apply);
        apply();
    })();
</script>
@endpush
