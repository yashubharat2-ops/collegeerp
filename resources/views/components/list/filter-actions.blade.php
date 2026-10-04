@props([
    'clearUrl' => null,
    'hasFilters' => false,
    'submitLabel' => 'Filter',
    'clearLabel' => 'Clear',
])

<div {{ $attributes->merge(['class' => 'erp-list-field erp-list-actions flex items-end gap-2']) }}>
    <button class="button !py-2 !text-xs font-semibold" type="submit">{{ $submitLabel }}</button>
    @if($hasFilters && $clearUrl)
        <a class="button !bg-slate-200 !text-slate-700 !py-2 !text-xs font-semibold hover:!bg-slate-300 transition" href="{{ $clearUrl }}">{{ $clearLabel }}</a>
    @endif
</div>
