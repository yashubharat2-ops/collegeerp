@props([
    'id',
    'value' => null,
])

@php
    $rowId = $value ?? $id;
@endphp

<input
    type="checkbox"
    data-select-row
    value="{{ $rowId }}"
    aria-label="Select row {{ $rowId }}"
    class="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 cursor-pointer transition"
    {{ $attributes }}
>
