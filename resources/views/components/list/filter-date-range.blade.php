@props([
    'fromName' => 'from_date',
    'toName' => 'to_date',
    'label' => 'Date range',
    'fromValue' => null,
    'toValue' => null,
])

@php
    $fromVal = $fromValue ?? request($fromName, '');
    $toVal = $toValue ?? request($toName, '');
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
