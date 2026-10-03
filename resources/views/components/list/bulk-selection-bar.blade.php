@props([
    'module',
    'endpoint' => null,
    'exportRoute' => null,
])

@php
    $actionUrl = $endpoint ?? route('bulk-actions.execute');
@endphp

<div
    data-bulk-selection
    data-module="{{ $module }}"
    data-endpoint="{{ $actionUrl }}"
    class="my-3 hidden rounded-xl border border-indigo-200 bg-indigo-50/70 p-3 text-sm text-indigo-950 transition-all shadow-sm"
>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-3">
            <span class="inline-flex items-center gap-1.5 font-semibold text-indigo-900">
                <span data-selected-count class="inline-flex h-6 min-w-[1.5rem] items-center justify-center rounded-full bg-indigo-600 px-2 text-xs font-bold text-white">0</span>
                <span>selected</span>
            </span>
            <button
                type="button"
                data-bulk-clear
                class="text-xs font-semibold text-indigo-700 underline hover:text-indigo-900 transition"
            >
                Clear selection
            </button>
        </div>

        <div class="flex flex-wrap items-center gap-2" data-bulk-actions-container>
            {{ $slot }}
        </div>
    </div>
</div>
