@props([
    'fields' => [],
    'options' => [],
    'values' => [],
    'applied' => [],
    'action',
    'resetUrl' => null,
    'preserve' => [],
    'title' => 'Filter Student',
    'submitDisabled' => false,
])

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

        <div class="erp-list-grid">
            @foreach($fields as $key => $field)
                @php
                    $requestName = $field['request'] ?? $key;
                    $type = $field['type'] ?? 'text';
                    $value = $values[$key] ?? null;
                    $choices = isset($field['options']) ? ($options[$field['options']] ?? []) : [];
                @endphp

                @if($type === 'date_range')
                    <x-list.filter-date-range
                        class="erp-list-filter-span-2"
                        :from-name="$field['from_request']"
                        :to-name="$field['to_request']"
                        :label="$field['label']"
                        :from-value="$values['registration_date_from'] ?? null"
                        :to-value="$values['registration_date_to'] ?? null"
                    />
                @elseif($type === 'select')
                    <x-list.filter-select
                        :name="$requestName"
                        :label="$field['label']"
                        :value="$value"
                        :placeholder="$field['placeholder'] ?? 'All'"
                    >
                        @if($key === 'academic_context')
                            @foreach(['Semesters', 'Sections'] as $group)
                                <optgroup label="{{ $group }}">
                                    @foreach($choices as $choice)
                                        @if(($choice['group'] ?? null) === $group)
                                            <option value="{{ $choice['value'] }}" @selected((string) $value === (string) $choice['value'])>{{ $choice['label'] }}</option>
                                        @endif
                                    @endforeach
                                </optgroup>
                            @endforeach
                        @else
                            @foreach($choices as $choice)
                                <option value="{{ $choice['value'] }}" @selected((string) $value === (string) $choice['value'])>{{ $choice['label'] }}</option>
                            @endforeach
                        @endif
                    </x-list.filter-select>
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
                <a class="button !bg-slate-200 !text-slate-700 !py-2 !text-xs font-semibold hover:!bg-slate-300 transition" href="{{ $resetUrl ?? $action }}">Reset</a>
            </div>
        </div>
    </form>
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
