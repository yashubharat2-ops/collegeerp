@props([
    'name',
    'label' => null,
    'value' => null,
    'placeholder' => null,
    'type' => 'text',
])

@php
    /*
     * A single-line text filter (a contains-match on one column).
     *
     * This completes the kit: filter-select, filter-date, filter-date-range and
     * filter-boolean all existed, but there was no text field, so a text filter
     * had to be hand-written in each view (which is what the sibling student
     * screens do). It is deliberately not <x-list.search-input>: that component
     * carries a magnifying-glass icon and type="search", so using it for
     * "Nationality" or "Mother Tongue" would present a plain field as a search
     * box — the same mislabelling this kit exists to avoid.
     *
     * A malformed query string (e.g. ?nationality[]=nested) delivers an array
     * where a single-value control expects one value, and casting that to string
     * is a fatal "Array to string conversion". The value is normalised here, at
     * the point where it would become markup: a non-scalar reads as empty,
     * exactly as the server-side filter treated it.
     */
    $requested = $value ?? request($name, '');
    $current = is_scalar($requested) ? (string) $requested : '';
@endphp

<div class="erp-list-field">
    @if($label)
        <label for="filter-{{ $name }}" class="mb-1 block text-xs font-semibold text-slate-700">{{ $label }}</label>
    @endif
    <input
        type="{{ $type }}"
        id="filter-{{ $name }}"
        name="{{ $name }}"
        value="{{ $current }}"
        @if($placeholder !== null) placeholder="{{ $placeholder }}" @endif
        autocomplete="off"
        {{ $attributes->merge(['class' => 'input !py-2 !text-xs']) }}
    >
</div>
