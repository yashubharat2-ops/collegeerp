@props([
    'name',
    'label' => null,
    'value' => null,
    'placeholder' => 'All',
])

@php
    $selected = (string) ($value ?? request($name, ''));
@endphp

<div>
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
