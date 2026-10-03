@props([
    'id' => 'select-all-checkbox',
])

<input
    type="checkbox"
    id="{{ $id }}"
    data-select-all
    title="Select all on this page"
    aria-label="Select all on this page"
    class="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 cursor-pointer transition"
    {{ $attributes }}
>
