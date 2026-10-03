@props([
    'name',
    'label' => null,
    'value' => null,
])

@php
    $val = $value ?? request($name, '');
@endphp

<div>
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
