@props([
    'placeholder' => 'Search...',
    'name' => 'search',
    'value' => null,
])

@php
    $val = $value ?? request($name, '');
@endphp

<div class="relative w-full">
    <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400">
        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
        </svg>
    </div>
    <input
        type="search"
        name="{{ $name }}"
        value="{{ $val }}"
        placeholder="{{ $placeholder }}"
        {{ $attributes->merge(['class' => 'input !pl-9']) }}
    >
</div>
