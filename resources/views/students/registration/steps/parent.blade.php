@php
    /*
     * STEP 2 — Parent / Guardian.
     *
     * Father and mother are captured as their own records; the guardian block
     * is separate and is DERIVED (server-side, in RegistrationStepRules) when the
     * relation is Father, Mother or Self. Choosing "Other / legal guardian"
     * preserves whatever is currently shown and makes the fields editable, so a
     * switch never loses data.
     *
     * The guardian photographs were captured on step 1 and are linked, not
     * re-uploaded — one file per person, owned by the same student record.
     */
    $value = fn (string $key) => old($key, $stepData[$key] ?? $merged[$key] ?? null);
    $selfName = trim(($merged['first_name'] ?? '').' '.($merged['middle_name'] ?? '').' '.($merged['last_name'] ?? ''));
    $relation = $value('guardian_relation');
@endphp

<form id="erp-step-form" data-erp-step-form method="POST" action="{{ route('students.registration.step.save', ['step' => 2]) }}" novalidate>
    @csrf
    <input type="hidden" name="draft_id" value="{{ $draftId }}">

    {{-- Values the "Self" auto-fill copies from; never submitted. --}}
    <input type="hidden" data-erp-self="name" value="{{ $selfName }}">
    <input type="hidden" data-erp-self="phone" value="{{ $merged['phone'] ?? '' }}">
    <input type="hidden" data-erp-self="email" value="{{ $merged['email'] ?? '' }}">

    @foreach([
        'father' => ['label' => 'Father', 'num' => '01', 'photo' => 'father_photo_path'],
        'mother' => ['label' => 'Mother', 'num' => '02', 'photo' => 'mother_photo_path'],
    ] as $person => $meta)
        <section class="erp-card erp-wizard__card">
            <header class="erp-card__head">
                <span class="erp-card__num">{{ $meta['num'] }}</span>
                <div>
                    <h3 class="erp-card__title">{{ $meta['label'] }}'s details</h3>
                    <p class="erp-card__desc">Kept separately from the guardian block.</p>
                </div>
            </header>

            <div class="erp-card__body">
                @if(! empty($merged[$meta['photo']]))
                    <p class="erp-help">
                        Photograph attached in Step 1.
                        <a href="{{ route('students.registration.step', ['step' => 1]) }}">Change</a>
                    </p>
                @endif

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
        </section>
    @endforeach

    <section class="erp-card erp-wizard__card">
        <header class="erp-card__head">
            <span class="erp-card__num">03</span>
            <div>
                <h3 class="erp-card__title">Guardian</h3>
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
                           @if(in_array($relation, $derivedRelations, true)) readonly tabindex="-1" @endif>
                    <p class="erp-error">@error('guardian_name'){{ $message }}@enderror</p>
                </div>
                <div class="erp-field erp-col-3">
                    <label class="erp-label" for="guardian_phone">Guardian's mobile</label>
                    <input class="erp-input" id="guardian_phone" name="guardian_phone" value="{{ $value('guardian_phone') }}"
                           maxlength="30" inputmode="tel" data-erp-guardian="phone"
                           @if(in_array($relation, $derivedRelations, true)) readonly tabindex="-1" @endif>
                    <p class="erp-error">@error('guardian_phone'){{ $message }}@enderror</p>
                </div>
                <div class="erp-field erp-col-3">
                    <label class="erp-label" for="guardian_email">Guardian's email</label>
                    <input class="erp-input" id="guardian_email" name="guardian_email" type="email" value="{{ $value('guardian_email') }}"
                           maxlength="255" data-erp-guardian="email"
                           @if(in_array($relation, $derivedRelations, true)) readonly tabindex="-1" @endif>
                    <p class="erp-error">@error('guardian_email'){{ $message }}@enderror</p>
                </div>
                <div class="erp-field erp-col-4">
                    <label class="erp-label" for="guardian_occupation">Guardian's occupation</label>
                    <input class="erp-input" id="guardian_occupation" name="guardian_occupation" value="{{ $value('guardian_occupation') }}"
                           maxlength="150" data-erp-guardian="occupation"
                           @if(in_array($relation, $derivedRelations, true)) readonly tabindex="-1" @endif>
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

    @include('students.registration._actions', ['step' => 2, 'draftId' => $draftId, 'submitMode' => false])
</form>
