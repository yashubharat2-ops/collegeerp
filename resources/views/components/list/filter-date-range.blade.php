@props([
    'fromName' => 'from_date',
    'toName' => 'to_date',
    'label' => 'Date range',
    'fromValue' => null,
    'toValue' => null,
])

@php
    // Malformed (array) query values read as empty instead of being rendered
    // into the value attributes: see filter-select.blade.php for the rule.
    $fromRequested = $fromValue ?? request($fromName, '');
    $toRequested = $toValue ?? request($toName, '');
    $fromVal = is_scalar($fromRequested) ? (string) $fromRequested : '';
    $toVal = is_scalar($toRequested) ? (string) $toRequested : '';
@endphp

<div>
    @if($label)
        <span class="mb-1 block text-xs font-semibold text-slate-700">{{ $label }}</span>
    @endif
    <div class="flex items-center gap-2">
        <input
            type="date"
            name="{{ $fromName }}"
            value="{{ $fromVal }}"
            placeholder="From"
            aria-label="From date"
            {{ $attributes->merge(['class' => 'input !py-2 !text-xs']) }}
        >
        <span class="text-xs text-slate-400">to</span>
        <input
            type="date"
            name="{{ $toName }}"
            value="{{ $toVal }}"
            placeholder="To"
            aria-label="To date"
            {{ $attributes->merge(['class' => 'input !py-2 !text-xs']) }}
        >
    </div>
</div>
