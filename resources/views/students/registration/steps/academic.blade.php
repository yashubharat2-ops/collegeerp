@php
    /*
     * STEP 3 — Academic Record.
     *
     * Two clearly separated blocks, because they are different facts:
     *
     *   01 Previous qualifications — results earned OUTSIDE this college
     *      (Class 10, Class 12, Graduation, Post Graduation, Other). Many rows;
     *      each has its own board/university, marks or grade.
     *
     *   02 Current enrollment — the course this student is joining. This is
     *      captured as the ACADEMIC CONTEXT of the registration; no enrollment
     *      row is created here. The approver creates it when the registration is
     *      approved, so a saved draft can never look like an approved admission.
     *
     * The percentage field is read-only and derived on the client for immediate
     * feedback; the server derives it again from the marks pair and leaves it
     * empty for grade-based results.
     */
    $value = fn (string $key) => old($key, $stepData[$key] ?? $merged[$key] ?? null);
    $rows = old('qualifications', $stepData['qualifications'] ?? []);
    $rows = is_array($rows) ? array_values($rows) : [];
@endphp

<form id="erp-step-form" data-erp-step-form method="POST" action="{{ route('students.registration.step.save', ['step' => 3]) }}" novalidate>
    @csrf
    <input type="hidden" name="draft_id" value="{{ $draftId }}">

    {{-- --- 01 Previous qualifications ------------------------------------- --}}
    <section class="erp-card erp-wizard__card">
        <header class="erp-card__head">
            <span class="erp-card__num">01</span>
            <div>
                <h3 class="erp-card__title">Previous qualifications</h3>
                <p class="erp-card__desc">Results from other boards or universities. Add one row per qualification.</p>
            </div>
        </header>

        <div class="erp-card__body">
            <div data-erp-qualifications>
                @foreach($rows as $index => $row)
                    @include('students.registration._qualification', [
                        'index' => $index,
                        'row' => is_array($row) ? $row : [],
                    ])
                @endforeach
            </div>

            @if($rows === [])
                <p class="erp-alert erp-alert--info">No qualification added yet. Use “Add qualification” to record Class 10, Class 12 or a degree.</p>
            @endif

            <p>
                <button class="erp-btn erp-btn--ghost erp-btn--sm" type="button" data-erp-qualification-add>
                    Add qualification
                </button>
            </p>

            <template data-erp-qualification-template>
                @include('students.registration._qualification', ['index' => '__INDEX__', 'row' => []])
            </template>
        </div>
    </section>

    {{-- --- 02 Current enrollment context ---------------------------------- --}}
    <section class="erp-card erp-wizard__card">
        <header class="erp-card__head">
            <span class="erp-card__num">02</span>
            <div>
                <h3 class="erp-card__title">Current course / enrollment</h3>
                <p class="erp-card__desc">The course being joined. An enrollment is created only when the registration is approved.</p>
            </div>
        </header>

        <div class="erp-card__body">
            <div class="erp-grid">
                <div class="erp-field erp-col-3">
                    <label class="erp-label" for="academic_year_id">Academic year <span class="erp-req">*</span></label>
                    <select class="erp-input" id="academic_year_id" name="academic_year_id" required>
                        <option value="">— Select —</option>
                        @foreach($academicYears as $year)
                            <option value="{{ $year->id }}" @selected((string) $value('academic_year_id') === (string) $year->id)>
                                {{ $year->name }} ({{ $year->code }})
                            </option>
                        @endforeach
                    </select>
                    <p class="erp-error">@error('academic_year_id'){{ $message }}@enderror</p>
                </div>

                <div class="erp-field erp-col-3">
                    <label class="erp-label" for="program_id">Programme / Course <span class="erp-req">*</span></label>
                    <select class="erp-input" id="program_id" name="program_id" required>
                        <option value="">— Select —</option>
                        @foreach($programs as $program)
                            <option value="{{ $program->id }}" @selected((string) $value('program_id') === (string) $program->id)>
                                {{ $program->name }} ({{ $program->code }})
                            </option>
                        @endforeach
                    </select>
                    <p class="erp-error">@error('program_id'){{ $message }}@enderror</p>
                </div>

                <div class="erp-field erp-col-3">
                    <label class="erp-label" for="section_id">Section / Batch</label>
                    <select class="erp-input" id="section_id" name="section_id">
                        <option value="">— No section —</option>
                        @foreach($sections as $section)
                            <option value="{{ $section->id }}"
                                    data-academic-year-id="{{ $section->academic_year_id }}"
                                    data-program-id="{{ $section->program_id }}"
                                    @selected((string) $value('section_id') === (string) $section->id)>
                                {{ $section->name }} ({{ $section->code }})
                            </option>
                        @endforeach
                    </select>
                    <p class="erp-error">@error('section_id'){{ $message }}@enderror</p>
                </div>

                <div class="erp-field erp-col-3">
                    <label class="erp-label" for="semester">Semester / Year of study</label>
                    <input class="erp-input" id="semester" name="semester" value="{{ $value('semester') }}" maxlength="30" placeholder="e.g. 1 or Semester 3">
                    <p class="erp-error">@error('semester'){{ $message }}@enderror</p>
                </div>

                <div class="erp-field erp-col-3">
                    <label class="erp-label" for="admission_date">Admission date</label>
                    <input class="erp-input" id="admission_date" name="admission_date" type="date" lang="en-IN" value="{{ $value('admission_date') }}">
                    <p class="erp-error">@error('admission_date'){{ $message }}@enderror</p>
                </div>

                <div class="erp-field erp-col-3">
                    <label class="erp-label" for="enrollment_date">Enrollment date</label>
                    <input class="erp-input" id="enrollment_date" name="enrollment_date" type="date" lang="en-IN" value="{{ $value('enrollment_date') }}">
                    <p class="erp-help">Applied when an approver approves the registration.</p>
                    <p class="erp-error">@error('enrollment_date'){{ $message }}@enderror</p>
                </div>

                <div class="erp-field erp-col-3">
                    <span class="erp-label">Admission category</span>
                    <p class="erp-basic">{{ $admissionCategory }}</p>
                    <p class="erp-help">
                        Captured on
                        <a href="{{ route('students.registration.step', ['step' => 1]) }}">Step 1</a>
                        so there is only one copy of it.
                    </p>
                </div>
            </div>
        </div>
    </section>

    @include('students.registration._actions', ['step' => 3, 'draftId' => $draftId, 'submitMode' => false])
</form>
