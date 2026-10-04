@php
    /*
     * The Create/Edit form is ONE partial for both pages: `$student` is present
     * on edit and absent on create, and the seven sections below are identical
     * either way (only the read-only context and the optional first enrollment
     * differ). Every section is fed by the same Student record, so an operator
     * never has to look for a second screen to complete a person.
     *
     * Layout: a compact data-entry grid, not a stacked marketing form. One
     * `.form-grid` per section holds up to 12 columns on desktop and collapses to
     * a single column on mobile; fields declare their width with `.form-field`
     * (half), `--third`, `--wide` or `--full`. Sections are separated by a thin
     * heading rule instead of large cards, labels sit tight above their input and
     * an empty error slot reserves no height — so the same 48 fields fit in far
     * less vertical space. The styling vocabulary lives in resources/css/app.css
     * (`.form-*` and `button--sm`), next to the shared `.input` / `.label` pair.
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
@endphp

@csrf

{{-- ===================================================================== --}}
{{-- 1. Basic information                                                   --}}
{{-- ===================================================================== --}}
<fieldset class="form-section-fieldset mt-5">
    <legend class="form-section">
        <span class="form-section-title">Basic information</span>
        <span class="form-section-hint">Only the first name and a status are required; every other section can be completed later from this same form.</span>
    </legend>

    <div class="form-grid">
        <div class="form-field form-field--third">
            <label class="form-label" for="first_name">First Name <span class="form-required">*</span></label>
            <input class="form-input" id="first_name" name="first_name" value="{{ old('first_name', $student->first_name ?? '') }}" required maxlength="255">
            <p class="form-error">@error('first_name'){{ $message }}@enderror</p>
        </div>
        <div class="form-field form-field--third">
            <label class="form-label" for="middle_name">Middle Name</label>
            <input class="form-input" id="middle_name" name="middle_name" value="{{ old('middle_name', $student->middle_name ?? '') }}" maxlength="255">
            <p class="form-error">@error('middle_name'){{ $message }}@enderror</p>
        </div>
        <div class="form-field form-field--third">
            <label class="form-label" for="last_name">Last Name</label>
            <input class="form-input" id="last_name" name="last_name" value="{{ old('last_name', $student->last_name ?? '') }}" maxlength="255">
            <p class="form-error">@error('last_name'){{ $message }}@enderror</p>
        </div>

        <div class="form-field form-field--third">
            <label class="form-label" for="date_of_birth">Date of Birth</label>
            <input class="form-input" id="date_of_birth" name="date_of_birth" type="date" value="{{ old('date_of_birth', isset($student->date_of_birth) ? $student->date_of_birth->format('Y-m-d') : '') }}">
            <p class="form-error">@error('date_of_birth'){{ $message }}@enderror</p>
        </div>
        <div class="form-field form-field--third">
            <label class="form-label" for="gender">Gender</label>
            <select class="form-input" id="gender" name="gender">
                <option value="">— Select —</option>
                @foreach(\App\Models\Student::GENDERS as $gender)
                    <option value="{{ $gender }}" @selected(old('gender', $student->gender ?? '') === $gender)>{{ ucfirst(str_replace('_', ' ', $gender)) }}</option>
                @endforeach
            </select>
            <p class="form-error">@error('gender'){{ $message }}@enderror</p>
        </div>
        <div class="form-field form-field--third">
            <label class="form-label" for="category">Category</label>
            <select class="form-input" id="category" name="category">
                <option value="">— Select —</option>
                @foreach(\App\Models\Student::CATEGORIES as $category)
                    <option value="{{ $category }}" @selected(old('category', $student->category ?? '') === $category)>{{ \App\Models\Student::categoryLabel($category) }}</option>
                @endforeach
            </select>
            <p class="form-error">@error('category'){{ $message }}@enderror</p>
        </div>

        <div class="form-field form-field--third">
            <label class="form-label" for="status">Status <span class="form-required">*</span></label>
            <select class="form-input" id="status" name="status" required>
                <option value="">— Select —</option>
                @foreach(\App\Models\Student::STATUSES as $s)
                    <option value="{{ $s }}" @selected(old('status', $student->status ?? 'active') === $s)>{{ ucfirst($s) }}</option>
                @endforeach
            </select>
            <p class="form-error">@error('status'){{ $message }}@enderror</p>
        </div>
        <div class="form-field form-field--wide">
            <label class="form-label" for="photo">Photograph</label>
            <div class="flex flex-wrap items-center gap-3">
                @if($isEdit && $student->photo_path)
                    <img class="form-avatar" src="{{ route('students.photo', $student) }}" alt="Current photograph of {{ $student->fullName() }}">
                @else
                    <span class="form-avatar--empty" aria-hidden="true">None</span>
                @endif
                <input class="form-input form-input--file max-w-xs" id="photo" name="photo" type="file" accept="image/jpeg,image/png,image/webp">
                @if($isEdit && $student->photo_path)
                    <label class="form-check"><input type="checkbox" name="remove_photo" value="1" @checked(old('remove_photo'))> Remove the stored photograph</label>
                @endif
                <p class="form-help mt-0">JPG, PNG or WebP, up to 2 MB — stored on the private disk under a server-generated name.</p>
            </div>
            <p class="form-error">@error('photo'){{ $message }}@enderror</p>
        </div>
    </div>
</fieldset>

{{-- ===================================================================== --}}
{{-- 2. Parent / guardian                                                   --}}
{{-- ===================================================================== --}}
<fieldset class="form-section-fieldset mt-5">
    <legend class="form-section">
        <span class="form-section-title">Parent / guardian</span>
        <span class="form-section-hint">The people the institute contacts — the named guardian first, then the father, then the mother.</span>
    </legend>

    <div class="form-grid">
        <div class="form-field">
            <label class="form-label" for="father_name">Father's Name</label>
            <input class="form-input" id="father_name" name="father_name" value="{{ old('father_name', $student->father_name ?? '') }}" maxlength="255">
            <p class="form-error">@error('father_name'){{ $message }}@enderror</p>
        </div>
        <div class="form-field">
            <label class="form-label" for="mother_name">Mother's Name</label>
            <input class="form-input" id="mother_name" name="mother_name" value="{{ old('mother_name', $student->mother_name ?? '') }}" maxlength="255">
            <p class="form-error">@error('mother_name'){{ $message }}@enderror</p>
        </div>

        <div class="form-field form-field--third">
            <label class="form-label" for="guardian_name">Guardian's Name</label>
            <input class="form-input" id="guardian_name" name="guardian_name" value="{{ old('guardian_name', $student->guardian_name ?? '') }}" maxlength="255">
            <p class="form-error">@error('guardian_name'){{ $message }}@enderror</p>
        </div>
        <div class="form-field form-field--third">
            <label class="form-label" for="guardian_relation">Guardian's Relationship</label>
            <select class="form-input" id="guardian_relation" name="guardian_relation">
                <option value="">— Select —</option>
                @foreach(\App\Models\Student::GUARDIAN_RELATIONS as $relation)
                    <option value="{{ $relation }}" @selected(old('guardian_relation', $student->guardian_relation ?? '') === $relation)>{{ \App\Models\Student::guardianRelationLabel($relation) }}</option>
                @endforeach
            </select>
            <p class="form-error">@error('guardian_relation'){{ $message }}@enderror</p>
        </div>
        <div class="form-field form-field--third">
            <label class="form-label" for="guardian_phone">Guardian's Phone</label>
            <input class="form-input" id="guardian_phone" name="guardian_phone" value="{{ old('guardian_phone', $student->guardian_phone ?? '') }}" maxlength="30">
            <p class="form-error">@error('guardian_phone'){{ $message }}@enderror</p>
        </div>

        <div class="form-field">
            <label class="form-label" for="guardian_email">Guardian's Email</label>
            <input class="form-input" id="guardian_email" name="guardian_email" type="email" value="{{ old('guardian_email', $student->guardian_email ?? '') }}" maxlength="255">
            <p class="form-error">@error('guardian_email'){{ $message }}@enderror</p>
        </div>
        <div class="form-field">
            <label class="form-label" for="guardian_occupation">Guardian's Occupation</label>
            <input class="form-input" id="guardian_occupation" name="guardian_occupation" value="{{ old('guardian_occupation', $student->guardian_occupation ?? '') }}" maxlength="150">
            <p class="form-error">@error('guardian_occupation'){{ $message }}@enderror</p>
        </div>

        <div class="form-field form-field--full">
            <label class="form-label" for="guardian_address">Guardian's Address</label>
            <input class="form-input" id="guardian_address" name="guardian_address" value="{{ old('guardian_address', $student->guardian_address ?? '') }}" maxlength="2000" placeholder="Only when it differs from the student's address">
            <p class="form-error">@error('guardian_address'){{ $message }}@enderror</p>
        </div>
    </div>
</fieldset>

{{-- ===================================================================== --}}
{{-- 3. Identity & government IDs                                           --}}
{{-- ===================================================================== --}}
<fieldset class="form-section-fieldset mt-5">
    <legend class="form-section">
        <span class="form-section-title">Identity &amp; government IDs</span>
        <span class="form-section-hint">Stored encrypted; only the last four characters are ever displayed, exported or audited.</span>
    </legend>

    <div class="form-grid">
        <div class="form-field">
            <label class="form-label" for="aadhaar_number">Aadhaar number{{ $isEdit && $student->hasAadhaar() ? ' (replace)' : '' }}</label>
            {{-- value is ALWAYS empty: the stored number is never echoed back into the page. --}}
            <input class="form-input" id="aadhaar_number" name="aadhaar_number" value="" inputmode="numeric" autocomplete="off" maxlength="20" placeholder="{{ $isEdit && $student->hasAadhaar() ? 'Leave blank to keep the stored number' : '12 digits, e.g. 9999 4105 7058' }}">
            @if($isEdit && $student->hasAadhaar())
                <div class="form-meta">
                    <span>Recorded: <strong>{{ $student->maskedAadhaar() }}</strong></span>
                    <label class="form-check"><input type="checkbox" name="remove_aadhaar" value="1" @checked(old('remove_aadhaar'))> Remove the stored Aadhaar</label>
                </div>
            @endif
            <p class="form-error">@error('aadhaar_number'){{ $message }}@enderror</p>
            <p class="form-help">
                Spaces and hyphens are accepted and the checksum is verified before saving. It is stored encrypted, only its last four
                digits stay readable, it can never be entered twice for two live students of this college, and for security it is not
                re-filled after a validation error.
            </p>
        </div>
        <div class="form-field">
            <label class="form-label" for="apaar_id">APAAR / ABC ID</label>
            <input class="form-input" id="apaar_id" name="apaar_id" value="{{ old('apaar_id', $student->apaar_id ?? '') }}" inputmode="numeric" maxlength="30" placeholder="12 digits">
            <p class="form-error">@error('apaar_id'){{ $message }}@enderror</p>
            <p class="form-help">The academic credit registry ID — not a secret, so it is shown whole.</p>
        </div>

        <div class="form-field form-field--third">
            <label class="form-label" for="govt_id_type">Other Government ID — Type</label>
            <select class="form-input" id="govt_id_type" name="govt_id_type">
                <option value="">— None —</option>
                @foreach(\App\Models\Student::GOVT_ID_TYPES as $type)
                    <option value="{{ $type }}" @selected(old('govt_id_type', $student->govt_id_type ?? '') === $type)>{{ \App\Models\Student::govtIdTypeLabel($type) }}</option>
                @endforeach
            </select>
            <p class="form-error">@error('govt_id_type'){{ $message }}@enderror</p>
            @if($isEdit && $student->maskedGovtIdNumber())
                <p class="form-help">Choosing “— None —” clears the stored type and number; leave the number blank to keep it while correcting the type.</p>
            @endif
        </div>
        <div class="form-field form-field--wide">
            <label class="form-label" for="govt_id_number">Other Government ID — Number</label>
            {{-- value is ALWAYS empty (same rule as Aadhaar). --}}
            <input class="form-input" id="govt_id_number" name="govt_id_number" value="" autocomplete="off" maxlength="100" placeholder="{{ $isEdit && $student->maskedGovtIdNumber() ? 'Leave blank to keep the stored number' : 'Number as printed on the document' }}">
            @if($isEdit && $student->maskedGovtIdNumber())
                <div class="form-meta">
                    <span>{{ \App\Models\Student::govtIdTypeLabel($student->govt_id_type) }} recorded: <strong>{{ $student->maskedGovtIdNumber() }}</strong></span>
                    <label class="form-check"><input type="checkbox" name="remove_govt_id" value="1" @checked(old('remove_govt_id'))> Remove the stored government ID</label>
                </div>
            @endif
            <p class="form-error">@error('govt_id_number'){{ $message }}@enderror</p>
        </div>
    </div>
</fieldset>

{{-- ===================================================================== --}}
{{-- 4. Contact                                                             --}}
{{-- ===================================================================== --}}
<fieldset class="form-section-fieldset mt-5">
    <legend class="form-section">
        <span class="form-section-title">Contact</span>
        <span class="form-section-hint">Address and the numbers the list, the exports and the reports use.</span>
    </legend>

    <div class="form-grid">
        <div class="form-field form-field--third">
            <label class="form-label" for="email">Email</label>
            <input class="form-input" id="email" name="email" type="email" value="{{ old('email', $student->email ?? '') }}" maxlength="255">
            <p class="form-error">@error('email'){{ $message }}@enderror</p>
        </div>
        <div class="form-field form-field--third">
            <label class="form-label" for="phone">Phone</label>
            <input class="form-input" id="phone" name="phone" value="{{ old('phone', $student->phone ?? '') }}" maxlength="30">
            <p class="form-error">@error('phone'){{ $message }}@enderror</p>
        </div>
        <div class="form-field form-field--third">
            <label class="form-label" for="alternate_phone">Alternate Phone</label>
            <input class="form-input" id="alternate_phone" name="alternate_phone" value="{{ old('alternate_phone', $student->alternate_phone ?? '') }}" maxlength="30">
            <p class="form-error">@error('alternate_phone'){{ $message }}@enderror</p>
        </div>

        <div class="form-field">
            <label class="form-label" for="emergency_contact_name">Emergency Contact Name</label>
            <input class="form-input" id="emergency_contact_name" name="emergency_contact_name" value="{{ old('emergency_contact_name', $student->emergency_contact_name ?? '') }}" maxlength="255">
            <p class="form-error">@error('emergency_contact_name'){{ $message }}@enderror</p>
        </div>
        <div class="form-field">
            <label class="form-label" for="emergency_contact_phone">Emergency Contact Phone</label>
            <input class="form-input" id="emergency_contact_phone" name="emergency_contact_phone" value="{{ old('emergency_contact_phone', $student->emergency_contact_phone ?? '') }}" maxlength="30">
            <p class="form-error">@error('emergency_contact_phone'){{ $message }}@enderror</p>
        </div>

        {{-- Address: a long line is never squeezed — line 1 still shares its row
             with the short postal code, line 2 takes the full width, and the
             remaining short tail (city / state / country) fills one row. --}}
        <div class="form-field form-field--wide">
            <label class="form-label" for="address_line_1">Address Line 1</label>
            <input class="form-input" id="address_line_1" name="address_line_1" value="{{ old('address_line_1', $student->address_line_1 ?? '') }}" maxlength="2000">
            <p class="form-error">@error('address_line_1'){{ $message }}@enderror</p>
        </div>
        <div class="form-field form-field--third">
            <label class="form-label" for="postal_code">Postal Code</label>
            <input class="form-input" id="postal_code" name="postal_code" value="{{ old('postal_code', $student->postal_code ?? '') }}" maxlength="20">
            <p class="form-error">@error('postal_code'){{ $message }}@enderror</p>
        </div>

        <div class="form-field form-field--full">
            <label class="form-label" for="address_line_2">Address Line 2</label>
            <input class="form-input" id="address_line_2" name="address_line_2" value="{{ old('address_line_2', $student->address_line_2 ?? '') }}" maxlength="2000">
            <p class="form-error">@error('address_line_2'){{ $message }}@enderror</p>
        </div>

        <div class="form-field form-field--third">
            <label class="form-label" for="city">City</label>
            <input class="form-input" id="city" name="city" value="{{ old('city', $student->city ?? '') }}" maxlength="100">
            <p class="form-error">@error('city'){{ $message }}@enderror</p>
        </div>
        <div class="form-field form-field--third">
            <label class="form-label" for="state">State</label>
            <input class="form-input" id="state" name="state" value="{{ old('state', $student->state ?? '') }}" maxlength="100">
            <p class="form-error">@error('state'){{ $message }}@enderror</p>
        </div>
        <div class="form-field form-field--third">
            <label class="form-label" for="country">Country</label>
            <input class="form-input" id="country" name="country" value="{{ old('country', $student->country ?? '') }}" maxlength="100">
            <p class="form-error">@error('country'){{ $message }}@enderror</p>
        </div>
    </div>
</fieldset>

{{-- ===================================================================== --}}
{{-- 5. Academic / admission                                                --}}
{{-- ===================================================================== --}}
<fieldset class="form-section-fieldset mt-5">
    <legend class="form-section">
        <span class="form-section-title">Academic / admission</span>
        <span class="form-section-hint">Admission context and the previous-education snapshot — year-by-year enrollment stays in the Enrollment module.</span>
    </legend>

    @if($isEdit)
        {{-- Read-only context: one compact strip instead of a boxed block. --}}
        <div class="form-strip mt-3">
            <span>Student number: <strong>{{ $student->student_number }}</strong> <span class="text-slate-400">(server-generated)</span></span>
            <span>
                Admission source:
                @if($student->admissionApplication)
                    <strong>{{ $student->admissionApplication->application_number }}</strong>
                @else
                    direct registration
                @endif
            </span>
            <span>
                Current enrollment:
                @if($currentEnrollment)
                    <strong>{{ $currentEnrollment->enrollment_number }}</strong>
                    · {{ $currentEnrollment->academicYear?->name ?? '—' }}
                    @if($currentEnrollment->program) · {{ $currentEnrollment->program->name }} @endif
                    @if($currentEnrollment->section) · {{ $currentEnrollment->section->name }} @endif
                @else
                    none
                @endif
            </span>
            @can('viewAny', App\Models\StudentEnrollment::class)
                <a class="font-semibold text-indigo-600 hover:underline" href="{{ route('students.show', ['student' => $student, 'tab' => 'enrollments']) }}">Manage enrollments</a>
            @endcan
        </div>
    @endif

    <div class="form-grid">
        <div class="form-field form-field--third">
            <label class="form-label" for="admission_date">Admission Date</label>
            <input class="form-input" id="admission_date" name="admission_date" type="date" value="{{ old('admission_date', isset($student->admission_date) ? $student->admission_date->format('Y-m-d') : now()->format('Y-m-d')) }}">
            <p class="form-error">@error('admission_date'){{ $message }}@enderror</p>
        </div>

        @if(! $isEdit && $canEnroll && ($academicYears ?? collect())->isNotEmpty())
            <div class="form-field form-field--full">
                <p class="form-subsection">First enrollment (optional)</p>
                <p class="form-help">
                    Opens the student's first enrollment in the same save. The enrollment number is server-generated and the section
                    must belong to the chosen academic year and program; leaving this empty creates the student with no enrollment.
                </p>
            </div>
            <div class="form-field form-field--third">
                <label class="form-label" for="academic_year_id">Academic Year</label>
                <select class="form-input" id="academic_year_id" name="academic_year_id">
                    <option value="">— No enrollment —</option>
                    @foreach($academicYears as $ay)
                        <option value="{{ $ay->id }}" @selected((int) old('academic_year_id') === $ay->id)>{{ $ay->name }} ({{ $ay->code }})</option>
                    @endforeach
                </select>
                <p class="form-error">@error('academic_year_id'){{ $message }}@enderror</p>
            </div>
            <div class="form-field form-field--third">
                <label class="form-label" for="program_id">Program</label>
                <select class="form-input" id="program_id" name="program_id">
                    <option value="">— Select program —</option>
                    @foreach($programs as $program)
                        <option value="{{ $program->id }}" @selected((int) old('program_id') === $program->id)>{{ $program->name }} ({{ $program->code }})</option>
                    @endforeach
                </select>
                <p class="form-error">@error('program_id'){{ $message }}@enderror</p>
            </div>
            <div class="form-field form-field--third">
                <label class="form-label" for="enrollment_date">Enrollment Date</label>
                <input class="form-input" id="enrollment_date" name="enrollment_date" type="date" value="{{ old('enrollment_date', now()->format('Y-m-d')) }}">
                <p class="form-error">@error('enrollment_date'){{ $message }}@enderror</p>
            </div>
            <div class="form-field form-field--full">
                <label class="form-label" for="section_id">Section / Batch</label>
                <select class="form-input" id="section_id" name="section_id">
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
                <p class="form-error">@error('section_id'){{ $message }}@enderror</p>
            </div>
        @endif

        <div class="form-field form-field--full">
            <p class="form-subsection">Previous education</p>
            <p class="form-help">A snapshot of the school/qualification the student came from — it stays on this record even if the admission application is later corrected.</p>
        </div>
        <div class="form-field">
            <label class="form-label" for="previous_school_name">School / Institution</label>
            <input class="form-input" id="previous_school_name" name="previous_school_name" value="{{ old('previous_school_name', $student->previous_school_name ?? '') }}" maxlength="255">
            <p class="form-error">@error('previous_school_name'){{ $message }}@enderror</p>
        </div>
        <div class="form-field">
            <label class="form-label" for="previous_school_board">Board / University</label>
            <input class="form-input" id="previous_school_board" name="previous_school_board" value="{{ old('previous_school_board', $student->previous_school_board ?? '') }}" maxlength="100">
            <p class="form-error">@error('previous_school_board'){{ $message }}@enderror</p>
        </div>
        <div class="form-field form-field--third">
            <label class="form-label" for="previous_qualification">Qualification Passed</label>
            <input class="form-input" id="previous_qualification" name="previous_qualification" value="{{ old('previous_qualification', $student->previous_qualification ?? '') }}" maxlength="100" placeholder="e.g. Class XII, Diploma">
            <p class="form-error">@error('previous_qualification'){{ $message }}@enderror</p>
        </div>
        <div class="form-field form-field--third">
            <label class="form-label" for="previous_exam_year">Year of Passing</label>
            <input class="form-input" id="previous_exam_year" name="previous_exam_year" type="number" min="1900" max="2100" step="1" value="{{ old('previous_exam_year', $student->previous_exam_year ?? '') }}">
            <p class="form-error">@error('previous_exam_year'){{ $message }}@enderror</p>
        </div>
        <div class="form-field form-field--third">
            <label class="form-label" for="previous_percentage">Percentage / Marks</label>
            <input class="form-input" id="previous_percentage" name="previous_percentage" type="number" min="0" max="100" step="0.01" value="{{ old('previous_percentage', $student->previous_percentage ?? '') }}">
            <p class="form-error">@error('previous_percentage'){{ $message }}@enderror</p>
        </div>
    </div>
</fieldset>

{{-- ===================================================================== --}}
{{-- 6. Additional information                                              --}}
{{-- ===================================================================== --}}
<fieldset class="form-section-fieldset mt-5">
    <legend class="form-section">
        <span class="form-section-title">Additional information</span>
        <span class="form-section-hint">Optional health and background notes held on the student record.</span>
    </legend>

    <div class="form-grid">
        <div class="form-field form-field--third">
            <label class="form-label" for="blood_group">Blood Group</label>
            <select class="form-input" id="blood_group" name="blood_group">
                <option value="">— Select —</option>
                @foreach(\App\Models\Student::BLOOD_GROUPS as $group)
                    <option value="{{ $group }}" @selected(old('blood_group', $student->blood_group ?? '') === $group)>{{ $group === 'unknown' ? 'Not known' : $group }}</option>
                @endforeach
            </select>
            <p class="form-error">@error('blood_group'){{ $message }}@enderror</p>
        </div>
        <div class="form-field form-field--third">
            <label class="form-label" for="nationality">Nationality</label>
            <input class="form-input" id="nationality" name="nationality" value="{{ old('nationality', $student->nationality ?? '') }}" maxlength="100">
            <p class="form-error">@error('nationality'){{ $message }}@enderror</p>
        </div>
        <div class="form-field form-field--third">
            <label class="form-label" for="mother_tongue">Mother Tongue</label>
            <input class="form-input" id="mother_tongue" name="mother_tongue" value="{{ old('mother_tongue', $student->mother_tongue ?? '') }}" maxlength="100">
            <p class="form-error">@error('mother_tongue'){{ $message }}@enderror</p>
        </div>
        <div class="form-field form-field--full">
            <label class="form-label" for="remarks">Remarks</label>
            <textarea class="form-input" id="remarks" name="remarks" rows="2" maxlength="2000">{{ old('remarks', $student->remarks ?? '') }}</textarea>
            <p class="form-error">@error('remarks'){{ $message }}@enderror</p>
        </div>
    </div>
</fieldset>

{{-- ===================================================================== --}}
{{-- 7. Documents (link, never an inline upload)                            --}}
{{-- ===================================================================== --}}
<fieldset class="form-section-fieldset mt-5">
    <legend class="form-section">
        <span class="form-section-title">Documents</span>
        <span class="form-section-hint">Held by the Document module on the private disk — linked from here, never attached to this form.</span>
    </legend>

    <div class="mt-3 flex flex-wrap items-center gap-3">
        @if($isEdit)
            <p class="text-xs text-slate-600">
                Stored documents: <span class="font-semibold">{{ $documentsCount ?? 0 }}</span>
            </p>
            @can('viewAny', App\Models\StudentDocument::class)
                <a class="button button--sm button--secondary" href="{{ route('students.show', ['student' => $student, 'tab' => 'documents']) }}">Open this student's documents</a>
            @endcan
            @can('create', App\Models\StudentDocument::class)
                <a class="button button--sm" href="{{ route('student-documents.create', ['student_id' => $student->id]) }}">+ Upload a document</a>
            @endcan
        @else
            <p class="text-xs text-slate-600">
                Uploads are possible once the student exists — create the student first, then upload from its Documents tab or the
                document register.
            </p>
            @can('viewAny', App\Models\StudentDocument::class)
                <a class="button button--sm button--secondary" href="{{ route('student-documents.index') }}">Open the document register</a>
            @endcan
        @endif
    </div>
</fieldset>

<div class="mt-5 flex flex-wrap items-center gap-2 border-t border-slate-200 pt-4">
    <button class="button" type="submit">{{ $submitLabel }}</button>
    <a class="button button--secondary" href="{{ $isEdit ? route('students.show', $student) : route('students.index') }}">Cancel</a>
</div>

@if(! $isEdit && $canEnroll && ($academicYears ?? collect())->isNotEmpty())
    @push('scripts')
        <script>
            // Convenience only: narrows the section list to the chosen academic
            // year and program. The server re-validates the combination in
            // StudentService, so this script can never be relied on for
            // correctness — it only stops an obviously wrong pick.
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
@endif
