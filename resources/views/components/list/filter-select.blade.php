@props([
    'name',
    'label' => null,
    'value' => null,
    'placeholder' => 'All',
])

@php
    /*
     * A malformed query string (e.g. ?department_id[]=nested) delivers an array
     * where a single-value control expects one value, and casting that to string
     * is a fatal "Array to string conversion". The value is normalised here, at
     * the point where it would become markup: a non-scalar reads as "nothing
     * selected", exactly as the server-side filter treated it.
     */
    $requested = $value ?? request($name, '');
    $selected = is_scalar($requested) ? (string) $requested : '';
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
        {{ $slot }}
    </select>
</div>
