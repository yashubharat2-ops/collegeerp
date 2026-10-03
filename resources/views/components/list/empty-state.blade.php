@props([
    'colspan' => 1,
    'message' => null,
    'filteredMessage' => 'No records found matching your active filters.',
    'defaultMessage' => 'No records found.',
    'clearUrl' => null,
    'hasFilters' => false,
])

@php
    $text = $message ?? ($hasFilters ? $filteredMessage : $defaultMessage);
@endphp

<tr>
    <td colspan="{{ $colspan }}" class="py-12 text-center text-slate-500">
        <div class="mx-auto flex max-w-sm flex-col items-center justify-center">
            <div class="mb-3 rounded-full bg-slate-100 p-3 text-slate-400">
                <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
            </div>
            <p class="text-sm font-medium text-slate-700">{{ $text }}</p>
            @if($hasFilters && $clearUrl)
                <p class="mt-1 text-xs text-slate-400">Try adjusting your search or filter keywords.</p>
                <a href="{{ $clearUrl }}" class="mt-3 inline-block rounded-xl border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-sm hover:bg-slate-50 transition">
                    Clear Filters
                </a>
            @endif
        </div>
    </td>
</tr>
