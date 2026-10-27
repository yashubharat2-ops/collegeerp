@php
    /*
     * STEP 1 — Basic Information (four-step wizard).
     *
     * Section order is fixed and must not be rearranged:
     *   A. Student Identification   (Student ID, Admission Number, Roll Number)
     *   B. Personal Details
     *   C. Parent Details           (father + mother)
     *   D. Guardian Details
     *   E. Permanent Address
     *   F. Temporary Address        (correspondence, "Same as permanent")
     *   G. Photograph
     *   H. Signatures
     *
     * Parent/Guardian used to be a separate step; it is now sections C and D of
     * this page. The headings carry no section numbering — the progress bar is
     * the only numbered navigation in the wizard.
     *
     * Security notes honoured by this view:
     * - the three identifiers in section A are READ-ONLY and come from the
     *   server (StudentIdentifierService). No input is rendered for them, so a
     *   crafted POST cannot set or change one; a value the workflow has not
     *   allocated yet is shown as "Pending", never invented;
     * - the stored Aadhaar / government ID are NEVER echoed into the page, not
     *   even after a failed validation; only the masked tail is displayed, and a
     *   blank field means "keep what is stored";
     * - image previews are streamed from the private disk by an authorised
     *   route, so no stored file has a public URL.
     *
     * Address behaviour: when the geography directory has data for a level the
     * control is a dependent <select> (children only, parent change clears the
     * children); when it does not, the control is a plain text input bound to the
     * existing student column, so the form is usable with no directory loaded.
     */
    $value = fn (string $key) => old($key, $stepData[$key] ?? $merged[$key] ?? null);

    // Geography ids actually on screen: old() wins after a failed save, then the
    // draft, then the directory's default country (India when present).
    $geoValue = function (string $key) use ($geoSelection) {
        return old($key, $geoSelection[$key] ?? null);
    };

    // The five images. Photograph and Signatures are separate SECTIONS, but both
    // render through this one list so no upload capability can drift apart.
    $photos = [
        'photo' => ['label' => 'Student photograph', 'column' => 'photo_path'],
        'father_photo' => ['label' => "Father's photograph", 'column' => 'father_photo_path'],
        'mother_photo' => ['label' => "Mother's photograph", 'column' => 'mother_photo_path'],
        'guardian_photo' => ['label' => "Guardian's photograph", 'column' => 'guardian_photo_path'],
    ];
    $signatures = [
        'signature' => ['label' => 'Student signature', 'column' => 'signature_path'],
    ];

    // The address directory: one definition used by BOTH address blocks, so the
    // two can never drift (permanent uses the ids, temporary mirrors the text).
    $levels = [
        ['level' => 'country', 'id' => 'country_id', 'text' => 'country', 'label' => 'Country'],
        ['level' => 'state', 'id' => 'state_id', 'text' => 'state', 'label' => 'State'],
        ['level' => 'district', 'id' => 'district_id', 'text' => 'district', 'label' => 'District'],
        ['level' => 'block', 'id' => 'block_id', 'text' => 'block_tehsil', 'label' => 'Block / Tehsil'],
        ['level' => 'village', 'id' => 'village_id', 'text' => 'city', 'label' => 'Village / City'],
    ];
    $directoryLoaded = in_array(true, $addressLevels, true);

    // Section D — guardian derivation. Choosing Father / Mother / Self makes the
    // guardian block follow that record (server-side derivation lives in
    // RegistrationStepRules::normaliseParent()).
    $relation = $value('guardian_relation');
    $guardianDerived = in_array($relation, $derivedRelations, true);
    $selfName = trim(implode(' ', array_filter([
        (string) ($value('first_name') ?? ''),
        (string) ($value('middle_name') ?? ''),
        (string) ($value('last_name') ?? ''),
    ], fn ($part) => trim($part) !== '')));
@endphp

<form id="erp-step-form" data-erp-step-form method="POST" action="{{ route('students.registration.step.save', ['step' => 1]) }}"
      enctype="multipart/form-data" novalidate>
    @csrf
    <input type="hidden" name="draft_id" value="{{ $draftId }}">

    {{-- Values the "Self" guardian auto-fill copies from; never submitted. --}}
    <input type="hidden" data-erp-self="name" value="{{ $selfName }}">
    <input type="hidden" data-erp-self="phone" value="{{ $value('phone') }}">
    <input type="hidden" data-erp-self="email" value="{{ $value('email') }}">

    {{-- === A. Student Identification ==================================== --}}
    <section class="erp-card erp-wizard__card" aria-labelledby="wz-basic-identifiers">
        <header class="erp-card__head">
            <div>
                <h3 class="erp-card__title" id="wz-basic-identifiers">Student Identification</h3>
                <p class="erp-card__desc">Assigned by the college — read-only on this form.</p>
            </div>
        </header>

        <div class="erp-card__body">
            <div class="erp-id-grid">
                @foreach($identifiers as $key => $identifier)
                    <div class="erp-id erp-id--{{ $identifier['state'] }}" data-erp-identifier="{{ $key }}">
                        <span class="erp-id__label">{{ $identifier['label'] }}</span>
                        <span class="erp-id__value" data-erp-identifier-value="{{ $key }}">
                            {{ $identifier['value'] ?? 'Pending' }}
                        </span>
                        <span class="erp-id__note">{{ $identifier['note'] }}</span>
                    </div>
                @endforeach
            </div>
            <p class="erp-help">
                These identifiers are generated by their own workflow (registration, admission, enrollment).
                Opening or saving this wizard never creates one.
            </p>
        </div>
    </section>

    {{-- === B. Personal Details ========================================== --}}
    <section class="erp-card erp-wizard__card" aria-labelledby="wz-basic-personal">
        <header class="erp-card__head">
            <div>
                <h3 class="erp-card__title" id="wz-basic-personal">Personal Details</h3>
                <p class="erp-card__desc">As printed on the student's certificates.</p>
            </div>
        </header>

        <div class="erp-card__body">
            <div class="erp-grid">
                <div class="erp-field erp-col-4">
                    <label class="erp-label" for="first_name">First name <span class="erp-req">*</span></label>
                    <input class="erp-input" id="first_name" name="first_name" value="{{ $value('first_name') }}" maxlength="255" required autocomplete="given-name">
                    <p class="erp-error">@error('first_name'){{ $message }}@enderror</p>
                </div>
                <div class="erp-field erp-col-4">
                    <label class="erp-label" for="middle_name">Middle name</label>
                    <input class="erp-input" id="middle_name" name="middle_name" value="{{ $value('middle_name') }}" maxlength="255" autocomplete="additional-name">
                    <p class="erp-error">@error('middle_name'){{ $message }}@enderror</p>
                </div>
                <div class="erp-field erp-col-4">
                    <label class="erp-label" for="last_name">Last name</label>
                    <input class="erp-input" id="last_name" name="last_name" value="{{ $value('last_name') }}" maxlength="255" autocomplete="family-name">
                    <p class="erp-error">@error('last_name'){{ $message }}@enderror</p>
                </div>

                <div class="erp-field erp-col-3">
                    <label class="erp-label" for="date_of_birth">Date of birth</label>
                    <input class="erp-input" id="date_of_birth" name="date_of_birth" type="date" lang="en-IN"
                           value="{{ $value('date_of_birth') }}" max="{{ now()->subDay()->toDateString() }}">
                    <p class="erp-error">@error('date_of_birth'){{ $message }}@enderror</p>
                </div>
                <div class="erp-field erp-col-3">
                    <label class="erp-label" for="gender">Gender</label>
                    <select class="erp-input" id="gender" name="gender">
                        <option value="">— Select —</option>
                        @foreach(\App\Models\Student::GENDERS as $gender)
                            <option value="{{ $gender }}" @selected($value('gender') === $gender)>{{ ucfirst(str_replace('_', ' ', $gender)) }}</option>
                        @endforeach
                    </select>
                    <p class="erp-error">@error('gender'){{ $message }}@enderror</p>
                </div>
                <div class="erp-field erp-col-3">
                    <label class="erp-label" for="category">Category</label>
                    <select class="erp-input" id="category" name="category">
                        <option value="">— Select —</option>
                        @foreach(\App\Models\Student::CATEGORIES as $category)
                            <option value="{{ $category }}" @selected($value('category') === $category)>{{ \App\Models\Student::categoryLabel($category) }}</option>
                        @endforeach
                    </select>
                    <p class="erp-error">@error('category'){{ $message }}@enderror</p>
                </div>
                <div class="erp-field erp-col-3">
                    <label class="erp-label" for="mother_tongue">Mother tongue</label>
                    <input class="erp-input" id="mother_tongue" name="mother_tongue" value="{{ $value('mother_tongue') }}" maxlength="100">
                    <p class="erp-error">@error('mother_tongue'){{ $message }}@enderror</p>
                </div>

                <div class="erp-field erp-col-3">
                    <label class="erp-label" for="blood_group">Blood group</label>
                    <select class="erp-input" id="blood_group" name="blood_group">
                        <option value="">— Select —</option>
                        @foreach(\App\Models\Student::BLOOD_GROUPS as $group)
                            <option value="{{ $group }}" @selected($value('blood_group') === $group)>{{ $group === 'unknown' ? 'Unknown' : $group }}</option>
                        @endforeach
                    </select>
                    <p class="erp-error">@error('blood_group'){{ $message }}@enderror</p>
                </div>
                <div class="erp-field erp-col-3">
                    <label class="erp-label" for="nationality">Nationality</label>
                    <input class="erp-input" id="nationality" name="nationality" value="{{ $value('nationality') }}" maxlength="100" autocomplete="country-name">
                    <p class="erp-error">@error('nationality'){{ $message }}@enderror</p>
                </div>
                <div class="erp-field erp-col-3">
                    <label class="erp-label" for="phone">Mobile number</label>
                    <input class="erp-input" id="phone" name="phone" value="{{ $value('phone') }}" maxlength="30" inputmode="tel" autocomplete="tel">
                    <p class="erp-error">@error('phone'){{ $message }}@enderror</p>
                </div>
                <div class="erp-field erp-col-3">
                    <label class="erp-label" for="alternate_phone">Alternate number</label>
                    <input class="erp-input" id="alternate_phone" name="alternate_phone" value="{{ $value('alternate_phone') }}" maxlength="30" inputmode="tel">
                    <p class="erp-error">@error('alternate_phone'){{ $message }}@enderror</p>
                </div>
                <div class="erp-field erp-col-6">
                    <label class="erp-label" for="email">Email</label>
                    <input class="erp-input" id="email" name="email" type="email" value="{{ $value('email') }}" maxlength="255" autocomplete="email">
                    <p class="erp-error">@error('email'){{ $message }}@enderror</p>
                </div>

                {{-- Identity numbers: encrypted at rest, masked on screen. --}}
                <div class="erp-field erp-col-4">
                    <label class="erp-label" for="aadhaar_number">Aadhaar number</label>
                    <input class="erp-input" id="aadhaar_number" name="aadhaar_number" value="" inputmode="numeric" autocomplete="off" maxlength="20"
                           placeholder="{{ $aadhaarMasked ? 'Leave blank to keep '.$aadhaarMasked : '12 digits, e.g. 9999 4105 7058' }}">
                    @if($aadhaarMasked)
                        <p class="erp-help">Recorded: <strong>{{ $aadhaarMasked }}</strong></p>
                        <label class="erp-check"><input type="checkbox" name="remove_aadhaar" value="1"> Remove the stored Aadhaar</label>
                    @endif
                    <p class="erp-error">@error('aadhaar_number'){{ $message }}@enderror</p>
                </div>
                <div class="erp-field erp-col-4">
                    <label class="erp-label" for="apaar_id">APAAR / ABC ID</label>
                    <input class="erp-input" id="apaar_id" name="apaar_id" value="{{ $value('apaar_id') }}" maxlength="12" inputmode="numeric" pattern="[0-9]{12}">
                    <p class="erp-help">12 digits, if issued.</p>
                    <p class="erp-error">@error('apaar_id'){{ $message }}@enderror</p>
                </div>
                <div class="erp-field erp-col-2">
                    <label class="erp-label" for="govt_id_type">Other government ID</label>
                    <select class="erp-input" id="govt_id_type" name="govt_id_type">
                        <option value="">— None —</option>
                        @foreach(\App\Models\Student::GOVT_ID_TYPES as $type)
                            <option value="{{ $type }}" @selected($value('govt_id_type') === $type)>{{ ucfirst(str_replace('_', ' ', $type)) }}</option>
                        @endforeach
                    </select>
                    <p class="erp-error">@error('govt_id_type'){{ $message }}@enderror</p>
                </div>
                <div class="erp-field erp-col-2">
                    <label class="erp-label" for="govt_id_number">ID number</label>
                    <input class="erp-input" id="govt_id_number" name="govt_id_number" value="" autocomplete="off" maxlength="100"
                           placeholder="{{ $hasGovtId ? 'Stored — leave blank to keep' : '' }}">
                    @if($hasGovtId)
                        <label class="erp-check"><input type="checkbox" name="remove_govt_id" value="1"> Remove</label>
                    @endif
                    <p class="erp-error">@error('govt_id_number'){{ $message }}@enderror</p>
                </div>
            </div>

            @if(! empty($duplicateWarnings))
                <div class="erp-alert erp-alert--info" role="status" data-erp-duplicate-warning>
                    <strong>Possible duplicate — please check:</strong>
                    <ul>
                        @foreach($duplicateWarnings as $warning)
                            <li>{{ $warning['message'] }}</li>
                        @endforeach
                    </ul>
                    This does not block the registration; an approver reviews it.
                </div>
            @endif
        </div>
    </section>

    {{-- === C. Parent Details ============================================ --}}
    <section class="erp-card erp-wizard__card" aria-labelledby="wz-basic-parents">
        <header class="erp-card__head">
            <div>
                <h3 class="erp-card__title" id="wz-basic-parents">Parent Details</h3>
                <p class="erp-card__desc">Kept separately from the guardian block below.</p>
            </div>
        </header>

        <div class="erp-card__body">
            @foreach([
                'father' => ['label' => 'Father', 'photo' => 'father_photo_path'],
                'mother' => ['label' => 'Mother', 'photo' => 'mother_photo_path'],
            ] as $person => $meta)
                <div class="erp-subcard">
                    <p class="erp-subhead">
                        {{ $meta['label'] }}
                        @if(! empty($merged[$meta['photo']]))
                            <span class="erp-subhead__hint">Photograph attached in this step.</span>
                        @endif
                    </p>

                    <div class="erp-grid">
                        <div class="erp-field erp-col-6">
                            <label class="erp-label" for="{{ $person }}_name">{{ $meta['label'] }}'s name</label>
                            <input class="erp-input" id="{{ $person }}_name" name="{{ $person }}_name"
                                   value="{{ $value($person.'_name') }}" maxlength="255" autocomplete="off">
                            <p class="erp-error">@error($person.'_name'){{ $message }}@enderror</p>
                        </div>
                        <div class="erp-field erp-col-3">
                            <label class="erp-label" for="{{ $person }}_phone">Mobile number</label>
                            <input class="erp-input" id="{{ $person }}_phone" name="{{ $person }}_phone"
                                   value="{{ $value($person.'_phone') }}" maxlength="30" inputmode="tel">
                            <p class="erp-error">@error($person.'_phone'){{ $message }}@enderror</p>
                        </div>
                        <div class="erp-field erp-col-3">
                            <label class="erp-label" for="{{ $person }}_email">Email</label>
                            <input class="erp-input" id="{{ $person }}_email" name="{{ $person }}_email" type="email"
                                   value="{{ $value($person.'_email') }}" maxlength="255">
                            <p class="erp-error">@error($person.'_email'){{ $message }}@enderror</p>
                        </div>
                        <div class="erp-field erp-col-6">
                            <label class="erp-label" for="{{ $person }}_occupation">Occupation</label>
                            <input class="erp-input" id="{{ $person }}_occupation" name="{{ $person }}_occupation"
                                   value="{{ $value($person.'_occupation') }}" maxlength="150">
                            <p class="erp-error">@error($person.'_occupation'){{ $message }}@enderror</p>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </section>

    {{-- === D. Guardian Details ========================================== --}}
    <section class="erp-card erp-wizard__card" aria-labelledby="wz-basic-guardian">
        <header class="erp-card__head">
            <div>
                <h3 class="erp-card__title" id="wz-basic-guardian">Guardian Details</h3>
                <p class="erp-card__desc">Who the college contacts for this student.</p>
            </div>
        </header>

        <div class="erp-card__body">
            <div class="erp-grid">
                <div class="erp-field erp-col-4">
                    <label class="erp-label" for="guardian_relation">Guardian is</label>
                    <select class="erp-input" id="guardian_relation" name="guardian_relation" data-erp-guardian-relation>
                        <option value="">— Not chosen yet —</option>
                        <option value="father" @selected($relation === 'father')>Father</option>
                        <option value="mother" @selected($relation === 'mother')>Mother</option>
                        <option value="self" @selected($relation === 'self')>Self (student is the guardian)</option>
                        <option value="other" @selected($relation === 'other')>Other / legal guardian</option>
                    </select>
                    <p class="erp-error">@error('guardian_relation'){{ $message }}@enderror</p>
                </div>

                <div class="erp-field erp-col-8">
                    <p class="erp-autofill-note" data-erp-guardian-note @if($relation === '') hidden @endif>
                        Choosing Father, Mother or Self fills the guardian details from that record and keeps them in step.
                    </p>
                </div>

                <div class="erp-field erp-col-6">
                    <label class="erp-label" for="guardian_name">Guardian's name</label>
                    <input class="erp-input" id="guardian_name" name="guardian_name" value="{{ $value('guardian_name') }}"
                           maxlength="255" data-erp-guardian="name"
                           @if($guardianDerived) readonly tabindex="-1" @endif>
                    <p class="erp-error">@error('guardian_name'){{ $message }}@enderror</p>
                </div>
                <div class="erp-field erp-col-3">
                    <label class="erp-label" for="guardian_phone">Guardian's mobile</label>
                    <input class="erp-input" id="guardian_phone" name="guardian_phone" value="{{ $value('guardian_phone') }}"
                           maxlength="30" inputmode="tel" data-erp-guardian="phone"
                           @if($guardianDerived) readonly tabindex="-1" @endif>
                    <p class="erp-error">@error('guardian_phone'){{ $message }}@enderror</p>
                </div>
                <div class="erp-field erp-col-3">
                    <label class="erp-label" for="guardian_email">Guardian's email</label>
                    <input class="erp-input" id="guardian_email" name="guardian_email" type="email" value="{{ $value('guardian_email') }}"
                           maxlength="255" data-erp-guardian="email"
                           @if($guardianDerived) readonly tabindex="-1" @endif>
                    <p class="erp-error">@error('guardian_email'){{ $message }}@enderror</p>
                </div>
                <div class="erp-field erp-col-4">
                    <label class="erp-label" for="guardian_occupation">Guardian's occupation</label>
                    <input class="erp-input" id="guardian_occupation" name="guardian_occupation" value="{{ $value('guardian_occupation') }}"
                           maxlength="150" data-erp-guardian="occupation"
                           @if($guardianDerived) readonly tabindex="-1" @endif>
                    <p class="erp-error">@error('guardian_occupation'){{ $message }}@enderror</p>
                </div>
                <div class="erp-field erp-col-8">
                    <label class="erp-label" for="guardian_address">Guardian's address</label>
                    <input class="erp-input" id="guardian_address" name="guardian_address" value="{{ $value('guardian_address') }}" maxlength="2000">
                    <p class="erp-error">@error('guardian_address'){{ $message }}@enderror</p>
                </div>
            </div>
        </div>
    </section>

    {{-- === E. Permanent Address ========================================= --}}
    <section class="erp-card erp-wizard__card" aria-labelledby="wz-basic-permanent">
        <header class="erp-card__head">
            <div>
                <h3 class="erp-card__title" id="wz-basic-permanent">Permanent Address</h3>
                <p class="erp-card__desc">
                    @if($directoryLoaded)
                        Country → State → District → Block → Village. Changing a level clears the levels below it.
                    @else
                        No geography directory is loaded for this college yet, so the address is entered as text.
                    @endif
                </p>
            </div>
        </header>

        <div class="erp-card__body">
            <div class="erp-grid">
                @foreach($levels as $index => $entry)
                    @php
                        // A level is selectable as soon as its PARENT has a value:
                        // with the country pre-selected (India), the state list is
                        // immediately usable instead of a disabled empty box.
                        $parentEntry = $index > 0 ? $levels[$index - 1] : null;
                        $levelDisabled = $parentEntry !== null && blank($geoValue($parentEntry['id']));
                    @endphp
                    <div class="erp-field erp-col-3">
                        @if($addressLevels[$entry['level']])
                            <label class="erp-label" for="{{ $entry['id'] }}">{{ $entry['label'] }}</label>
                            <select class="erp-input" id="{{ $entry['id'] }}" name="{{ $entry['id'] }}"
                                    data-geo-level="{{ $entry['level'] }}"
                                    data-empty-label="— Select {{ strtolower($entry['label']) }} —"
                                    @if($levelDisabled) disabled @endif>
                                <option value="">{{ $geoOptions[$entry['level']]->isEmpty() ? '— Not configured —' : '— Select '.strtolower($entry['label']).' —' }}</option>
                                @foreach($geoOptions[$entry['level']] as $option)
                                    <option value="{{ $option->id }}" @selected((string) $geoValue($entry['id']) === (string) $option->id)>{{ $option->name }}</option>
                                @endforeach
                            </select>
                            @if($entry['level'] === 'country' && $geoOptions['country']->isEmpty())
                                <p class="erp-help">No country is configured in the geography directory.</p>
                            @endif
                            <p class="erp-error">@error($entry['id']){{ $message }}@enderror</p>
                        @else
                            <label class="erp-label" for="{{ $entry['text'] }}">{{ $entry['label'] }}</label>
                            <input class="erp-input" id="{{ $entry['text'] }}" name="{{ $entry['text'] }}"
                                   value="{{ $value($entry['text']) }}" maxlength="100">
                            <p class="erp-error">@error($entry['text']){{ $message }}@enderror</p>
                        @endif
                    </div>
                @endforeach

                <div class="erp-field erp-col-3">
                    <label class="erp-label" for="gram_panchayat_ward">Gram panchayat / Ward</label>
                    <input class="erp-input" id="gram_panchayat_ward" name="gram_panchayat_ward" value="{{ $value('gram_panchayat_ward') }}" maxlength="150">
                    <p class="erp-error">@error('gram_panchayat_ward'){{ $message }}@enderror</p>
                </div>
                <div class="erp-field erp-col-3">
                    <label class="erp-label" for="postal_code">Postal code</label>
                    <input class="erp-input" id="postal_code" name="postal_code" value="{{ $value('postal_code') }}" maxlength="20" inputmode="numeric" autocomplete="postal-code">
                    <p class="erp-error">@error('postal_code'){{ $message }}@enderror</p>
                </div>
                <div class="erp-field erp-col-6">
                    <label class="erp-label" for="address_line_1">Address line 1</label>
                    <input class="erp-input" id="address_line_1" name="address_line_1" value="{{ $value('address_line_1') }}" maxlength="2000" autocomplete="address-line1">
                    <p class="erp-error">@error('address_line_1'){{ $message }}@enderror</p>
                </div>
                <div class="erp-field erp-col-6">
                    <label class="erp-label" for="address_line_2">Address line 2</label>
                    <input class="erp-input" id="address_line_2" name="address_line_2" value="{{ $value('address_line_2') }}" maxlength="2000" autocomplete="address-line2">
                    <p class="erp-error">@error('address_line_2'){{ $message }}@enderror</p>
                </div>
            </div>
        </div>
    </section>

    {{-- === F. Temporary Address (correspondence) ======================== --}}
    @php
        $sameAsPermanent = old('correspondence_same_as_permanent', $stepData['correspondence_same_as_permanent'] ?? true);
        $sameAsPermanent = filter_var($sameAsPermanent, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
    @endphp

    <section class="erp-card erp-wizard__card" aria-labelledby="wz-basic-temporary">
        <header class="erp-card__head">
            <div>
                <h3 class="erp-card__title" id="wz-basic-temporary">Temporary Address</h3>
                <p class="erp-card__desc">Where the student can be reached during the course.</p>
            </div>
            <label class="erp-checkline erp-wizard__card-toggle">
                <input type="hidden" name="correspondence_same_as_permanent" value="0">
                <input type="checkbox" name="correspondence_same_as_permanent" value="1"
                       data-erp-same-address @checked($sameAsPermanent)>
                Same as permanent address
            </label>
        </header>

        <div class="erp-card__body">
            <div class="erp-grid" data-erp-correspondence @if($sameAsPermanent) hidden @endif>
                <div class="erp-field erp-col-6">
                    <label class="erp-label" for="correspondence_address_line_1">Address line 1</label>
                    <input class="erp-input" id="correspondence_address_line_1" name="correspondence_address_line_1"
                           value="{{ $value('correspondence_address_line_1') }}" maxlength="2000">
                </div>
                <div class="erp-field erp-col-6">
                    <label class="erp-label" for="correspondence_address_line_2">Address line 2</label>
                    <input class="erp-input" id="correspondence_address_line_2" name="correspondence_address_line_2"
                           value="{{ $value('correspondence_address_line_2') }}" maxlength="2000">
                </div>
                <div class="erp-field erp-col-3">
                    <label class="erp-label" for="correspondence_city">Village / City</label>
                    <input class="erp-input" id="correspondence_city" name="correspondence_city" value="{{ $value('correspondence_city') }}" maxlength="100">
                </div>
                <div class="erp-field erp-col-3">
                    <label class="erp-label" for="correspondence_district">District</label>
                    <input class="erp-input" id="correspondence_district" name="correspondence_district" value="{{ $value('correspondence_district') }}" maxlength="100">
                </div>
                <div class="erp-field erp-col-3">
                    <label class="erp-label" for="correspondence_state">State</label>
                    <input class="erp-input" id="correspondence_state" name="correspondence_state" value="{{ $value('correspondence_state') }}" maxlength="100">
                </div>
                <div class="erp-field erp-col-3">
                    <label class="erp-label" for="correspondence_postal_code">Postal code</label>
                    <input class="erp-input" id="correspondence_postal_code" name="correspondence_postal_code" value="{{ $value('correspondence_postal_code') }}" maxlength="20">
                </div>
                <div class="erp-field erp-col-3">
                    <label class="erp-label" for="correspondence_country">Country</label>
                    <input class="erp-input" id="correspondence_country" name="correspondence_country" value="{{ $value('correspondence_country') }}" maxlength="100">
                </div>
            </div>

            <p class="erp-help" data-erp-same-address-note @if(! $sameAsPermanent) hidden @endif>
                The permanent address above is used as the correspondence address.
            </p>
        </div>
    </section>

    {{-- === G. Photograph ================================================ --}}
    <section class="erp-card erp-wizard__card" aria-labelledby="wz-basic-photograph">
        <header class="erp-card__head">
            <div>
                <h3 class="erp-card__title" id="wz-basic-photograph">Photograph</h3>
                <p class="erp-card__desc">JPG, PNG or WebP · up to 2 MB each. Stored privately.</p>
            </div>
        </header>

        <div class="erp-card__body">
            @include('students.registration._media_field', ['fields' => ['photo' => $photos['photo']], 'value' => $value])

            <p class="erp-subhead">
                Parent &amp; guardian photographs
                <span class="erp-subhead__hint">Optional — one file per person.</span>
            </p>

            @include('students.registration._media_field', [
                'fields' => [
                    'father_photo' => $photos['father_photo'],
                    'mother_photo' => $photos['mother_photo'],
                    'guardian_photo' => $photos['guardian_photo'],
                ],
                'value' => $value,
            ])
        </div>
    </section>

    {{-- === H. Signatures ================================================ --}}
    <section class="erp-card erp-wizard__card" aria-labelledby="wz-basic-signature">
        <header class="erp-card__head">
            <div>
                <h3 class="erp-card__title" id="wz-basic-signature">Signatures</h3>
                <p class="erp-card__desc">A clear signature image on plain paper.</p>
            </div>
        </header>

        <div class="erp-card__body">
            @include('students.registration._media_field', ['fields' => $signatures, 'value' => $value])
        </div>
    </section>

    @include('students.registration._actions', ['step' => 1, 'draftId' => $draftId, 'submitMode' => false])
</form>
