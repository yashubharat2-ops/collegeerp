@props([
    'name',
    'label' => null,
    'value' => null,
    'placeholder' => 'All',
    'trueLabel' => 'Yes',
    'falseLabel' => 'No',
])

@php
    // A malformed (array) query value reads as empty, so it can select neither
    // option and cannot reach a string cast: see filter-select.blade.php.
    $requested = $value ?? request($name, '');
    $val = is_scalar($requested) ? (string) $requested : '';
@endphp

<div class="erp-list-field">
    @if($label)
        <label for="filter-{{ $name }}" class="mb-1 block text-xs font-semibold text-slate-700">{{ $label }}</label>
    @endif
    <select
        id="filter-{{ $name }}"
        name="{{ $name }}"
        {{ $attributes->merge(['class' => 'input !py-2 !text-xs']) }}
    >
        @if($placeholder !== null)
            <option value="">{{ $placeholder }}</option>
        @endif
        <option value="1" @selected((string)$val === '1' || (string)$val === 'true')>{{ $trueLabel }}</option>
        <option value="0" @selected((string)$val === '0' || (string)$val === 'false')>{{ $falseLabel }}</option>
    </select>
</div>
