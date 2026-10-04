@props([
    'field',
    'label',
    'context' => null,
])

@php
    // Sort state is also normalised: a malformed query string may deliver an
    // array (`?direction[]=asc`), and strtolower() on an array is a TypeError.
    $requestedField = request('sort');
    $requestedDir = request('direction', '');
    $currentField = is_string($requestedField) ? $requestedField : '';
    $currentDir = is_string($requestedDir) ? strtolower($requestedDir) : '';
    $isActive = $currentField === $field;
    $nextDir = ($isActive && $currentDir === 'asc') ? 'desc' : 'asc';

    $queryParams = request()->query();
    $queryParams['sort'] = $field;
    $queryParams['direction'] = $nextDir;
    unset($queryParams['page']);

    $url = url()->current() . '?' . http_build_query($queryParams);
@endphp

<a href="{{ $url }}" {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 font-semibold text-slate-700 hover:text-indigo-600 transition group']) }}>
    <span>{{ $label ?? $slot }}</span>
    <span class="inline-flex flex-col text-[10px] leading-[8px] {{ $isActive ? 'text-indigo-600' : 'text-slate-300 group-hover:text-slate-400' }}">
        @if($isActive && $currentDir === 'asc')
            ▲
        @elseif($isActive && $currentDir === 'desc')
            ▼
        @else
            <span class="opacity-40">▲</span>
            <span class="opacity-40">▼</span>
        @endif
    </span>
</a>
