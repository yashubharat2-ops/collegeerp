@props([
    'fields' => [],
    'options' => [],
    'values' => [],
    'applied' => [],
    'action',
    'resetUrl' => null,
    'preserve' => [],
    'title' => 'Filter Students',
    'submitDisabled' => false,
    'identityFields' => null,
    'identityAction' => null,
    'identityReturnTo' => null,
    'identityActive' => null,
    'identityToken' => null,
])

@php
    /*
     * Compact canonical Student Filter.
     *
     * The panel renders ONLY the fields the caller passes, in the caller's order:
     * REG.NO, NAME OF STUDENT, STUDENT STATUS, ACADEMIC STATUS, Academic year,
     * Department, PROGRAM / CLASS, SEMESTER / SECTION, AADHAAR NUMBER,
     * ABC ID / APAAR ID, and REG. DATE. A `date_range` field is always moved to
     * the very end, so the date range keeps the last position whatever a module
     * lists. Every field definition still carries `help`, but it is rendered
     * screen-reader-only: it documents the control without adding rows of text.
     *
     * Identity search (Aadhaar / ABC ID) — permission-gated and never in the URL.
     *
     * These two controls post to a DIFFERENT endpoint (StudentIdentityFilterController)
     * so the value is stored server-side behind an opaque token instead of riding
     * in a query string. HTML forbids nested forms, so the inputs sit in the GET
     * grid (keeping the single, ordered, responsive layout) and are attached to
     * the CSRF-protected POST form below with their `form` attribute. Submitting
     * either input — or the "Apply identity search" button, which is bound the
     * same way — posts only the identity form; the ordinary controls stay in the
     * GET form.
     */
    $identityFields = is_array($identityFields) ? $identityFields : [];
    $identityToken = $identityToken ?? null;
    $identityActive = (bool) ($identityActive ?? ($identityToken !== null));
    $identityFormId = 'student-identity-filter';
    $identityClearUrl = request()->fullUrlWithoutQuery(['identity', 'page']);

    // Smaller single-value filters first, the date range last. `unavailable`
    // fields (documented schema gaps) are never drawn.
    $gridFields = [];
    $rangeFields = [];
    foreach ($fields as $fieldKey => $field) {
        $type = $field['type'] ?? 'text';
        if ($type === 'unavailable') {
            continue;
        }
        if ($type === 'date_range') {
            $rangeFields[$fieldKey] = $field;
        } else {
            $gridFields[$fieldKey] = $field;
        }
    }
@endphp

{{--
    Native details/summary supplies keyboard-accessible collapse behavior without
    making JavaScript a prerequisite. The panel starts collapsed; active criteria
    remain visible in the server-rendered summary below it.
--}}
<details class="erp-student-filter mt-5 rounded-xl border border-slate-200 bg-slate-50/70" data-student-filter>
    <summary class="flex cursor-pointer list-none items-center justify-between gap-3 rounded-xl px-3 py-2 text-sm font-semibold text-slate-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500" aria-label="Toggle {{ $title }}">
        <span class="inline-flex items-center gap-2">
            <svg aria-hidden="true" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7" class="h-4 w-4 text-indigo-600">
                <path d="M3 5h14M6 10h8m-5 5h2" stroke-linecap="round" />
            </svg>
            {{ $title }}
        </span>
        <span class="text-xs font-medium text-slate-500">
            {{ count($applied) }} applied · <span aria-hidden="true">show / hide</span>
        </span>
    </summary>

    <form method="GET" action="{{ $action }}" class="border-t border-slate-200 p-3" data-student-filter-form>
        @foreach($preserve as $name => $value)
            @if(is_scalar($value) && (string) $value !== '')
                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
            @endif
        @endforeach

        @if($identityActive)
            {{-- Only the opaque token is carried; the sensitive value stays in the
                 server-side vault. --}}
            <input type="hidden" name="identity" value="{{ $identityToken }}">
        @endif

        <div class="erp-list-grid">
            @foreach($gridFields as $fieldKey => $field)
                @php
                    $requestName = $field['request'] ?? $fieldKey;
                    $type = $field['type'] ?? 'text';
                    $current = $values[$fieldKey] ?? null;
                    $choices = isset($field['options']) ? ($options[$field['options']] ?? []) : [];
                    $helpId = 'filter-'.$requestName.'-help';
                @endphp

                <div class="erp-list-field">
                    <label for="filter-{{ $requestName }}" class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-slate-600">{{ $field['label'] }}</label>

                    @if($type === 'select')
                        <select
                            id="filter-{{ $requestName }}"
                            name="{{ $requestName }}"
                            data-student-filter-dependent="{{ implode(' ', $field['depends_on'] ?? []) }}"
                            @if(! empty($field['help'])) aria-describedby="{{ $helpId }}" @endif
                            class="input !py-1.5 !text-xs"
                        >
                            <option value="">{{ $field['placeholder'] ?? 'All' }}</option>
                            @if($fieldKey === 'academic_context')
                                @foreach(['Semesters', 'Sections'] as $group)
                                    <optgroup label="{{ $group }}">
                                        @foreach($choices as $choice)
                                            @if(($choice['group'] ?? null) === $group)
                                                <option
                                                    value="{{ $choice['value'] }}"
                                                    @selected((string) $current === (string) $choice['value'])
                                                    @foreach(($choice['attributes'] ?? []) as $attribute => $attributeValue) {{ $attribute }}="{{ $attributeValue }}" @endforeach
                                                >{{ $choice['label'] }}</option>
                                            @endif
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            @else
                                @foreach($choices as $choice)
                                    <option
                                        value="{{ $choice['value'] }}"
                                        @selected((string) $current === (string) $choice['value'])
                                        @foreach(($choice['attributes'] ?? []) as $attribute => $attributeValue) {{ $attribute }}="{{ $attributeValue }}" @endforeach
                                    >{{ $choice['label'] }}</option>
                                @endforeach
                            @endif
                        </select>
                    @else
                        <input
                            type="text"
                            id="filter-{{ $requestName }}"
                            name="{{ $requestName }}"
                            value="{{ is_scalar($current) ? $current : '' }}"
                            placeholder="{{ $field['placeholder'] ?? '' }}"
                            autocomplete="off"
                            @if(isset($field['inputmode'])) inputmode="{{ $field['inputmode'] }}" @endif
                            @if(isset($field['maxlength'])) maxlength="{{ $field['maxlength'] }}" @endif
                            @if(! empty($field['help'])) aria-describedby="{{ $helpId }}" @endif
                            class="input !py-1.5 !text-xs"
                        >
                    @endif

                    @if(! empty($field['help']))
                        <p id="{{ $helpId }}" class="sr-only">{{ $field['help'] }}</p>
                    @endif
                </div>
            @endforeach

            @if($identityFields !== [])
                {{-- Bound to the POST form below by `form`; never echoed back, never
                     carried in a URL. --}}
                @foreach($identityFields as $identityKey => $identityField)
                    @php $identityName = $identityField['request'] ?? $identityKey; @endphp
                    <div class="erp-list-field">
                        <label for="filter-identity-{{ $identityKey }}" class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-slate-600">{{ $identityField['label'] }}</label>
                        <input
                            type="password"
                            id="filter-identity-{{ $identityKey }}"
                            name="{{ $identityName }}"
                            form="{{ $identityFormId }}"
                            value=""
                            placeholder="{{ $identityField['placeholder'] ?? '' }}"
                            autocomplete="off"
                            spellcheck="false"
                            @if(isset($identityField['inputmode'])) inputmode="{{ $identityField['inputmode'] }}" @endif
                            @if(isset($identityField['maxlength'])) maxlength="{{ $identityField['maxlength'] }}" @endif
                            aria-describedby="filter-identity-{{ $identityKey }}-help"
                            class="input !py-1.5 !text-xs"
                        >
                        <p id="filter-identity-{{ $identityKey }}-help" class="sr-only">{{ $identityField['help'] ?? 'Authorized roles only. Matched on the server and never shown in the address bar.' }}</p>
                        @error($identityName)
                            {{-- The message names the field only; the value is never
                                 repeated back into the page. --}}
                            <p class="mt-1 text-xs font-semibold text-rose-600">{{ $message }}</p>
                        @enderror
                    </div>
                @endforeach

                <div class="erp-list-field erp-list-actions flex items-end gap-2">
                    <button type="submit" form="{{ $identityFormId }}" class="button !bg-slate-800 !py-1.5 !text-xs font-semibold hover:!bg-slate-900">Apply identity</button>
                    @if($identityActive)
                        <a class="text-xs font-semibold text-indigo-700 underline hover:text-indigo-900" href="{{ $identityClearUrl }}">Clear</a>
                    @endif
                </div>
            @endif

            {{-- Page-level search and module-specific controls (the wider
                 controls) come after every compact single-value filter and
                 within reach of the date range, which is always last. --}}
            {{ $slot }}

            @foreach($rangeFields as $fieldKey => $field)
                <div class="erp-list-filter-span-2">
                    <x-list.filter-date-range
                        :from-name="$field['from_request']"
                        :to-name="$field['to_request']"
                        :label="$field['label']"
                        :from-value="$values['registration_date_from'] ?? null"
                        :to-value="$values['registration_date_to'] ?? null"
                    />
                    @if(! empty($field['help']))
                        <p class="sr-only">{{ $field['help'] }}</p>
                    @endif
                </div>
            @endforeach

            <div class="erp-list-field erp-list-actions flex items-end gap-2">
                <button class="button !py-1.5 !text-xs font-semibold" type="submit" @disabled($submitDisabled)>Apply Filters</button>
                <a class="button !bg-slate-200 !py-1.5 !text-xs font-semibold !text-slate-700 transition hover:!bg-slate-300" href="{{ $resetUrl ?? $action }}">Reset Filters</a>
            </div>
        </div>
    </form>

    @if($identityFields !== [])
        {{-- The Aadhaar / ABC ID controls above belong to THIS form through their
             `form` attribute: a separate, CSRF-protected POST so no identity value
             can ever appear in a URL. --}}
        <form id="{{ $identityFormId }}" method="POST" action="{{ $identityAction }}" class="hidden" aria-hidden="true">
            @csrf
            <input type="hidden" name="return_to" value="{{ $identityReturnTo }}">
            @if($identityToken)
                <input type="hidden" name="identity" value="{{ $identityToken }}">
            @endif
        </form>
    @endif
</details>

@if($applied !== [])
    <div class="mt-3 flex flex-wrap items-center gap-2" aria-label="Applied student filters" aria-live="polite">
        <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">Applied filters:</span>
        @foreach($applied as $criterion)
            <span class="inline-flex max-w-full items-center gap-1 rounded-full border border-indigo-100 bg-indigo-50 px-2.5 py-1 text-xs text-indigo-900">
                <strong>{{ $criterion['label'] }}:</strong>
                <span class="break-words">{{ $criterion['value'] }}</span>
            </span>
        @endforeach
    </div>
@endif
