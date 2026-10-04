@php
    /*
     * The Create/Edit form is ONE partial for both pages: `$student` is present
     * on edit and absent on create. The six numbered sections below are identical
     * either way — only the read-only context strip (edit) and the optional first
     * enrollment block (create) differ. Every section reads the same Student
     * record, so an operator never needs a second screen to complete a person.
     *
     * Order (fixed): 01 Academic / admission · 02 Basic information (with the
     * Government / identity sub-block) · 03 Parent / guardian · 04 Contact ·
     * 05 Additional information · 06 Documents. Government IDs are a sub-block of
     * Basic information, not a section of their own.
     *
     * Styling lives in the STATIC stylesheet public/css/erp-student-form.css
     * (linked from resources/views/layouts/app.blade.php): `.erp-card` per
     * section, `.erp-grid`/`.erp-col-*` for the field grid, `.erp-input` for the
     * controls. No Tailwind utility is used, because the layout only links the
     * Vite bundle when a build exists — this form must render as a compact
     * ERP screen with no build at all.
     *
     * Security rules applied by this view:
     * - the stored Aadhaar / government ID numbers are NEVER echoed into the
     *   HTML — not even after a failed validation — so the full number cannot be
     *   read out of a page source or a browser cache; only the masked helpers
     *   are shown, and a blank input means "keep what is stored";
     * - nothing the server owns (tenant, student number, admission link, audit
     *   stamps, derived identity columns, stored file paths) has an input here.
     */
    $isEdit = isset($student) && $student !== null;
    $currentEnrollment = $isEdit ? $student->currentEnrollment() : null;
    $canEnroll = auth()->user()?->can('create', App\Models\StudentEnrollment::class) ?? false;
    // The first-enrollment block is only rendered for a user who may create
    // enrollments AND when the college actually has an academic year to enroll
    // into — the form request strips the fields for anyone else.
    $showEnrollmentBlock = ! $isEdit && $canEnroll && ($academicYears ?? collect())->isNotEmpty();

    /*
     * Dates: the control must READ day/month/year, but the value the browser
     * submits (and the server validates) stays the native YYYY-MM-DD.
     *
     * A native <input type="date"> paints its segments in the BROWSER's locale
     * order — month/day/year on an en-US browser — and that order cannot be
     * restyled: `lang` is only a hint and the ::-webkit-datetime-edit-* fields
     * cannot be regrouped with their separators. So each date field is a small
     * shim: a visible, read-only text mirror showing DD/MM/YYYY, overlaid by the
     * real <input type="date"> (same id/name/value/validation — it still owns the
     * picker and still posts ISO). The mirror is server-rendered from the same
     * value, and the script at the end of this partial keeps it in step.
     *
     * $dateDisplay stays string-only and exception-free on purpose: a crafted
     * request can put an array or an invalid date in `old()`, and re-rendering
     * the form must show the validation message rather than fail formatting it.
     */
    $dateDisplay = function ($iso) {
        if (is_string($iso) && preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $iso, $parts) === 1) {
            return $parts[3].'/'.$parts[2].'/'.$parts[1];
        }

        return '';
    };
    // Same defaults the inputs always had — computed once so the mirror and the
    // control can never disagree.
    $dobValue = old('date_of_birth', isset($student->date_of_birth) ? $student->date_of_birth->format('Y-m-d') : '');
    $admissionValue = old('admission_date', isset($student->admission_date) ? $student->admission_date->format('Y-m-d') : now()->format('Y-m-d'));
    $enrollmentValue = old('enrollment_date', now()->format('Y-m-d'));
    $dobDisplay = $dateDisplay($dobValue);
    $admissionDisplay = $dateDisplay($admissionValue);
    $enrollmentDisplay = $dateDisplay($enrollmentValue);
@endphp

<div class="erp-student-form">
@csrf

{{-- ===================================================================== --}}
{{-- 01 Academic / admission                                                --}}
{{-- ===================================================================== --}}
<section class="erp-card">
    <header class="erp-card__head">
        <span class="erp-card__num">01</span>
        <div>
            <h3 class="erp-card__title">Academic / admission</h3>
            <p class="erp-card__desc">Admission context and, on the create screen, the student's first enrollment. Year-by-year enrollment stays in the Enrollment module.</p>
        </div>
    </header>

    <div class="erp-card__body">
        @if($isEdit)
            {{-- Read-only context: existing records only, never editable here. --}}
            <div class="erp-strip">
                <span>Student no: <strong>{{ $student->student_number }}</strong></span>
                <span>
                    Admission:
                    @if($student->admissionApplication)
                        <strong>{{ $student->admissionApplication->application_number }}</strong> · {{ ucfirst((string) $student->admissionApplication->status) }}
                    @else
                        direct registration
                    @endif
                </span>
                <span>
                    Enrollment:
                    @if($currentEnrollment)
                        <strong>{{ $currentEnrollment->enrollment_number }}</strong>
                        · {{ $currentEnrollment->academicYear?->name ?? '—' }}
                        @if($currentEnrollment->program) · {{ $currentEnrollment->program->name }} @endif
                        @if($currentEnrollment->section) · {{ $currentEnrollment->section->name }} @endif
                        · {{ ucfirst((string) $currentEnrollment->status) }}
                    @else
                        no active enrollment
                    @endif
                </span>
                @if($currentEnrollment?->program?->department)
                    <span>Department: <strong>{{ $currentEnrollment->program->department->name }}</strong></span>
                @endif
                @can('viewAny', App\Models\StudentEnrollment::class)
                    <a href="{{ route('students.show', ['student' => $student, 'tab' => 'enrollments']) }}">Manage enrollments</a>
                @endcan
            </div>
        @endif

        <div class="erp-grid">
            @if($showEnrollmentBlock)
                <div class="erp-field erp-col-12">
                    <p class="erp-subhead">First enrollment (optional)</p>
                    <p class="erp-help">Opens the student's first enrollment in the same save; a section must belong to the chosen academic year and program. Leaving it empty creates the student with no enrollment.</p>
                </div>
                <div class="erp-field erp-col-3">
                    <label class="erp-label" for="academic_year_id">Academic Year</label>
                    <select class="erp-input" id="academic_year_id" name="academic_year_id">
                        <option value="">— No enrollment —</option>
                        @foreach($academicYears as $ay)
                            <option value="{{ $ay->id }}" @selected((int) old('academic_year_id') === $ay->id)>{{ $ay->name }} ({{ $ay->code }})</option>
                        @endforeach
                    </select>
                    <p class="erp-error">@error('academic_year_id'){{ $message }}@enderror</p>
                </div>
                <div class="erp-field erp-col-3">
                    <label class="erp-label" for="program_id">Program / Course</label>
                    <select class="erp-input" id="program_id" name="program_id">
                        <option value="">— Select program —</option>
                        @foreach($programs as $program)
                            <option value="{{ $program->id }}" @selected((int) old('program_id') === $program->id)>{{ $program->name }} ({{ $program->code }})</option>
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
                                    @selected((int) old('section_id') === $section->id)>
                                {{ $section->name }} ({{ $section->code }}) — {{ $section->academicYear?->name }} · {{ $section->program?->name }}
                            </option>
                        @endforeach
                    </select>
                    <p class="erp-error">@error('section_id'){{ $message }}@enderror</p>
                </div>
                <div class="erp-field erp-col-3">
                    <label class="erp-label" for="enrollment_date">Enrollment Date</label>
                    <div class="erp-date">
                        <input class="erp-input erp-date__text" type="text" value="{{ $enrollmentDisplay }}" placeholder="DD/MM/YYYY" readonly tabindex="-1" aria-hidden="true" data-erp-date-text="enrollment_date">
                        <input class="erp-input erp-date__native" lang="en-IN" id="enrollment_date" name="enrollment_date" type="date" value="{{ $enrollmentValue }}" data-erp-date-native="enrollment_date">
                    </div>
                    <p class="erp-error">@error('enrollment_date'){{ $message }}@enderror</p>
                </div>
            @endif

            <div class="erp-field erp-col-3">
                <label class="erp-label" for="admission_date">Admission Date</label>
                <div class="erp-date">
                    <input class="erp-input erp-date__text" type="text" value="{{ $admissionDisplay }}" placeholder="DD/MM/YYYY" readonly tabindex="-1" aria-hidden="true" data-erp-date-text="admission_date">
                    <input class="erp-input erp-date__native" lang="en-IN" id="admission_date" name="admission_date" type="date" value="{{ $admissionValue }}" data-erp-date-native="admission_date">
                </div>
                <p class="erp-error">@error('admission_date'){{ $message }}@enderror</p>
            </div>
            <div class="erp-field erp-col-3">
                <label class="erp-label" for="status">Student Status <span class="erp-req">*</span></label>
                <select class="erp-input" id="status" name="status" required>
                    <option value="">— Select —</option>
                    @foreach(\App\Models\Student::STATUSES as $s)
                        <option value="{{ $s }}" @selected(old('status', $student->status ?? 'active') === $s)>{{ ucfirst($s) }}</option>
                    @endforeach
                </select>
                <p class="erp-error">@error('status'){{ $message }}@enderror</p>
            </div>

            <div class="erp-field erp-col-12">
                <p class="erp-subhead">Previous education</p>
            </div>
            <div class="erp-field erp-col-6">
                <label class="erp-label" for="previous_school_name">School / Institution</label>
                <input class="erp-input" id="previous_school_name" name="previous_school_name" value="{{ old('previous_school_name', $student->previous_school_name ?? '') }}" maxlength="255">
                <p class="erp-error">@error('previous_school_name'){{ $message }}@enderror</p>
            </div>
            <div class="erp-field erp-col-6">
                <label class="erp-label" for="previous_school_board">Board / University</label>
                <input class="erp-input" id="previous_school_board" name="previous_school_board" value="{{ old('previous_school_board', $student->previous_school_board ?? '') }}" maxlength="100">
                <p class="erp-error">@error('previous_school_board'){{ $message }}@enderror</p>
            </div>
            <div class="erp-field erp-col-4">
                <label class="erp-label" for="previous_qualification">Qualification Passed</label>
                <input class="erp-input" id="previous_qualification" name="previous_qualification" value="{{ old('previous_qualification', $student->previous_qualification ?? '') }}" maxlength="100" placeholder="e.g. Class XII, Diploma">
                <p class="erp-error">@error('previous_qualification'){{ $message }}@enderror</p>
            </div>
            <div class="erp-field erp-col-4">
                <label class="erp-label" for="previous_exam_year">Year of Passing</label>
                <input class="erp-input" id="previous_exam_year" name="previous_exam_year" type="number" min="1900" max="2100" step="1" value="{{ old('previous_exam_year', $student->previous_exam_year ?? '') }}">
                <p class="erp-error">@error('previous_exam_year'){{ $message }}@enderror</p>
            </div>
            <div class="erp-field erp-col-4">
                <label class="erp-label" for="previous_percentage">Percentage / Marks</label>
                <input class="erp-input" id="previous_percentage" name="previous_percentage" type="number" min="0" max="100" step="0.01" value="{{ old('previous_percentage', $student->previous_percentage ?? '') }}">
                <p class="erp-error">@error('previous_percentage'){{ $message }}@enderror</p>
            </div>
        </div>
    </div>
</section>

{{-- ===================================================================== --}}
{{-- 02 Basic information (+ Government / identity sub-block)               --}}
{{-- ===================================================================== --}}
<section class="erp-card">
    <header class="erp-card__head">
        <span class="erp-card__num">02</span>
        <div>
            <h3 class="erp-card__title">Basic information</h3>
            <p class="erp-card__desc">The person's own details. Only the first name is required here; the photograph and identity numbers are optional.</p>
        </div>
    </header>

    <div class="erp-card__body">
        <div class="erp-basic">
            <div class="erp-grid">
                <div class="erp-field erp-col-4">
                    <label class="erp-label" for="first_name">First Name <span class="erp-req">*</span></label>
                    <input class="erp-input" id="first_name" name="first_name" value="{{ old('first_name', $student->first_name ?? '') }}" required maxlength="255">
                    <p class="erp-error">@error('first_name'){{ $message }}@enderror</p>
                </div>
                <div class="erp-field erp-col-4">
                    <label class="erp-label" for="middle_name">Middle Name</label>
                    <input class="erp-input" id="middle_name" name="middle_name" value="{{ old('middle_name', $student->middle_name ?? '') }}" maxlength="255">
                    <p class="erp-error">@error('middle_name'){{ $message }}@enderror</p>
                </div>
                <div class="erp-field erp-col-4">
                    <label class="erp-label" for="last_name">Last Name</label>
                    <input class="erp-input" id="last_name" name="last_name" value="{{ old('last_name', $student->last_name ?? '') }}" maxlength="255">
                    <p class="erp-error">@error('last_name'){{ $message }}@enderror</p>
                </div>

                <div class="erp-field erp-col-3">
                    <label class="erp-label" for="date_of_birth">Date of Birth</label>
                    <div class="erp-date">
                        <input class="erp-input erp-date__text" type="text" value="{{ $dobDisplay }}" placeholder="DD/MM/YYYY" readonly tabindex="-1" aria-hidden="true" data-erp-date-text="date_of_birth">
                        <input class="erp-input erp-date__native" lang="en-IN" id="date_of_birth" name="date_of_birth" type="date" value="{{ $dobValue }}" data-erp-date-native="date_of_birth">
                    </div>
                    <p class="erp-error">@error('date_of_birth'){{ $message }}@enderror</p>
                </div>
                <div class="erp-field erp-col-3">
                    <label class="erp-label" for="gender">Gender</label>
                    <select class="erp-input" id="gender" name="gender">
                        <option value="">— Select —</option>
                        @foreach(\App\Models\Student::GENDERS as $gender)
                            <option value="{{ $gender }}" @selected(old('gender', $student->gender ?? '') === $gender)>{{ ucfirst(str_replace('_', ' ', $gender)) }}</option>
                        @endforeach
                    </select>
                    <p class="erp-error">@error('gender'){{ $message }}@enderror</p>
                </div>
                <div class="erp-field erp-col-3">
                    <label class="erp-label" for="blood_group">Blood Group</label>
                    <select class="erp-input" id="blood_group" name="blood_group">
                        <option value="">— Select —</option>
                        @foreach(\App\Models\Student::BLOOD_GROUPS as $group)
                            <option value="{{ $group }}" @selected(old('blood_group', $student->blood_group ?? '') === $group)>{{ $group === 'unknown' ? 'Not known' : $group }}</option>
                        @endforeach
                    </select>
                    <p class="erp-error">@error('blood_group'){{ $message }}@enderror</p>
                </div>
                <div class="erp-field erp-col-3">
                    <label class="erp-label" for="category">Category</label>
                    <select class="erp-input" id="category" name="category">
                        <option value="">— Select —</option>
                        @foreach(\App\Models\Student::CATEGORIES as $category)
                            <option value="{{ $category }}" @selected(old('category', $student->category ?? '') === $category)>{{ \App\Models\Student::categoryLabel($category) }}</option>
                        @endforeach
                    </select>
                    <p class="erp-error">@error('category'){{ $message }}@enderror</p>
                </div>

                <div class="erp-field erp-col-4">
                    <label class="erp-label" for="nationality">Nationality</label>
                    <input class="erp-input" id="nationality" name="nationality" value="{{ old('nationality', $student->nationality ?? '') }}" maxlength="100">
                    <p class="erp-error">@error('nationality'){{ $message }}@enderror</p>
                </div>
            </div>

            {{-- Compact photograph block: fixed ~108px column, never a large empty area. --}}
            <aside class="erp-photo-block">
                @if($isEdit && $student->photo_path)
                    <img class="erp-avatar" src="{{ route('students.photo', $student) }}" alt="Current photograph of {{ $student->fullName() }}">
                @else
                    <span class="erp-avatar erp-avatar--empty" aria-hidden="true">No photo</span>
                @endif
                <input class="erp-file-input" id="photo" name="photo" type="file" accept="image/jpeg,image/png,image/webp">
                <label class="erp-photo-btn" for="photo">Browse…</label>
                <p class="erp-photo-help">JPG / PNG / WebP · 2 MB</p>
                @if($isEdit && $student->photo_path)
                    <label class="erp-check erp-photo-remove"><input type="checkbox" name="remove_photo" value="1" @checked(old('remove_photo'))> Remove photo</label>
                @endif
                <p class="erp-error">@error('photo'){{ $message }}@enderror</p>
            </aside>
        </div>

        {{-- Government / identity: a small sub-block of Basic information. --}}
        <div class="erp-subcard">
            <header class="erp-subcard__head">
                <span class="erp-subcard__title">Government / identity</span>
                <span class="erp-subcard__desc">Stored encrypted; only the last four characters are ever displayed, exported or audited.</span>
            </header>

            <div class="erp-grid">
                <div class="erp-field erp-col-6">
                    <label class="erp-label" for="aadhaar_number">Aadhaar Number{{ $isEdit && $student->hasAadhaar() ? ' (replace)' : '' }}</label>
                    {{-- value is ALWAYS empty: the stored number is never echoed back into the page. --}}
                    <input class="erp-input" id="aadhaar_number" name="aadhaar_number" value="" inputmode="numeric" autocomplete="off" maxlength="20" placeholder="{{ $isEdit && $student->hasAadhaar() ? 'Leave blank to keep the stored number' : '12 digits, e.g. 9999 4105 7058' }}">
                    @if($isEdit && $student->hasAadhaar())
                        <div class="erp-meta">
                            <span>Recorded: <strong>{{ $student->maskedAadhaar() }}</strong></span>
                            <label class="erp-check"><input type="checkbox" name="remove_aadhaar" value="1" @checked(old('remove_aadhaar'))> Remove</label>
                        </div>
                    @endif
                    <p class="erp-error">@error('aadhaar_number'){{ $message }}@enderror</p>
                    <p class="erp-help">Checksum-verified, the number stays encrypted, and it is never re-filled after a validation error.</p>
                </div>
                <div class="erp-field erp-col-6">
                    <label class="erp-label" for="apaar_id">EBC / ABC / APAAR ID</label>
                    <input class="erp-input" id="apaar_id" name="apaar_id" value="{{ old('apaar_id', $student->apaar_id ?? '') }}" inputmode="numeric" maxlength="30" placeholder="12 digits">
                    <p class="erp-error">@error('apaar_id'){{ $message }}@enderror</p>
                    <p class="erp-help">The academic credit registry ID — not a secret, so it is shown whole.</p>
                </div>

                <div class="erp-field erp-col-4">
                    <label class="erp-label" for="govt_id_type">Identity Type</label>
                    <select class="erp-input" id="govt_id_type" name="govt_id_type">
                        <option value="">— None —</option>
                        @foreach(\App\Models\Student::GOVT_ID_TYPES as $type)
                            <option value="{{ $type }}" @selected(old('govt_id_type', $student->govt_id_type ?? '') === $type)>{{ \App\Models\Student::govtIdTypeLabel($type) }}</option>
                        @endforeach
                    </select>
                    <p class="erp-error">@error('govt_id_type'){{ $message }}@enderror</p>
                    @if($isEdit && $student->maskedGovtIdNumber())
                        <p class="erp-help">“— None —” clears the stored type and number; leave the number blank to keep it while correcting the type.</p>
                    @endif
                </div>
                <div class="erp-field erp-col-8">
                    <label class="erp-label" for="govt_id_number">Identity Number</label>
                    {{-- value is ALWAYS empty (same rule as Aadhaar). --}}
                    <input class="erp-input" id="govt_id_number" name="govt_id_number" value="" autocomplete="off" maxlength="100" placeholder="{{ $isEdit && $student->maskedGovtIdNumber() ? 'Leave blank to keep the stored number' : 'Number as printed on the document' }}">
                    @if($isEdit && $student->maskedGovtIdNumber())
                        <div class="erp-meta">
                            <span>{{ \App\Models\Student::govtIdTypeLabel($student->govt_id_type) }} recorded: <strong>{{ $student->maskedGovtIdNumber() }}</strong></span>
                            <label class="erp-check"><input type="checkbox" name="remove_govt_id" value="1" @checked(old('remove_govt_id'))> Remove</label>
                        </div>
                    @endif
                    <p class="erp-error">@error('govt_id_number'){{ $message }}@enderror</p>
                </div>
            </div>
        </div>
    </div>
</section>

{{-- ===================================================================== --}}
{{-- 03 Parent / guardian                                                   --}}
{{-- ===================================================================== --}}
<section class="erp-card">
    <header class="erp-card__head">
        <span class="erp-card__num">03</span>
        <div>
            <h3 class="erp-card__title">Parent / guardian</h3>
            <p class="erp-card__desc">The people the institute contacts — the named guardian first, then the father, then the mother.</p>
        </div>
    </header>

    <div class="erp-card__body">
        <div class="erp-grid">
            <div class="erp-field erp-col-6">
                <label class="erp-label" for="father_name">Father's Name</label>
                <input class="erp-input" id="father_name" name="father_name" value="{{ old('father_name', $student->father_name ?? '') }}" maxlength="255">
                <p class="erp-error">@error('father_name'){{ $message }}@enderror</p>
            </div>
            <div class="erp-field erp-col-6">
                <label class="erp-label" for="mother_name">Mother's Name</label>
                <input class="erp-input" id="mother_name" name="mother_name" value="{{ old('mother_name', $student->mother_name ?? '') }}" maxlength="255">
                <p class="erp-error">@error('mother_name'){{ $message }}@enderror</p>
            </div>

            <div class="erp-field erp-col-4">
                <label class="erp-label" for="guardian_name">Guardian's Name</label>
                <input class="erp-input" id="guardian_name" name="guardian_name" value="{{ old('guardian_name', $student->guardian_name ?? '') }}" maxlength="255">
                <p class="erp-error">@error('guardian_name'){{ $message }}@enderror</p>
            </div>
            <div class="erp-field erp-col-4">
                <label class="erp-label" for="guardian_relation">Guardian Relationship</label>
                <select class="erp-input" id="guardian_relation" name="guardian_relation">
                    <option value="">— Select —</option>
                    @foreach(\App\Models\Student::GUARDIAN_RELATIONS as $relation)
                        <option value="{{ $relation }}" @selected(old('guardian_relation', $student->guardian_relation ?? '') === $relation)>{{ \App\Models\Student::guardianRelationLabel($relation) }}</option>
                    @endforeach
                </select>
                <p class="erp-error">@error('guardian_relation'){{ $message }}@enderror</p>
            </div>
            <div class="erp-field erp-col-4">
                <label class="erp-label" for="guardian_phone">Guardian Phone</label>
                <input class="erp-input" id="guardian_phone" name="guardian_phone" value="{{ old('guardian_phone', $student->guardian_phone ?? '') }}" maxlength="30">
                <p class="erp-error">@error('guardian_phone'){{ $message }}@enderror</p>
            </div>

            <div class="erp-field erp-col-6">
                <label class="erp-label" for="guardian_email">Guardian's Email</label>
                <input class="erp-input" id="guardian_email" name="guardian_email" type="email" value="{{ old('guardian_email', $student->guardian_email ?? '') }}" maxlength="255">
                <p class="erp-error">@error('guardian_email'){{ $message }}@enderror</p>
            </div>
            <div class="erp-field erp-col-6">
                <label class="erp-label" for="guardian_occupation">Guardian's Occupation</label>
                <input class="erp-input" id="guardian_occupation" name="guardian_occupation" value="{{ old('guardian_occupation', $student->guardian_occupation ?? '') }}" maxlength="150">
                <p class="erp-error">@error('guardian_occupation'){{ $message }}@enderror</p>
            </div>

            <div class="erp-field erp-col-12">
                <label class="erp-label" for="guardian_address">Guardian's Address</label>
                <input class="erp-input" id="guardian_address" name="guardian_address" value="{{ old('guardian_address', $student->guardian_address ?? '') }}" maxlength="2000" placeholder="Only when it differs from the student's address">
                <p class="erp-error">@error('guardian_address'){{ $message }}@enderror</p>
            </div>
        </div>
    </div>
</section>

{{-- ===================================================================== --}}
{{-- 04 Contact                                                             --}}
{{-- ===================================================================== --}}
<section class="erp-card">
    <header class="erp-card__head">
        <span class="erp-card__num">04</span>
        <div>
            <h3 class="erp-card__title">Contact</h3>
            <p class="erp-card__desc">Address and the numbers the list, the exports and the reports use.</p>
        </div>
    </header>

    <div class="erp-card__body">
        <div class="erp-grid">
            <div class="erp-field erp-col-4">
                <label class="erp-label" for="email">Email</label>
                <input class="erp-input" id="email" name="email" type="email" value="{{ old('email', $student->email ?? '') }}" maxlength="255">
                <p class="erp-error">@error('email'){{ $message }}@enderror</p>
            </div>
            <div class="erp-field erp-col-4">
                <label class="erp-label" for="phone">Mobile</label>
                <input class="erp-input" id="phone" name="phone" value="{{ old('phone', $student->phone ?? '') }}" maxlength="30">
                <p class="erp-error">@error('phone'){{ $message }}@enderror</p>
            </div>
            <div class="erp-field erp-col-4">
                <label class="erp-label" for="alternate_phone">Alternate Mobile</label>
                <input class="erp-input" id="alternate_phone" name="alternate_phone" value="{{ old('alternate_phone', $student->alternate_phone ?? '') }}" maxlength="30">
                <p class="erp-error">@error('alternate_phone'){{ $message }}@enderror</p>
            </div>

            <div class="erp-field erp-col-9">
                <label class="erp-label" for="address_line_1">Address Line 1</label>
                <input class="erp-input" id="address_line_1" name="address_line_1" value="{{ old('address_line_1', $student->address_line_1 ?? '') }}" maxlength="2000">
                <p class="erp-error">@error('address_line_1'){{ $message }}@enderror</p>
            </div>
            <div class="erp-field erp-col-3">
                <label class="erp-label" for="postal_code">Postal Code</label>
                <input class="erp-input" id="postal_code" name="postal_code" value="{{ old('postal_code', $student->postal_code ?? '') }}" maxlength="20">
                <p class="erp-error">@error('postal_code'){{ $message }}@enderror</p>
            </div>

            <div class="erp-field erp-col-12">
                <label class="erp-label" for="address_line_2">Address Line 2</label>
                <input class="erp-input" id="address_line_2" name="address_line_2" value="{{ old('address_line_2', $student->address_line_2 ?? '') }}" maxlength="2000">
                <p class="erp-error">@error('address_line_2'){{ $message }}@enderror</p>
            </div>

            <div class="erp-field erp-col-4">
                <label class="erp-label" for="city">Village / City</label>
                <input class="erp-input" id="city" name="city" value="{{ old('city', $student->city ?? '') }}" maxlength="100">
                <p class="erp-error">@error('city'){{ $message }}@enderror</p>
            </div>
            <div class="erp-field erp-col-4">
                <label class="erp-label" for="state">State</label>
                <input class="erp-input" id="state" name="state" value="{{ old('state', $student->state ?? '') }}" maxlength="100">
                <p class="erp-error">@error('state'){{ $message }}@enderror</p>
            </div>
            <div class="erp-field erp-col-4">
                <label class="erp-label" for="country">Country</label>
                <input class="erp-input" id="country" name="country" value="{{ old('country', $student->country ?? '') }}" maxlength="100">
                <p class="erp-error">@error('country'){{ $message }}@enderror</p>
            </div>

            <div class="erp-field erp-col-6">
                <label class="erp-label" for="emergency_contact_name">Emergency Contact Name</label>
                <input class="erp-input" id="emergency_contact_name" name="emergency_contact_name" value="{{ old('emergency_contact_name', $student->emergency_contact_name ?? '') }}" maxlength="255">
                <p class="erp-error">@error('emergency_contact_name'){{ $message }}@enderror</p>
            </div>
            <div class="erp-field erp-col-6">
                <label class="erp-label" for="emergency_contact_phone">Emergency Contact Phone</label>
                <input class="erp-input" id="emergency_contact_phone" name="emergency_contact_phone" value="{{ old('emergency_contact_phone', $student->emergency_contact_phone ?? '') }}" maxlength="30">
                <p class="erp-error">@error('emergency_contact_phone'){{ $message }}@enderror</p>
            </div>
        </div>
    </div>
</section>

{{-- ===================================================================== --}}
{{-- 05 Additional information                                              --}}
{{-- ===================================================================== --}}
<section class="erp-card">
    <header class="erp-card__head">
        <span class="erp-card__num">05</span>
        <div>
            <h3 class="erp-card__title">Additional information</h3>
            <p class="erp-card__desc">The remaining optional notes — everything else already appears in the sections above.</p>
        </div>
    </header>

    <div class="erp-card__body">
        <div class="erp-grid">
            <div class="erp-field erp-col-4">
                <label class="erp-label" for="mother_tongue">Mother Tongue</label>
                <input class="erp-input" id="mother_tongue" name="mother_tongue" value="{{ old('mother_tongue', $student->mother_tongue ?? '') }}" maxlength="100">
                <p class="erp-error">@error('mother_tongue'){{ $message }}@enderror</p>
            </div>
            <div class="erp-field erp-col-12">
                <label class="erp-label" for="remarks">Remarks</label>
                <textarea class="erp-input" id="remarks" name="remarks" rows="2" maxlength="2000">{{ old('remarks', $student->remarks ?? '') }}</textarea>
                <p class="erp-error">@error('remarks'){{ $message }}@enderror</p>
            </div>
        </div>
    </div>
</section>

{{-- ===================================================================== --}}
{{-- 06 Documents (link to the module, never a second upload system)        --}}
{{-- ===================================================================== --}}
<section class="erp-card">
    <header class="erp-card__head">
        <span class="erp-card__num">06</span>
        <div>
            <h3 class="erp-card__title">Documents</h3>
            <p class="erp-card__desc">Held by the Document module on the private disk — linked from here, never attached to this form.</p>
        </div>
    </header>

    <div class="erp-card__body">
        <div class="erp-doc-row">
            @if($isEdit)
                <span>Stored documents: <strong>{{ $documentsCount ?? 0 }}</strong></span>
                @can('viewAny', App\Models\StudentDocument::class)
                    <a class="erp-btn erp-btn--sm erp-btn--ghost" href="{{ route('students.show', ['student' => $student, 'tab' => 'documents']) }}">Open Document Register</a>
                @endcan
                @can('create', App\Models\StudentDocument::class)
                    <a class="erp-btn erp-btn--sm" href="{{ route('student-documents.create', ['student_id' => $student->id]) }}">+ Upload a document</a>
                @endcan
            @else
                <span>Uploads are possible once the student exists — create the student first, then upload from its Documents tab or the register.</span>
                @can('viewAny', App\Models\StudentDocument::class)
                    <a class="erp-btn erp-btn--sm erp-btn--ghost" href="{{ route('student-documents.index') }}">Open Document Register</a>
                @endcan
            @endif
        </div>
    </div>
</section>

<div class="erp-actions">
    <button class="erp-btn" type="submit">{{ $submitLabel }}</button>
    <a class="erp-btn erp-btn--ghost" href="{{ $isEdit ? route('students.show', $student) : route('students.index') }}">Cancel</a>
</div>
</div>

@push('scripts')
    <script>
        // Section narrowing (create only): convenience, never correctness — the
        // server re-validates the academic year / program / section combination.
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

        // Visible date format: a native date control paints its segments in the
        // browser's own locale order (month/day/year on an en-US browser) and
        // that cannot be restyled. The field therefore shows a read-only mirror
        // containing DD/MM/YYYY, with the real date input on top of it (see
        // .erp-date in the stylesheet): the mirror is what the operator reads,
        // the input underneath still owns the picker and still submits ISO.
        (function () {
            function pad(value) { return String(value).padStart(2, '0'); }

            function display(iso) {
                const parts = String(iso || '').split('-');
                return parts.length === 3 && parts[0]
                    ? pad(parts[2]) + '/' + pad(parts[1]) + '/' + parts[0]
                    : '';
            }

            const natives = document.querySelectorAll('[data-erp-date-native]');
            Array.prototype.forEach.call(natives, function (native) {
                const mirror = document.querySelector('[data-erp-date-text="' + native.dataset.erpDateNative + '"]');
                if (!mirror) return;

                const sync = function () { mirror.value = display(native.value); };

                // Typing into the segments and picking from the calendar both
                // land here, so the mirror follows either way.
                native.addEventListener('input', sync);
                native.addEventListener('change', sync);

                // Clicking anywhere in the field opens the calendar, exactly as
                // clicking the browser's own (now hidden) calendar button did.
                native.addEventListener('click', function () {
                    if (typeof native.showPicker !== 'function') return;
                    try { native.showPicker(); } catch (error) { /* already open, or no user gesture */ }
                });

                sync();
            });
        })();
    </script>
@endpush
