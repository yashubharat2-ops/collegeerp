@php
    /*
     * The Create/Edit form is ONE partial for both pages: `$student` is present
     * on edit and absent on create, and the seven sections below are identical
     * either way (only the read-only context and the optional first enrollment
     * differ). Every section is fed by the same Student record, so an operator
     * never has to look for a second screen to complete a person.
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
<fieldset class="mt-6">
    <legend class="panel-title">Basic information</legend>
    <p class="panel-subtitle">The person's own identity as the institute records it. Only the first name and a status are required.</p>

    <div class="mt-4 grid gap-5 md:grid-cols-2">
        <div>
            <label class="text-sm font-semibold" for="first_name">First Name *</label>
            <input class="input mt-1" id="first_name" name="first_name" value="{{ old('first_name', $student->first_name ?? '') }}" required maxlength="255">
            <p class="mt-1 text-xs text-rose-600">@error('first_name'){{ $message }}@enderror</p>
        </div>
        <div>
            <label class="text-sm font-semibold" for="middle_name">Middle Name</label>
            <input class="input mt-1" id="middle_name" name="middle_name" value="{{ old('middle_name', $student->middle_name ?? '') }}" maxlength="255">
            <p class="mt-1 text-xs text-rose-600">@error('middle_name'){{ $message }}@enderror</p>
        </div>
        <div>
            <label class="text-sm font-semibold" for="last_name">Last Name</label>
            <input class="input mt-1" id="last_name" name="last_name" value="{{ old('last_name', $student->last_name ?? '') }}" maxlength="255">
            <p class="mt-1 text-xs text-rose-600">@error('last_name'){{ $message }}@enderror</p>
        </div>
        <div>
            <label class="text-sm font-semibold" for="gender">Gender</label>
            <select class="input mt-1" id="gender" name="gender">
                <option value="">— Select —</option>
                @foreach(\App\Models\Student::GENDERS as $gender)
                    <option value="{{ $gender }}" @selected(old('gender', $student->gender ?? '') === $gender)>{{ ucfirst(str_replace('_', ' ', $gender)) }}</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-rose-600">@error('gender'){{ $message }}@enderror</p>
        </div>
        <div>
            <label class="text-sm font-semibold" for="date_of_birth">Date of Birth</label>
            <input class="input mt-1" id="date_of_birth" name="date_of_birth" type="date" value="{{ old('date_of_birth', isset($student->date_of_birth) ? $student->date_of_birth->format('Y-m-d') : '') }}">
            <p class="mt-1 text-xs text-rose-600">@error('date_of_birth'){{ $message }}@enderror</p>
        </div>
        <div>
            <label class="text-sm font-semibold" for="category">Category</label>
            <select class="input mt-1" id="category" name="category">
                <option value="">— Select —</option>
                @foreach(\App\Models\Student::CATEGORIES as $category)
                    <option value="{{ $category }}" @selected(old('category', $student->category ?? '') === $category)>{{ \App\Models\Student::categoryLabel($category) }}</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-rose-600">@error('category'){{ $message }}@enderror</p>
        </div>
        <div>
            <label class="text-sm font-semibold" for="status">Status *</label>
            <select class="input mt-1" id="status" name="status" required>
                <option value="">— Select —</option>
                @foreach(\App\Models\Student::STATUSES as $s)
                    <option value="{{ $s }}" @selected(old('status', $student->status ?? 'active') === $s)>{{ ucfirst($s) }}</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-rose-600">@error('status'){{ $message }}@enderror</p>
        </div>
        <div class="md:col-span-2">
            <label class="text-sm font-semibold" for="photo">Photograph</label>
            @if($isEdit && $student->photo_path)
                <div class="mt-2 flex flex-wrap items-center gap-4">
                    <img class="h-16 w-16 rounded-xl object-cover" src="{{ route('students.photo', $student) }}" alt="Current photograph of {{ $student->fullName() }}">
                    <label class="flex items-center gap-2 text-xs text-slate-600">
                        <input type="checkbox" name="remove_photo" value="1" @checked(old('remove_photo'))>
                        Remove the stored photograph
                    </label>
                </div>
            @endif
            <input class="input mt-2" id="photo" name="photo" type="file" accept="image/jpeg,image/png,image/webp">
            <p class="mt-1 text-xs text-slate-500">JPG, PNG or WebP, up to 2 MB. The file is stored on the private disk under a server-generated name and served only through the authorized photo route.</p>
            <p class="mt-1 text-xs text-rose-600">@error('photo'){{ $message }}@enderror</p>
        </div>
    </div>
</fieldset>

{{-- ===================================================================== --}}
{{-- 2. Parent / guardian                                                   --}}
{{-- ===================================================================== --}}
<fieldset class="mt-8">
    <legend class="panel-title">Parent / guardian</legend>
    <p class="panel-subtitle">The people the institute contacts about this student. The named guardian is used first, then the father, then the mother.</p>

    <div class="mt-4 grid gap-5 md:grid-cols-2">
        <div>
            <label class="text-sm font-semibold" for="father_name">Father's Name</label>
            <input class="input mt-1" id="father_name" name="father_name" value="{{ old('father_name', $student->father_name ?? '') }}" maxlength="255">
            <p class="mt-1 text-xs text-rose-600">@error('father_name'){{ $message }}@enderror</p>
        </div>
        <div>
            <label class="text-sm font-semibold" for="mother_name">Mother's Name</label>
            <input class="input mt-1" id="mother_name" name="mother_name" value="{{ old('mother_name', $student->mother_name ?? '') }}" maxlength="255">
            <p class="mt-1 text-xs text-rose-600">@error('mother_name'){{ $message }}@enderror</p>
        </div>
        <div>
            <label class="text-sm font-semibold" for="guardian_name">Guardian's Name</label>
            <input class="input mt-1" id="guardian_name" name="guardian_name" value="{{ old('guardian_name', $student->guardian_name ?? '') }}" maxlength="255">
            <p class="mt-1 text-xs text-rose-600">@error('guardian_name'){{ $message }}@enderror</p>
        </div>
        <div>
            <label class="text-sm font-semibold" for="guardian_relation">Guardian's Relationship</label>
            <select class="input mt-1" id="guardian_relation" name="guardian_relation">
                <option value="">— Select —</option>
                @foreach(\App\Models\Student::GUARDIAN_RELATIONS as $relation)
                    <option value="{{ $relation }}" @selected(old('guardian_relation', $student->guardian_relation ?? '') === $relation)>{{ \App\Models\Student::guardianRelationLabel($relation) }}</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-rose-600">@error('guardian_relation'){{ $message }}@enderror</p>
        </div>
        <div>
            <label class="text-sm font-semibold" for="guardian_phone">Guardian's Phone</label>
            <input class="input mt-1" id="guardian_phone" name="guardian_phone" value="{{ old('guardian_phone', $student->guardian_phone ?? '') }}" maxlength="30">
            <p class="mt-1 text-xs text-rose-600">@error('guardian_phone'){{ $message }}@enderror</p>
        </div>
        <div>
            <label class="text-sm font-semibold" for="guardian_email">Guardian's Email</label>
            <input class="input mt-1" id="guardian_email" name="guardian_email" type="email" value="{{ old('guardian_email', $student->guardian_email ?? '') }}" maxlength="255">
            <p class="mt-1 text-xs text-rose-600">@error('guardian_email'){{ $message }}@enderror</p>
        </div>
        <div>
            <label class="text-sm font-semibold" for="guardian_occupation">Guardian's Occupation</label>
            <input class="input mt-1" id="guardian_occupation" name="guardian_occupation" value="{{ old('guardian_occupation', $student->guardian_occupation ?? '') }}" maxlength="150">
            <p class="mt-1 text-xs text-rose-600">@error('guardian_occupation'){{ $message }}@enderror</p>
        </div>
        <div class="md:col-span-2">
            <label class="text-sm font-semibold" for="guardian_address">Guardian's Address</label>
            <input class="input mt-1" id="guardian_address" name="guardian_address" value="{{ old('guardian_address', $student->guardian_address ?? '') }}" maxlength="2000" placeholder="Only when it differs from the student's address">
            <p class="mt-1 text-xs text-rose-600">@error('guardian_address'){{ $message }}@enderror</p>
        </div>
    </div>
</fieldset>

{{-- ===================================================================== --}}
{{-- 3. Identity & government IDs                                           --}}
{{-- ===================================================================== --}}
<fieldset class="mt-8">
    <legend class="panel-title">Identity &amp; government IDs</legend>
    <p class="panel-subtitle">Sensitive identity numbers. Stored encrypted; only the last four characters are ever displayed, exported or audited.</p>

    <div class="mt-4 grid gap-5 md:grid-cols-2">
        <div class="md:col-span-2 rounded-xl border border-slate-200 bg-slate-50 p-4">
            <p class="text-sm font-semibold text-slate-700">Aadhaar number</p>

            @if($isEdit && $student->hasAadhaar())
                <p class="mt-2 text-sm text-slate-700">
                    Recorded: <span class="font-semibold tracking-widest">{{ $student->maskedAadhaar() }}</span>
                </p>
                <label class="mt-2 flex items-center gap-2 text-xs text-slate-600">
                    <input type="checkbox" name="remove_aadhaar" value="1" @checked(old('remove_aadhaar'))>
                    Remove the stored Aadhaar number
                </label>
            @endif

            <label class="mt-3 block text-sm font-semibold" for="aadhaar_number">Aadhaar number{{ $isEdit && $student->hasAadhaar() ? ' (replace)' : '' }}</label>
            {{-- value is ALWAYS empty: the stored number is never echoed back into the page. --}}
            <input class="input mt-1" id="aadhaar_number" name="aadhaar_number" value="" inputmode="numeric" autocomplete="off" maxlength="20" placeholder="{{ $isEdit && $student->hasAadhaar() ? 'Leave blank to keep the stored number' : '12 digits, e.g. 9999 4105 7058' }}">
            <p class="mt-1 text-xs text-rose-600">@error('aadhaar_number'){{ $message }}@enderror</p>
            <p class="mt-1 text-xs text-slate-500">
                Spaces and hyphens are accepted; the number is checksum-verified before it is saved. It is stored encrypted,
                only its last four digits are kept readable for the masked display, and it can never be entered twice for two
                live students of this college. For security it is not re-filled after a validation error — retype it only if
                it actually needs to change.
            </p>
        </div>

        <div>
            <label class="text-sm font-semibold" for="apaar_id">APAAR / ABC ID</label>
            <input class="input mt-1" id="apaar_id" name="apaar_id" value="{{ old('apaar_id', $student->apaar_id ?? '') }}" inputmode="numeric" maxlength="30" placeholder="12 digits">
            <p class="mt-1 text-xs text-rose-600">@error('apaar_id'){{ $message }}@enderror</p>
            <p class="mt-1 text-xs text-slate-500">The academic credit registry ID — not a secret, so it is shown whole.</p>
        </div>

        <div>
            <label class="text-sm font-semibold" for="govt_id_type">Other Government ID — Type</label>
            <select class="input mt-1" id="govt_id_type" name="govt_id_type">
                <option value="">— None —</option>
                @foreach(\App\Models\Student::GOVT_ID_TYPES as $type)
                    <option value="{{ $type }}" @selected(old('govt_id_type', $student->govt_id_type ?? '') === $type)>{{ \App\Models\Student::govtIdTypeLabel($type) }}</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-rose-600">@error('govt_id_type'){{ $message }}@enderror</p>
            @if($isEdit && $student->maskedGovtIdNumber())
                <p class="mt-1 text-xs text-slate-500">Choosing “— None —” clears the stored type and number. Leave the number blank to keep it while correcting the type.</p>
            @endif
        </div>

        <div class="md:col-span-2">
            @if($isEdit && $student->maskedGovtIdNumber())
                <p class="text-sm text-slate-700">
                    {{ \App\Models\Student::govtIdTypeLabel($student->govt_id_type) }} recorded: <span class="font-semibold tracking-widest">{{ $student->maskedGovtIdNumber() }}</span>
                </p>
                <label class="mt-2 flex items-center gap-2 text-xs text-slate-600">
                    <input type="checkbox" name="remove_govt_id" value="1" @checked(old('remove_govt_id'))>
                    Remove the stored government ID
                </label>
            @endif

            <label class="mt-3 block text-sm font-semibold" for="govt_id_number">Other Government ID — Number</label>
            {{-- value is ALWAYS empty (same rule as Aadhaar). --}}
            <input class="input mt-1" id="govt_id_number" name="govt_id_number" value="" autocomplete="off" maxlength="100" placeholder="{{ $isEdit && $student->maskedGovtIdNumber() ? 'Leave blank to keep the stored number' : 'Number as printed on the document' }}">
            <p class="mt-1 text-xs text-rose-600">@error('govt_id_number'){{ $message }}@enderror</p>
        </div>
    </div>
</fieldset>

{{-- ===================================================================== --}}
{{-- 4. Contact                                                             --}}
{{-- ===================================================================== --}}
<fieldset class="mt-8">
    <legend class="panel-title">Contact</legend>
    <p class="panel-subtitle">Where the student lives and who to reach in an emergency. These are the numbers the list, the exports and the reports use.</p>

    <div class="mt-4 grid gap-5 md:grid-cols-2">
        <div>
            <label class="text-sm font-semibold" for="email">Email</label>
            <input class="input mt-1" id="email" name="email" type="email" value="{{ old('email', $student->email ?? '') }}" maxlength="255">
            <p class="mt-1 text-xs text-rose-600">@error('email'){{ $message }}@enderror</p>
        </div>
        <div>
            <label class="text-sm font-semibold" for="phone">Phone</label>
            <input class="input mt-1" id="phone" name="phone" value="{{ old('phone', $student->phone ?? '') }}" maxlength="30">
            <p class="mt-1 text-xs text-rose-600">@error('phone'){{ $message }}@enderror</p>
        </div>
        <div>
            <label class="text-sm font-semibold" for="alternate_phone">Alternate Phone</label>
            <input class="input mt-1" id="alternate_phone" name="alternate_phone" value="{{ old('alternate_phone', $student->alternate_phone ?? '') }}" maxlength="30">
            <p class="mt-1 text-xs text-rose-600">@error('alternate_phone'){{ $message }}@enderror</p>
        </div>
        <div>
            <label class="text-sm font-semibold" for="emergency_contact_name">Emergency Contact Name</label>
            <input class="input mt-1" id="emergency_contact_name" name="emergency_contact_name" value="{{ old('emergency_contact_name', $student->emergency_contact_name ?? '') }}" maxlength="255">
            <p class="mt-1 text-xs text-rose-600">@error('emergency_contact_name'){{ $message }}@enderror</p>
        </div>
        <div>
            <label class="text-sm font-semibold" for="emergency_contact_phone">Emergency Contact Phone</label>
            <input class="input mt-1" id="emergency_contact_phone" name="emergency_contact_phone" value="{{ old('emergency_contact_phone', $student->emergency_contact_phone ?? '') }}" maxlength="30">
            <p class="mt-1 text-xs text-rose-600">@error('emergency_contact_phone'){{ $message }}@enderror</p>
        </div>
        <div class="md:col-span-2">
            <label class="text-sm font-semibold" for="address_line_1">Address Line 1</label>
            <input class="input mt-1" id="address_line_1" name="address_line_1" value="{{ old('address_line_1', $student->address_line_1 ?? '') }}" maxlength="2000">
            <p class="mt-1 text-xs text-rose-600">@error('address_line_1'){{ $message }}@enderror</p>
        </div>
        <div class="md:col-span-2">
            <label class="text-sm font-semibold" for="address_line_2">Address Line 2</label>
            <input class="input mt-1" id="address_line_2" name="address_line_2" value="{{ old('address_line_2', $student->address_line_2 ?? '') }}" maxlength="2000">
            <p class="mt-1 text-xs text-rose-600">@error('address_line_2'){{ $message }}@enderror</p>
        </div>
        <div>
            <label class="text-sm font-semibold" for="city">City</label>
            <input class="input mt-1" id="city" name="city" value="{{ old('city', $student->city ?? '') }}" maxlength="100">
            <p class="mt-1 text-xs text-rose-600">@error('city'){{ $message }}@enderror</p>
        </div>
        <div>
            <label class="text-sm font-semibold" for="state">State</label>
            <input class="input mt-1" id="state" name="state" value="{{ old('state', $student->state ?? '') }}" maxlength="100">
            <p class="mt-1 text-xs text-rose-600">@error('state'){{ $message }}@enderror</p>
        </div>
        <div>
            <label class="text-sm font-semibold" for="postal_code">Postal Code</label>
            <input class="input mt-1" id="postal_code" name="postal_code" value="{{ old('postal_code', $student->postal_code ?? '') }}" maxlength="20">
            <p class="mt-1 text-xs text-rose-600">@error('postal_code'){{ $message }}@enderror</p>
        </div>
        <div>
            <label class="text-sm font-semibold" for="country">Country</label>
            <input class="input mt-1" id="country" name="country" value="{{ old('country', $student->country ?? '') }}" maxlength="100">
            <p class="mt-1 text-xs text-rose-600">@error('country'){{ $message }}@enderror</p>
        </div>
    </div>
</fieldset>

{{-- ===================================================================== --}}
{{-- 5. Academic / admission                                                --}}
{{-- ===================================================================== --}}
<fieldset class="mt-8">
    <legend class="panel-title">Academic / admission</legend>
    <p class="panel-subtitle">Admission context and the previous-education snapshot. A student's year-by-year enrollment stays in the Enrollment module, not on this record.</p>

    <div class="mt-4 grid gap-5 md:grid-cols-2">
        @if($isEdit)
            <div class="md:col-span-2 rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700">
                <p>Student number: <span class="font-semibold">{{ $student->student_number }}</span> <span class="text-xs text-slate-500">(server-generated, never editable)</span></p>
                <p class="mt-1">
                    Admission source:
                    @if($student->admissionApplication)
                        <span class="font-semibold">{{ $student->admissionApplication->application_number }}</span>
                        <span class="text-xs text-slate-500">(linked by the admission → student conversion, never editable here)</span>
                    @else
                        <span class="text-slate-500">direct registration — no admission application is linked</span>
                    @endif
                </p>
                <p class="mt-1">
                    Current enrollment:
                    @if($currentEnrollment)
                        <span class="font-semibold">{{ $currentEnrollment->enrollment_number }}</span>
                        · {{ $currentEnrollment->academicYear?->name ?? '—' }}
                        @if($currentEnrollment->program) · {{ $currentEnrollment->program->name }} @endif
                        @if($currentEnrollment->section) · {{ $currentEnrollment->section->name }} @endif
                    @else
                        <span class="text-slate-500">no active enrollment</span>
                    @endif
                </p>
                @can('viewAny', App\Models\StudentEnrollment::class)
                    <a class="mt-2 inline-block text-xs font-semibold text-indigo-600 hover:underline" href="{{ route('students.show', ['student' => $student, 'tab' => 'enrollments']) }}">Manage enrollments</a>
                @endcan
            </div>
        @endif

        <div>
            <label class="text-sm font-semibold" for="admission_date">Admission Date</label>
            <input class="input mt-1" id="admission_date" name="admission_date" type="date" value="{{ old('admission_date', isset($student->admission_date) ? $student->admission_date->format('Y-m-d') : now()->format('Y-m-d')) }}">
            <p class="mt-1 text-xs text-rose-600">@error('admission_date'){{ $message }}@enderror</p>
        </div>

        @if(! $isEdit && $canEnroll && ($academicYears ?? collect())->isNotEmpty())
            <div class="md:col-span-2 rounded-xl border border-slate-200 bg-slate-50 p-4">
                <p class="text-sm font-semibold text-slate-700">First enrollment (optional)</p>
                <p class="mt-1 text-xs text-slate-500">
                    Fill this in to open the student's first enrollment in the same save. The enrollment number is generated
                    server-side; the section must belong to the chosen academic year and program. Leaving it empty creates the
                    student with no enrollment — a legitimate state for an imported or not-yet-enrolled record, and one that
                    can be completed later from the Enrollments tab.
                </p>

                <div class="mt-4 grid gap-5 md:grid-cols-2">
                    <div>
                        <label class="text-sm font-semibold" for="academic_year_id">Academic Year</label>
                        <select class="input mt-1" id="academic_year_id" name="academic_year_id">
                            <option value="">— No enrollment —</option>
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
                            @foreach($programs as $program)
                                <option value="{{ $program->id }}" @selected((int) old('program_id') === $program->id)>{{ $program->name }} ({{ $program->code }})</option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-xs text-rose-600">@error('program_id'){{ $message }}@enderror</p>
                    </div>
                    <div>
                        <label class="text-sm font-semibold" for="section_id">Section / Batch</label>
                        <select class="input mt-1" id="section_id" name="section_id">
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
                        <p class="mt-1 text-xs text-rose-600">@error('section_id'){{ $message }}@enderror</p>
                    </div>
                    <div>
                        <label class="text-sm font-semibold" for="enrollment_date">Enrollment Date</label>
                        <input class="input mt-1" id="enrollment_date" name="enrollment_date" type="date" value="{{ old('enrollment_date', now()->format('Y-m-d')) }}">
                        <p class="mt-1 text-xs text-rose-600">@error('enrollment_date'){{ $message }}@enderror</p>
                    </div>
                </div>
            </div>
        @endif

        <div class="md:col-span-2 mt-2">
            <p class="text-sm font-semibold text-slate-700">Previous education</p>
            <p class="mt-1 text-xs text-slate-500">A snapshot of the school/qualification the student came from — it stays on this record even if the admission application is later corrected.</p>
        </div>
        <div>
            <label class="text-sm font-semibold" for="previous_school_name">School / Institution</label>
            <input class="input mt-1" id="previous_school_name" name="previous_school_name" value="{{ old('previous_school_name', $student->previous_school_name ?? '') }}" maxlength="255">
            <p class="mt-1 text-xs text-rose-600">@error('previous_school_name'){{ $message }}@enderror</p>
        </div>
        <div>
            <label class="text-sm font-semibold" for="previous_school_board">Board / University</label>
            <input class="input mt-1" id="previous_school_board" name="previous_school_board" value="{{ old('previous_school_board', $student->previous_school_board ?? '') }}" maxlength="100">
            <p class="mt-1 text-xs text-rose-600">@error('previous_school_board'){{ $message }}@enderror</p>
        </div>
        <div>
            <label class="text-sm font-semibold" for="previous_qualification">Qualification Passed</label>
            <input class="input mt-1" id="previous_qualification" name="previous_qualification" value="{{ old('previous_qualification', $student->previous_qualification ?? '') }}" maxlength="100" placeholder="e.g. Class XII, Diploma">
            <p class="mt-1 text-xs text-rose-600">@error('previous_qualification'){{ $message }}@enderror</p>
        </div>
        <div>
            <label class="text-sm font-semibold" for="previous_exam_year">Year of Passing</label>
            <input class="input mt-1" id="previous_exam_year" name="previous_exam_year" type="number" min="1900" max="2100" step="1" value="{{ old('previous_exam_year', $student->previous_exam_year ?? '') }}">
            <p class="mt-1 text-xs text-rose-600">@error('previous_exam_year'){{ $message }}@enderror</p>
        </div>
        <div>
            <label class="text-sm font-semibold" for="previous_percentage">Percentage / Marks</label>
            <input class="input mt-1" id="previous_percentage" name="previous_percentage" type="number" min="0" max="100" step="0.01" value="{{ old('previous_percentage', $student->previous_percentage ?? '') }}">
            <p class="mt-1 text-xs text-rose-600">@error('previous_percentage'){{ $message }}@enderror</p>
        </div>
    </div>
</fieldset>

{{-- ===================================================================== --}}
{{-- 6. Additional information                                              --}}
{{-- ===================================================================== --}}
<fieldset class="mt-8">
    <legend class="panel-title">Additional information</legend>
    <p class="panel-subtitle">Optional health and background notes held on the student record.</p>

    <div class="mt-4 grid gap-5 md:grid-cols-2">
        <div>
            <label class="text-sm font-semibold" for="blood_group">Blood Group</label>
            <select class="input mt-1" id="blood_group" name="blood_group">
                <option value="">— Select —</option>
                @foreach(\App\Models\Student::BLOOD_GROUPS as $group)
                    <option value="{{ $group }}" @selected(old('blood_group', $student->blood_group ?? '') === $group)>{{ $group === 'unknown' ? 'Not known' : $group }}</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-rose-600">@error('blood_group'){{ $message }}@enderror</p>
        </div>
        <div>
            <label class="text-sm font-semibold" for="nationality">Nationality</label>
            <input class="input mt-1" id="nationality" name="nationality" value="{{ old('nationality', $student->nationality ?? '') }}" maxlength="100">
            <p class="mt-1 text-xs text-rose-600">@error('nationality'){{ $message }}@enderror</p>
        </div>
        <div>
            <label class="text-sm font-semibold" for="mother_tongue">Mother Tongue</label>
            <input class="input mt-1" id="mother_tongue" name="mother_tongue" value="{{ old('mother_tongue', $student->mother_tongue ?? '') }}" maxlength="100">
            <p class="mt-1 text-xs text-rose-600">@error('mother_tongue'){{ $message }}@enderror</p>
        </div>
        <div class="md:col-span-2">
            <label class="text-sm font-semibold" for="remarks">Remarks</label>
            <textarea class="input mt-1" id="remarks" name="remarks" rows="3" maxlength="2000">{{ old('remarks', $student->remarks ?? '') }}</textarea>
            <p class="mt-1 text-xs text-rose-600">@error('remarks'){{ $message }}@enderror</p>
        </div>
    </div>
</fieldset>

{{-- ===================================================================== --}}
{{-- 7. Documents (link, never an inline upload)                            --}}
{{-- ===================================================================== --}}
<fieldset class="mt-8">
    <legend class="panel-title">Documents</legend>
    <p class="panel-subtitle">Documents are held by the Document module (private disk, verification workflow) — they are linked from here, never attached to this form.</p>

    @if($isEdit)
        <p class="mt-3 text-sm text-slate-700">
            Stored documents: <span class="font-semibold">{{ $documentsCount ?? 0 }}</span>
        </p>
        <div class="mt-3 flex flex-wrap gap-2">
            @can('viewAny', App\Models\StudentDocument::class)
                <a class="button !bg-slate-200 !text-slate-700 !px-3 !py-2 text-xs" href="{{ route('students.show', ['student' => $student, 'tab' => 'documents']) }}">Open this student's documents</a>
            @endcan
            @can('create', App\Models\StudentDocument::class)
                <a class="button !px-3 !py-2 text-xs" href="{{ route('student-documents.create', ['student_id' => $student->id]) }}">+ Upload a document</a>
            @endcan
        </div>
    @else
        <p class="mt-3 text-sm text-slate-600">
            Uploads are possible once the student exists — a private file must belong to a real student record. Create the
            student first, then open the Documents tab of the profile (or the document register) to upload.
        </p>
        @can('viewAny', App\Models\StudentDocument::class)
            <a class="button mt-3 !bg-slate-200 !text-slate-700 !px-3 !py-2 text-xs" href="{{ route('student-documents.index') }}">Open the document register</a>
        @endcan
    @endif
</fieldset>

<div class="mt-8 flex gap-2">
    <button class="button" type="submit">{{ $submitLabel }}</button>
    <a class="button !bg-slate-200 !text-slate-700" href="{{ $isEdit ? route('students.show', $student) : route('students.index') }}">Cancel</a>
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
