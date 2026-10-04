@props([
    'fromName' => 'from_date',
    'toName' => 'to_date',
    'label' => 'Date range',
    'fromValue' => null,
    'toValue' => null,
])

@php
    // Keep the list query parameters canonical (Y-m-d). The visible text inputs
    // are localized to DD/MM/YYYY and erp-list.js maps between those formats.
    // Invalid or malformed request values are rendered empty; no date is ever
    // inferred or defaulted here.
    $canonicalDate = static function ($value): string {
        if (! is_scalar($value)) {
            return '';
        }

        $date = trim((string) $value);
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $parts)) {
            return '';
        }

        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) ? $date : '';
    };

    $fromVal = $canonicalDate($fromValue ?? request($fromName, ''));
    $toVal = $canonicalDate($toValue ?? request($toName, ''));
    $toDisplayDate = static fn (string $date): string => $date === ''
        ? ''
        : substr($date, 8, 2).'/'.substr($date, 5, 2).'/'.substr($date, 0, 4);

    $dateFields = [
        ['label' => 'From', 'edge' => 'from', 'name' => $fromName, 'value' => $fromVal, 'align' => 'start'],
        ['label' => 'To', 'edge' => 'to', 'name' => $toName, 'value' => $toVal, 'align' => 'end'],
    ];
@endphp

<div {{ $attributes->merge(['class' => 'erp-list-field erp-list-date-range']) }} data-list-date-range role="group" aria-label="{{ $label }}">
    @if($label)
        <span class="mb-1 block text-xs font-semibold text-slate-700">{{ $label }}</span>
    @endif

    <div class="erp-list-controls erp-list-date-controls">
        @foreach($dateFields as $dateField)
            <div class="erp-list-date-part" data-erp-date-picker data-date-picker-align="{{ $dateField['align'] }}" data-date-picker-label="{{ $dateField['label'] }}">
                <div class="erp-list-date-control">
                    <label for="filter-display-{{ $dateField['name'] }}" class="erp-list-date-prefix">{{ $dateField['label'] }}</label>
                    <input
                        type="text"
                        id="filter-display-{{ $dateField['name'] }}"
                        data-list-date-display
                        data-list-date-edge="{{ $dateField['edge'] }}"
                        data-list-date-target="filter-{{ $dateField['name'] }}"
                        value="{{ $toDisplayDate($dateField['value']) }}"
                        placeholder="DD/MM/YYYY"
                        aria-label="{{ $dateField['label'] }} date, DD/MM/YYYY"
                        aria-haspopup="dialog"
                        aria-controls="filter-calendar-{{ $dateField['name'] }}"
                        aria-expanded="false"
                        inputmode="numeric"
                        autocomplete="off"
                        pattern="[0-9]{2}/[0-9]{2}/[0-9]{4}"
                        title="Enter a date as DD/MM/YYYY"
                        class="input !py-2 !pl-12 !pr-11 !text-xs"
                    >
                    <button
                        type="button"
                        class="erp-list-date-picker-trigger"
                        data-date-picker-toggle
                        aria-label="Open {{ strtolower($dateField['label']) }} date calendar"
                        aria-haspopup="dialog"
                        aria-controls="filter-calendar-{{ $dateField['name'] }}"
                        aria-expanded="false"
                    >
                        <svg aria-hidden="true" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" class="h-4 w-4">
                            <rect x="2.75" y="4.25" width="14.5" height="13" rx="2" />
                            <path d="M6.5 2.75v3M13.5 2.75v3M3 8h14" />
                        </svg>
                    </button>
                </div>
                <input
                    type="hidden"
                    id="filter-{{ $dateField['name'] }}"
                    data-list-date-value
                    name="{{ $dateField['name'] }}"
                    value="{{ $dateField['value'] }}"
                >

                <div
                    class="erp-list-date-picker-popover"
                    id="filter-calendar-{{ $dateField['name'] }}"
                    data-date-picker-popover
                    role="dialog"
                    aria-modal="false"
                    aria-label="{{ $label }} {{ strtolower($dateField['label']) }} date picker"
                    aria-labelledby="filter-calendar-title-{{ $dateField['name'] }}"
                    hidden
                >
                    <div class="erp-list-date-picker-header">
                        <button type="button" data-date-picker-prev aria-label="Previous month" title="Previous month">
                            <svg aria-hidden="true" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4">
                                <path d="m12.5 4.5-5.5 5.5 5.5 5.5" />
                            </svg>
                        </button>
                        <h3 id="filter-calendar-title-{{ $dateField['name'] }}" data-date-picker-title aria-live="polite"></h3>
                        <button type="button" data-date-picker-next aria-label="Next month" title="Next month">
                            <svg aria-hidden="true" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4">
                                <path d="m7.5 4.5 5.5 5.5-5.5 5.5" />
                            </svg>
                        </button>
                    </div>

                    <table class="erp-list-date-picker-calendar" role="grid" data-date-picker-calendar aria-labelledby="filter-calendar-title-{{ $dateField['name'] }}">
                        <thead>
                            <tr>
                                <th scope="col" aria-label="Sunday">Su</th>
                                <th scope="col" aria-label="Monday">Mo</th>
                                <th scope="col" aria-label="Tuesday">Tu</th>
                                <th scope="col" aria-label="Wednesday">We</th>
                                <th scope="col" aria-label="Thursday">Th</th>
                                <th scope="col" aria-label="Friday">Fr</th>
                                <th scope="col" aria-label="Saturday">Sa</th>
                            </tr>
                        </thead>
                        <tbody data-date-picker-days></tbody>
                    </table>

                    <div class="erp-list-date-picker-footer">
                        <button type="button" data-date-picker-clear>Clear date</button>
                        <button type="button" data-date-picker-close>Close calendar</button>
                    </div>
                </div>
            </div>

            @unless($loop->last)
                <span class="erp-list-date-separator" aria-hidden="true">to</span>
            @endunless
        @endforeach
    </div>
</div>
