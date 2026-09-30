@props(['name' => 'grid'])
<svg {{ $attributes->class(['h-[18px] w-[18px] shrink-0']) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    @switch($name)
        @case('dashboard')
            <rect x="3" y="3" width="18" height="18" rx="2" /><path d="M3 10h18M10 10v11" />
            @break
        @case('people')
            <circle cx="9" cy="8" r="3" /><path d="M3 20v-2a6 6 0 0 1 12 0v2H3ZM16 5a3 3 0 0 1 0 6m2 3a5 5 0 0 1 3 5v1h-3" />
            @break
        @case('admissions')
            <circle cx="9" cy="8" r="3" /><path d="M3 20v-2a6 6 0 0 1 12 0v2H3Zm15-12v6m-3-3h6" />
            @break
        @case('students')
            <path d="m2 9 10-5 10 5-10 5L2 9Zm4 2v5c0 2 3 4 6 4s6-2 6-4v-5m4-2v8" />
            @break
        @case('certificate')
            <rect x="4" y="3" width="16" height="15" rx="2" /><path d="M8 7h8M8 11h5m-4 7v4l3-2 3 2v-4" />
            @break
        @case('book')
            <path d="M12 6c-3-2-6-2-10-1v14c4-1 7-1 10 1 3-2 6-2 10-1V5c-4-1-7-1-10 1Zm0 0v14" />
            @break
        @case('exam')
            <rect x="4" y="4" width="16" height="18" rx="2" /><path d="M9 4V2h6v2M8 10h8m-8 4 2 2 5-5" />
            @break
        @case('finance')
            <rect x="2" y="5" width="20" height="15" rx="2" /><path d="M2 9h20m-5 6h2M6 5V3h12" />
            @break
        @case('transport')
            <rect x="3" y="4" width="18" height="15" rx="2" /><path d="M3 12h18M7 4v8m10-8v8M6 19v2m12-2v2" /><circle cx="7" cy="16" r=".5" /><circle cx="17" cy="16" r=".5" />
            @break
        @case('library')
            <path d="M4 4h4v16H4zm6-1h4v17h-4zm6 3 3-1 3 14-3 1-3-14ZM4 9h4m2 6h4" />
            @break
        @case('hostel')
            <path d="M4 21V5l8-3 8 3v16M2 21h20M8 8h1m6 0h1M8 12h1m6 0h1M9 21v-5h6v5" />
            @break
        @case('communication')
            <path d="M21 11a8 8 0 0 1-8 8H5l-3 3V11a9 9 0 1 1 19 0ZM7 10h10M7 14h7" />
            @break
        @case('inventory')
            <path d="m12 2 9 5-9 5-9-5 9-5Zm-9 5v10l9 5 9-5V7M12 12v10" />
            @break
        @case('reports')
            <path d="M4 20h16M6 17v-5h3v5m3 0V5h3v12m3 0V9h3v8" />
            @break
        @case('settings')
            <path d="M4 6h16M4 12h16M4 18h16" /><circle cx="9" cy="6" r="2" fill="currentColor" stroke="none" /><circle cx="16" cy="12" r="2" fill="currentColor" stroke="none" /><circle cx="10" cy="18" r="2" fill="currentColor" stroke="none" />
            @break
        @default
            <rect x="3" y="3" width="7" height="7" rx="1" /><rect x="14" y="3" width="7" height="7" rx="1" /><rect x="3" y="14" width="7" height="7" rx="1" /><rect x="14" y="14" width="7" height="7" rx="1" />
    @endswitch
</svg>
