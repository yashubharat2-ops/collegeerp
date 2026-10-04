@props([
    'name',
    'label' => null,
    'value' => null,
])

@php
    // A malformed (array) query value reads as empty instead of being rendered
    // into the value attribute: see filter-select.blade.php for the rule.
    $requested = $value ?? request($name, '');
    $val = is_scalar($requested) ? (string) $requested : '';
@endphp

<div class="erp-list-field">
    @if($label)
        <label for="filter-{{ $name }}" class="mb-1 block text-xs font-semibold text-slate-700">{{ $label }}</label>
    @endif
    <input
        type="date"
        id="filter-{{ $name }}"
        name="{{ $name }}"
        value="{{ $val }}"
        {{ $attributes->merge(['class' => 'input !py-2 !text-xs']) }}
    >
</div>
