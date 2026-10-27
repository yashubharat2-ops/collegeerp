@php
    /*
     * STEP 2 — Academic Record.
     *
     * Two clearly separated blocks, because they are different facts:
     *
     *   Previous qualifications — results earned OUTSIDE this college
     *      (Class 10, Class 12, Graduation, Post Graduation, Other). Many rows;
     *      each has its own board/university, marks or grade.
     *
     *   Current course / enrollment — the course this student is joining. This is
     *      captured as the ACADEMIC CONTEXT of the registration; no enrollment
     *      row is created here. The approver creates it when the registration is
     *      approved, so a saved draft can never look like an approved admission.
     *
     * The current course follows the ERP's one academic order, and each level
     * depends on the ones before it:
     *
     *   1 Academic Year → 2 Programme/Course → 3 Semester/Year of study →
     *   4 Section/Batch → 5 Admission date → 6 Enrollment date →
     *   7 Admission category
     *
     * The semester list is the college's CONFIGURED academic levels for the
     * chosen year (academic_terms); the section list is the sections of the
     * chosen year and programme. Both are refreshed by the wizard script through
     * students.registration.academic-options, and the server re-validates every
     * combination on save, so the browser is never the authority.
     *
     * The percentage field is read-only and derived on the client for immediate
     * feedback; the server derives it again from the marks pair and leaves it
     * empty for grade-based results.
     */
    $value = fn (string $key) => old($key, $stepData[$key] ?? $merged[$key] ?? null);
    $rows = old('qualifications', $stepData['qualifications'] ?? []);
    $rows = is_array($rows) ? array_values($rows) : [];
    $hasYears = ($academicYears ?? collect())->isNotEmpty();
    $termId = (string) $value('academic_term_id');
@endphp

<form id="erp-step-form" data-erp-step-form method="POST" action="{{ route('students.registration.step.save', ['step' => 2]) }}" novalidate>
    @csrf
    <input type="hidden" name="draft_id" value="{{ $draftId }}">

    {{-- --- Previous qualifications ----------------------------------------- --}}
    <section class="erp-card erp-wizard__card" aria-labelledby="wz-qualifications">
        <header class="erp-card__head">
            <div>
                <h3 class="erp-card__title" id="wz-qualifications">Previous qualifications</h3>
                <p class="erp-card__desc">Results from other boards or universities. Add one row per qualification.</p>
            </div>
            <button class="erp-btn erp-btn--outline erp-btn--sm erp-wizard__card-toggle" type="button"
                    data-erp-qualification-add>
                + Add Qualification
            </button>
        </header>

        <div class="erp-card__body">
            <p class="erp-alert erp-alert--info" data-erp-qualifications-empty @if($rows !== []) hidden @endif>
                No qualification added yet. Use “+ Add Qualification” to record Class 10, Class 12 or a degree.
            </p>

            <div data-erp-qualifications>
                @foreach($rows as $index => $row)
                    @include('students.registration._qualification', [
                        'index' => $index,
                        'row' => is_array($row) ? $row : [],
                    ])
                @endforeach
            </div>

            <template data-erp-qualification-template>
                @include('students.registration._qualification', ['index' => '__INDEX__', 'row' => []])
            </template>
        </div>
    </section>

    {{-- --- Current course / enrollment context ----------------------------- --}}
    <section class="erp-card erp-wizard__card" aria-labelledby="wz-current-course">
        <header class="erp-card__head">
            <div>
                <h3 class="erp-card__title" id="wz-current-course">Current Course</h3>
                <p class="erp-card__desc">The course being joined. An enrollment is created only when the registration is approved.</p>
            </div>
        </header>

        <div class="erp-card__body">
            <div class="erp-grid">
                {{-- 1. Academic year -------------------------------------------- --}}
                <div class="erp-field erp-col-3">
                    <label class="erp-label" for="academic_year_id">Academic year <span class="erp-req">*</span></label>
                    <select class="erp-input" id="academic_year_id" name="academic_year_id" required
                            data-erp-academic-year>
                        <option value="">— Select —</option>
                        @foreach($academicYears as $year)
                            <option value="{{ $year->id }}" @selected((string) $value('academic_year_id') === (string) $year->id)>
                                {{ $year->name }} ({{ $year->code }})
                            </option>
                        @endforeach
                    </select>
                    <p class="erp-error">@error('academic_year_id'){{ $message }}@enderror</p>
                </div>

                {{-- 2. Programme / course --------------------------------------- --}}
                <div class="erp-field erp-col-3">
                    <label class="erp-label" for="program_id">Programme / Course <span class="erp-req">*</span></label>
                    <select class="erp-input" id="program_id" name="program_id" required
                            data-erp-academic-program>
                        <option value="">— Select —</option>
                        @foreach($programs as $program)
                            <option value="{{ $program->id }}" @selected((string) $value('program_id') === (string) $program->id)>
                                {{ $program->name }} ({{ $program->code }})
                            </option>
                        @endforeach
                    </select>
                    <p class="erp-error">@error('program_id'){{ $message }}@enderror</p>
                </div>

                {{-- 3. Semester / year of study -------------------------------- --}}
                <div class="erp-field erp-col-3">
                    <label class="erp-label" for="academic_term_id">Semester / Year of study</label>

                    {{-- One slot, two possible controls, exactly one of which is
                         posted: a SELECT of the configured levels (the normal
                         case) or — only while the chosen year has no configured
                         levels — a free-text fallback so the wizard stays usable
                         before the academic structure exists. The wizard script
                         swaps the control when the academic year changes. --}}
                    <div data-erp-academic-term-slot>
                        @if($termsConfigured)
                            <select class="erp-input" id="academic_term_id" name="academic_term_id"
                                    data-erp-academic-term
                                    data-empty-label="— Select —">
                                <option value="">— Select —</option>
                                @foreach($terms as $term)
                                    <option value="{{ $term->id }}" @selected($termId === (string) $term->id)>
                                        {{ $term->name }}@if($term->code) ({{ $term->code }})@endif
                                    </option>
                                @endforeach
                            </select>
                        @else
                            <input class="erp-input" id="semester" name="semester" maxlength="30"
                                   data-erp-academic-term-text
                                   value="{{ old('semester', $stepData['semester'] ?? $merged['semester'] ?? '') }}"
                                   placeholder="e.g. 1 or Semester 3">
                        @endif
                    </div>

                    <p class="erp-help" data-erp-academic-term-note>
                        @if($termsConfigured)
                            Configured levels of the selected academic year.
                        @else
                            No semester / year of study is configured for this academic year yet, so it is entered as text.
                            Choosing a year that has configured levels replaces this field with a list.
                        @endif
                    </p>
                    <p class="erp-error">@error('academic_term_id'){{ $message }}@enderror</p>
                    <p class="erp-error">@error('semester'){{ $message }}@enderror</p>
                </div>

                {{-- 4. Section / batch ------------------------------------------ --}}
                <div class="erp-field erp-col-3">
                    <label class="erp-label" for="section_id">Section / Batch</label>
                    <select class="erp-input" id="section_id" name="section_id"
                            data-erp-academic-section
                            data-empty-label="— No section —">
                        <option value="">— No section —</option>
                        @foreach($sections as $section)
                            <option value="{{ $section->id }}" @selected((string) $value('section_id') === (string) $section->id)>
                                {{ $section->name }}@if($section->code) ({{ $section->code }})@endif
                            </option>
                        @endforeach
                    </select>
                    <p class="erp-help">
                        Only sections of the selected academic year and programme are offered. A section is optional
                        until the programme assigns one.
                    </p>
                    <p class="erp-error">@error('section_id'){{ $message }}@enderror</p>
                </div>

                {{-- 5. Admission date ------------------------------------------- --}}
                <div class="erp-field erp-col-3">
                    <label class="erp-label" for="admission_date">Admission date</label>
                    <input class="erp-input" id="admission_date" name="admission_date" type="date" lang="en-IN" value="{{ $value('admission_date') }}">
                    <p class="erp-error">@error('admission_date'){{ $message }}@enderror</p>
                </div>

                {{-- 6. Enrollment date ------------------------------------------ --}}
                <div class="erp-field erp-col-3">
                    <label class="erp-label" for="enrollment_date">Enrollment date</label>
                    <input class="erp-input" id="enrollment_date" name="enrollment_date" type="date" lang="en-IN" value="{{ $value('enrollment_date') }}">
                    <p class="erp-help">Applied when an approver approves the registration.</p>
                    <p class="erp-error">@error('enrollment_date'){{ $message }}@enderror</p>
                </div>

                {{-- 7. Admission category --------------------------------------- --}}
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

    @include('students.registration._actions', ['step' => 2, 'draftId' => $draftId, 'submitMode' => false])
</form>
