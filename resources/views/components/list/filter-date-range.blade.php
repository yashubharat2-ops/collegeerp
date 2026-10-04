@props([
    'fromName' => 'from_date',
    'toName' => 'to_date',
    'label' => 'Date range',
    'fromValue' => null,
    'toValue' => null,
])

@php
    // Query parameters stay in the list pipeline's canonical Y-m-d format. The
    // visible inputs use DD/MM/YYYY and are translated back by erp-list.js.
    // Invalid/malformed query values are shown as empty, just like other shared
    // list controls.
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

    $fromRequested = $fromValue ?? request($fromName, '');
    $toRequested = $toValue ?? request($toName, '');
    $fromVal = $canonicalDate($fromRequested);
    $toVal = $canonicalDate($toRequested);
    $toDisplayDate = static fn (string $date): string => $date === ''
        ? ''
        : substr($date, 8, 2).'/'.substr($date, 5, 2).'/'.substr($date, 0, 4);
@endphp

<div class="erp-list-field erp-list-date-range" data-list-date-range>
    @if($label)
        <span class="mb-1 block text-xs font-semibold text-slate-700">{{ $label }}</span>
    @endif
    <div class="erp-list-controls flex items-end gap-2">
        <div class="erp-list-date-part">
            <label for="filter-display-{{ $fromName }}" class="mb-1 block text-[10px] font-semibold text-slate-500">From</label>
            <input
                type="text"
                id="filter-display-{{ $fromName }}"
                data-list-date-display
                data-list-date-target="filter-{{ $fromName }}"
                value="{{ $toDisplayDate($fromVal) }}"
                placeholder="DD/MM/YYYY"
                aria-label="From date, DD/MM/YYYY"
                inputmode="numeric"
                autocomplete="off"
                pattern="[0-9]{2}/[0-9]{2}/[0-9]{4}"
                title="Enter the date as DD/MM/YYYY"
                {{ $attributes->merge(['class' => 'input !py-2 !text-xs']) }}
            >
            <input
                type="hidden"
                id="filter-{{ $fromName }}"
                data-list-date-value
                name="{{ $fromName }}"
                value="{{ $fromVal }}"
            >
        </div>
        <div class="erp-list-date-part">
            <label for="filter-display-{{ $toName }}" class="mb-1 block text-[10px] font-semibold text-slate-500">To</label>
            <input
                type="text"
                id="filter-display-{{ $toName }}"
                data-list-date-display
                data-list-date-target="filter-{{ $toName }}"
                value="{{ $toDisplayDate($toVal) }}"
                placeholder="DD/MM/YYYY"
                aria-label="To date, DD/MM/YYYY"
                inputmode="numeric"
                autocomplete="off"
                pattern="[0-9]{2}/[0-9]{2}/[0-9]{4}"
                title="Enter the date as DD/MM/YYYY"
                {{ $attributes->merge(['class' => 'input !py-2 !text-xs']) }}
            >
            <input
                type="hidden"
                id="filter-{{ $toName }}"
                data-list-date-value
                name="{{ $toName }}"
                value="{{ $toVal }}"
            >
        </div>
    </div>
</div>
