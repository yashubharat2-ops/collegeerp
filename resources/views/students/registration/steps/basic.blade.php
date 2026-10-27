@php
    /*
     * STEP 1 — Basic Information.
     *
     * Personal details, the five images (student photograph, signature, father,
     * mother and guardian photographs) and the two addresses.
     *
     * Security notes honoured by this view:
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
    $media = [
        'photo' => ['label' => 'Student photograph', 'column' => 'photo_path'],
        'signature' => ['label' => 'Student signature', 'column' => 'signature_path'],
        'father_photo' => ['label' => "Father's photograph", 'column' => 'father_photo_path'],
        'mother_photo' => ['label' => "Mother's photograph", 'column' => 'mother_photo_path'],
        'guardian_photo' => ['label' => "Guardian's photograph", 'column' => 'guardian_photo_path'],
    ];
@endphp

<form id="erp-step-form" data-erp-step-form method="POST" action="{{ route('students.registration.step.save', ['step' => 1]) }}"
      enctype="multipart/form-data" novalidate>
    @csrf
    <input type="hidden" name="draft_id" value="{{ $draftId }}">

    {{-- --- Personal details ------------------------------------------------ --}}
    <section class="erp-card erp-wizard__card">
        <header class="erp-card__head">
            <span class="erp-card__num">01</span>
            <div>
                <h3 class="erp-card__title">Personal details</h3>
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
                    <input class="erp-input" id="phone" name="phone" value="{{ $value('phone') }}" maxlength="30" inputmode="tel" autocomplete="tel"
                           data-erp-self="phone">
                    <p class="erp-error">@error('phone'){{ $message }}@enderror</p>
                </div>
                <div class="erp-field erp-col-3">
                    <label class="erp-label" for="alternate_phone">Alternate number</label>
                    <input class="erp-input" id="alternate_phone" name="alternate_phone" value="{{ $value('alternate_phone') }}" maxlength="30" inputmode="tel">
                    <p class="erp-error">@error('alternate_phone'){{ $message }}@enderror</p>
                </div>
                <div class="erp-field erp-col-6">
                    <label class="erp-label" for="email">Email</label>
                    <input class="erp-input" id="email" name="email" type="email" value="{{ $value('email') }}" maxlength="255" autocomplete="email" data-erp-self="email">
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
        </div>
    </section>

    {{-- --- Photographs and signature --------------------------------------- --}}
    <section class="erp-card erp-wizard__card">
        <header class="erp-card__head">
            <span class="erp-card__num">02</span>
            <div>
                <h3 class="erp-card__title">Photographs &amp; signature</h3>
                <p class="erp-card__desc">JPG, PNG or WebP · up to 2 MB each. Stored privately.</p>
            </div>
        </header>

        <div class="erp-card__body">
            <div class="erp-media-grid">
                @foreach($media as $field => $meta)
                    @php $stored = $value($meta['column']); @endphp
                    <div class="erp-media">
                        <span class="erp-media__label">{{ $meta['label'] }}</span>
                        <div class="erp-media__preview" id="preview-{{ $field }}">
                            @if($stored)
                                <img src="{{ route('students.registration.image', ['field' => $field]) }}" alt="{{ $meta['label'] }}">
                            @else
                                <span class="erp-media__empty">No file yet</span>
                            @endif
                        </div>
                        <input type="file" id="{{ $field }}" name="{{ $field }}"
                               accept="image/jpeg,image/png,image/webp"
                               aria-label="{{ $meta['label'] }}"
                               data-erp-image-input="preview-{{ $field }}">
                        @if($stored)
                            <label class="erp-check erp-media__remove">
                                <input type="checkbox" name="remove_{{ $field }}" value="1"> Remove
                            </label>
                        @endif
                        <p class="erp-error">@error($field){{ $message }}@enderror</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- --- Permanent address ---------------------------------------------- --}}
    <div class="erp-address">
        <h3 class="erp-address__title">Permanent address</h3>
        <p class="erp-address__note">
            @if(in_array(true, $addressLevels, true))
                Country, state, district, block and village are linked: changing one clears the levels below it.
            @else
                No geography directory is loaded for this college yet, so the address is entered as text.
            @endif
        </p>

        <div class="erp-grid">
            @php
                $levels = [
                    ['level' => 'country', 'id' => 'country_id', 'text' => 'country', 'label' => 'Country'],
                    ['level' => 'state', 'id' => 'state_id', 'text' => 'state', 'label' => 'State'],
                    ['level' => 'district', 'id' => 'district_id', 'text' => 'district', 'label' => 'District'],
                    ['level' => 'block', 'id' => 'block_id', 'text' => 'block_tehsil', 'label' => 'Block / Tehsil'],
                    ['level' => 'village', 'id' => 'village_id', 'text' => 'city', 'label' => 'Village / City'],
                ];
            @endphp

            @foreach($levels as $entry)
                <div class="erp-field erp-col-3">
                    @if($addressLevels[$entry['level']])
                        <label class="erp-label" for="{{ $entry['id'] }}">{{ $entry['label'] }}</label>
                        <select class="erp-input" id="{{ $entry['id'] }}" name="{{ $entry['id'] }}"
                                data-geo-level="{{ $entry['level'] }}"
                                data-empty-label="— Select {{ strtolower($entry['label']) }} —"
                                @if($entry['level'] !== 'country' && blank($value($entry['id']))) disabled @endif>
                            <option value="">— Select {{ strtolower($entry['label']) }} —</option>
                            @foreach($geoOptions[$entry['level']] as $option)
                                <option value="{{ $option->id }}" @selected((string) $value($entry['id']) === (string) $option->id)>{{ $option->name }}</option>
                            @endforeach
                        </select>
                        <p class="erp-error">@error($entry['id']){{ $message }}@enderror</p>
                    @else
                        <label class="erp-label" for="{{ $entry['text'] }}">{{ $entry['label'] }}</label>
                        <input class="erp-input" id="{{ $entry['text'] }}" name="{{ $entry['text'] }}"
                               value="{{ $value($entry['text']) }}" maxlength="{{ $entry['level'] === 'village' ? 100 : 100 }}">
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

    {{-- --- Correspondence address ----------------------------------------- --}}
    @php
        $sameAsPermanent = old('correspondence_same_as_permanent', $stepData['correspondence_same_as_permanent'] ?? true);
        $sameAsPermanent = filter_var($sameAsPermanent, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
    @endphp

    <div class="erp-address">
        <div class="erp-address__head">
            <h3 class="erp-address__title">Correspondence address</h3>
        </div>
        <p class="erp-address__note">
            <label class="erp-checkline">
                <input type="hidden" name="correspondence_same_as_permanent" value="0">
                <input type="checkbox" name="correspondence_same_as_permanent" value="1"
                       data-erp-same-address @checked($sameAsPermanent)>
                Same as permanent address
            </label>
        </p>

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
    </div>

    @include('students.registration._actions', ['step' => 1, 'draftId' => $draftId, 'submitMode' => false])
</form>
