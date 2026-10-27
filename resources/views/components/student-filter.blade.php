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
     * Identity search (Aadhaar / ABC ID) — permission-gated and never in the URL.
     *
     * The four identity props come from StudentFilter::viewData(), which every
     * screen using this component already spreads into its view data. A module
     * whose own field subset has no identity fields (the Admission lists, for
     * example) therefore gets an empty list and no sub-form: the control only
     * appears where the canonical filter can actually apply it.
     *
     * The sub-form is a separate POST form because an Aadhaar number or ABC ID
     * must never be written into a URL. The server stores the value behind an
     * opaque token and redirects back with `?identity=<token>` only.
     */
    $identityFields = is_array($identityFields) ? $identityFields : [];
    $identityToken = $identityToken ?? null;
    $identityActive = (bool) ($identityActive ?? ($identityToken !== null));

    // "Clear identity search" keeps every ordinary filter and drops only the token.
    $identityClearUrl = request()->fullUrlWithoutQuery(['identity', 'page']);
@endphp

{{--
    Canonical Student Filter. Native details/summary supplies keyboard-accessible
    collapse behavior without making JavaScript a prerequisite. The panel starts
    collapsed; active criteria remain visible in the server-rendered summary.
--}}
<details class="erp-student-filter mt-6 rounded-2xl border border-slate-200 bg-slate-50/70" data-student-filter>
    <summary class="flex cursor-pointer list-none items-center justify-between gap-3 rounded-2xl px-4 py-3 text-sm font-semibold text-slate-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500" aria-label="Toggle {{ $title }}">
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

    <form method="GET" action="{{ $action }}" class="border-t border-slate-200 p-4" data-student-filter-form>
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
            @foreach($fields as $key => $field)
                @php
                    $requestName = $field['request'] ?? $key;
                    $type = $field['type'] ?? 'text';
                    $value = $values[$key] ?? null;
                    $choices = isset($field['options']) ? ($options[$field['options']] ?? []) : [];
                @endphp

                @if($type === 'date_range')
                    <div class="erp-list-filter-span-2">
                        <x-list.filter-date-range
                            :from-name="$field['from_request']"
                            :to-name="$field['to_request']"
                            :label="$field['label']"
                            :from-value="$values['registration_date_from'] ?? null"
                            :to-value="$values['registration_date_to'] ?? null"
                        />
                        @if(! empty($field['help']))
                            <p class="mt-1 text-xs text-slate-500">{{ $field['help'] }}</p>
                        @endif
                    </div>
                @elseif($type === 'select')
                    <x-list.filter-select
                        :name="$requestName"
                        :label="$field['label']"
                        :value="$value"
                        :placeholder="$field['placeholder'] ?? 'All'"
                        data-student-filter-dependent="{{ implode(' ', $field['depends_on'] ?? []) }}"
                    >
                        @if($key === 'academic_context')
                            @foreach(['Semesters', 'Sections'] as $group)
                                <optgroup label="{{ $group }}">
                                    @foreach($choices as $choice)
                                        @if(($choice['group'] ?? null) === $group)
                                            <option
                                                value="{{ $choice['value'] }}"
                                                @selected((string) $value === (string) $choice['value'])
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
                                    @selected((string) $value === (string) $choice['value'])
                                    @foreach(($choice['attributes'] ?? []) as $attribute => $attributeValue) {{ $attribute }}="{{ $attributeValue }}" @endforeach
                                >{{ $choice['label'] }}</option>
                            @endforeach
                        @endif
                    </x-list.filter-select>
                    @if(! empty($field['help']))
                        <p class="mt-1 text-xs text-slate-500">{{ $field['help'] }}</p>
                    @endif
                @elseif($type === 'unavailable')
                    <div class="erp-list-field">
                        <label for="filter-{{ $requestName }}" class="mb-1 block text-xs font-semibold text-slate-700">{{ $field['label'] }}</label>
                        <select id="filter-{{ $requestName }}" class="input !py-2 !text-xs" disabled aria-describedby="filter-{{ $requestName }}-gap">
                            <option>Not available in current schema</option>
                        </select>
                        <p id="filter-{{ $requestName }}-gap" class="mt-1 text-xs text-slate-500">{{ $field['reason'] }}</p>
                    </div>
                @else
                    <div class="erp-list-field">
                        <label for="filter-{{ $requestName }}" class="mb-1 block text-xs font-semibold text-slate-700">{{ $field['label'] }}</label>
                        <input
                            type="text"
                            id="filter-{{ $requestName }}"
                            name="{{ $requestName }}"
                            value="{{ is_scalar($value) ? $value : '' }}"
                            placeholder="{{ $field['placeholder'] ?? '' }}"
                            autocomplete="off"
                            @if(isset($field['inputmode'])) inputmode="{{ $field['inputmode'] }}" @endif
                            @if(isset($field['maxlength'])) maxlength="{{ $field['maxlength'] }}" @endif
                            @if(($field['sensitive'] ?? false) && isset($field['help'])) aria-describedby="filter-{{ $requestName }}-help" @endif
                            class="input !py-2 !text-xs"
                        >
                        @if(isset($field['help']))
                            <p id="filter-{{ $requestName }}-help" class="mt-1 text-xs text-slate-500">{{ $field['help'] }}</p>
                        @endif
                    </div>
                @endif
            @endforeach

            {{ $slot }}

            <div class="erp-list-field erp-list-actions flex items-end gap-2">
                <button class="button !py-2 !text-xs font-semibold" type="submit" @disabled($submitDisabled)>Apply Filters</button>
                <a class="button !bg-slate-200 !text-slate-700 !py-2 !text-xs font-semibold hover:!bg-slate-300 transition" href="{{ $resetUrl ?? $action }}">Reset Filters</a>
            </div>
        </div>
    </form>

    @if($identityFields !== [])
        {{--
            Identity search: a POST of its own, because an Aadhaar or ABC ID must
            never be placed in a URL. The response redirects back to this page with
            an opaque token, which is the only identity value the address bar sees.
        --}}
        <div class="border-t border-slate-200 p-4" data-student-identity-filter>
            <form method="POST" action="{{ $identityAction }}" class="erp-list-grid">
                @csrf
                <input type="hidden" name="return_to" value="{{ $identityReturnTo }}">
                @if($identityToken)
                    <input type="hidden" name="identity" value="{{ $identityToken }}">
                @endif

                <div class="erp-list-filter-span-2">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Secure identity search</p>
                    <p class="mt-1 text-xs text-slate-500">
                        Authorized roles only. Values are submitted privately, matched on the server and never shown in the address bar.
                        @if($identityActive)
                            <span class="font-semibold text-emerald-700">An identity search is currently applied.</span>
                        @endif
                    </p>
                </div>

                @foreach($identityFields as $identityKey => $identityField)
                    <div class="erp-list-field">
                        <label for="filter-identity-{{ $identityKey }}" class="mb-1 block text-xs font-semibold text-slate-700">{{ $identityField['label'] }}</label>
                        <input
                            type="password"
                            id="filter-identity-{{ $identityKey }}"
                            name="{{ $identityField['request'] ?? $identityKey }}"
                            value=""
                            placeholder="{{ $identityField['placeholder'] ?? '' }}"
                            autocomplete="off"
                            spellcheck="false"
                            @if(isset($identityField['inputmode'])) inputmode="{{ $identityField['inputmode'] }}" @endif
                            @if(isset($identityField['maxlength'])) maxlength="{{ $identityField['maxlength'] }}" @endif
                            aria-describedby="filter-identity-{{ $identityKey }}-help"
                            class="input !py-2 !text-xs"
                        >
                        @if(isset($identityField['help']))
                            <p id="filter-identity-{{ $identityKey }}-help" class="mt-1 text-xs text-slate-500">{{ $identityField['help'] }}</p>
                        @endif
                        @error($identityField['request'] ?? $identityKey)
                            <p class="mt-1 text-xs font-semibold text-rose-600">{{ $message }}</p>
                        @enderror
                    </div>
                @endforeach

                <div class="erp-list-field erp-list-actions flex items-end gap-2">
                    <button class="button !py-2 !text-xs font-semibold !bg-slate-800 hover:!bg-slate-900" type="submit">Apply identity search</button>
                    @if($identityActive)
                        <a class="button !bg-slate-200 !text-slate-700 !py-2 !text-xs font-semibold hover:!bg-slate-300 transition" href="{{ $identityClearUrl }}">Clear identity search</a>
                    @endif
                </div>
            </form>
        </div>
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
