@props(['name', 'size' => 18])

{{--
    The sidebar's icon system: one stroke-based 24×24 outline set, drawn inline
    so it never depends on the Vite build, a font or an icon package.

    Size is carried by the SVG's own width/height attributes (not only CSS), so
    an icon can never render larger than its box even if a stylesheet is
    missing — that is the guard against the old "giant magnifying glass"
    failure, which came from an unsized <svg> defaulting to 300×150.
--}}
@php
    $paths = [
        'grid' => '<rect x="3.6" y="3.6" width="7.2" height="7.2" rx="1.6"/><rect x="13.2" y="3.6" width="7.2" height="7.2" rx="1.6"/><rect x="3.6" y="13.2" width="7.2" height="7.2" rx="1.6"/><rect x="13.2" y="13.2" width="7.2" height="7.2" rx="1.6"/>',
        'users' => '<circle cx="9.8" cy="8" r="3.3"/><path d="M15.6 19.4v-.9a3.6 3.6 0 0 0-3.6-3.6H7.6A3.6 3.6 0 0 0 4 18.5v.9"/><path d="M15.4 5.1a3.3 3.3 0 0 1 0 5.8"/><path d="M17.4 14.9a3.6 3.6 0 0 1 2.6 3.5v1"/>',
        'user-plus' => '<circle cx="9.4" cy="8" r="3.3"/><path d="M15.2 19.4v-.9a3.6 3.6 0 0 0-3.6-3.6H7.4a3.6 3.6 0 0 0-3.6 3.6v.9"/><path d="M18.2 8.1v5.2"/><path d="M15.6 10.7h5.2"/>',
        'graduation-cap' => '<path d="m12 4.1 8.9 4.2-8.9 4.2-8.9-4.2Z"/><path d="M6.4 10.6v4.3c0 1.6 2.5 2.9 5.6 2.9s5.6-1.3 5.6-2.9v-4.3"/><path d="M20.9 8.3v6.1"/>',
        'award' => '<circle cx="12" cy="9.3" r="5.4"/><path d="M8.5 14 7.1 20.9l4.9-2.7 4.9 2.7L15.5 14"/>',
        'book-open' => '<path d="M12 6.6C10.4 5.1 8.2 4.5 4.3 4.7a.8.8 0 0 0-.8.8V17c0 .4.4.8.8.7 3.8-.2 6 .3 7.7 1.8 1.7-1.5 3.9-2 7.7-1.8.4 0 .8-.3.8-.7V5.5a.8.8 0 0 0-.8-.8c-3.9-.2-6.1.4-7.7 1.9Z"/><path d="M12 6.6v13"/>',
        'clipboard-check' => '<rect x="5.4" y="4.6" width="13.2" height="15.8" rx="2.2"/><path d="M9.3 4.6V3.5c0-.5.4-.9.9-.9h3.6c.5 0 .9.4.9.9v1.1"/><path d="m8.9 12.8 2.1 2.1 4.1-4.1"/>',
        'wallet' => '<rect x="3.6" y="6.6" width="16.8" height="12.8" rx="2.4"/><path d="M3.6 10.6h16.8"/><path d="M16.4 15.4h1.6"/>',
        'bus' => '<rect x="4" y="4.6" width="16" height="11.8" rx="2.2"/><path d="M4 10.6h16"/><path d="M8 4.6v6M16 4.6v6"/><path d="M6.8 16.4v2a1 1 0 0 0 1 1h.5a1 1 0 0 0 1-1v-2M14.7 16.4v2a1 1 0 0 0 1 1h.5a1 1 0 0 0 1-1v-2"/>',
        'books' => '<rect x="4" y="4.6" width="4.2" height="14.8" rx="1.2"/><rect x="9.4" y="4.6" width="4.2" height="14.8" rx="1.2"/><path d="m15.2 6 4.1-1.2 2.9 11.6-4.1 1.2Z"/>',
        'building' => '<path d="M4.6 20.2V5.4c0-.7.5-1.2 1.2-1.2h7.4c.7 0 1.2.5 1.2 1.2v14.8"/><path d="M14.4 9.8h3.8c.7 0 1.2.5 1.2 1.2v9.2"/><path d="M3.2 20.2h17.6"/><path d="M7.4 7.6h4M7.4 11h4M7.4 14.4h4"/>',
        'message' => '<path d="M20.2 12.2c0 3.6-3.7 6.6-8.2 6.6a9.4 9.4 0 0 1-2.7-.4L4.6 20l1.3-3.2a6.3 6.3 0 0 1-2-4.6c0-3.6 3.7-6.6 8.2-6.6s8.1 3 8.1 6.6Z"/>',
        'box' => '<path d="m12 3.7 8.1 4.1v8.4L12 20.3l-8.1-4.1V7.8Z"/><path d="m3.9 7.8 8.1 4.1 8.1-4.1"/><path d="M12 11.9v8.4"/>',
        'chart-bar' => '<path d="M4.4 19.6h15.2"/><path d="M7.9 19.6v-7.2"/><path d="M12 19.6V5.4"/><path d="M16.1 19.6v-4.4"/>',
        'cog' => '<circle cx="12" cy="12" r="3"/><path d="m19.7 14.2.9-.5a.7.7 0 0 0 .3-.9l-.9-2.2a.7.7 0 0 0-.9-.3l-1 .4a6.9 6.9 0 0 0-1.5-.9l-.2-1.2a.7.7 0 0 0-.7-.6h-2.4a.7.7 0 0 0-.7.6l-.2 1.2c-.5.2-1 .5-1.5.9l-1-.4a.7.7 0 0 0-.9.3l-.9 2.2a.7.7 0 0 0 .3.9l.9.5c-.1.6-.1 1.1 0 1.7l-.9.5a.7.7 0 0 0-.3.9l.9 2.2c.1.3.5.5.9.3l1-.4c.4.4 1 .7 1.5.9l.2 1.2c.1.4.4.6.7.6h2.4c.4 0 .7-.2.7-.6l.2-1.2c.5-.2 1-.5 1.5-.9l1 .4c.4.2.8 0 .9-.3l.9-2.2a.7.7 0 0 0-.3-.9l-.9-.5c.1-.6.1-1.1 0-1.7Z"/>',
        'home' => '<path d="m3.9 10.4 8.1-6.6 8.1 6.6v8.9a1.7 1.7 0 0 1-1.7 1.7H5.6a1.7 1.7 0 0 1-1.7-1.7Z"/><path d="M9.4 21v-6.4h5.2V21"/>',
        'search' => '<circle cx="10.8" cy="10.8" r="6.1"/><path d="m19.3 19.3-4.3-4.3"/>',
        'chevron-right' => '<path d="m9.5 5.5 6.5 6.5-6.5 6.5"/>',
        'menu' => '<path d="M4.2 7.4h15.6M4.2 12h15.6M4.2 16.6h15.6"/>',
        'close' => '<path d="m6.3 6.3 11.4 11.4M17.7 6.3 6.3 17.7"/>',
        'user' => '<circle cx="12" cy="8.8" r="3.5"/><path d="M5.5 19.6a6.6 6.6 0 0 1 13 0"/>',
    ][$name] ?? '<rect x="4.4" y="4.4" width="15.2" height="15.2" rx="3"/>';
@endphp

<svg {{ $attributes->merge([
    'class' => 'nav-icon',
    'width' => $size,
    'height' => $size,
    'viewBox' => '0 0 24 24',
    'fill' => 'none',
    'stroke' => 'currentColor',
    'stroke-width' => '1.7',
    'stroke-linecap' => 'round',
    'stroke-linejoin' => 'round',
    'aria-hidden' => 'true',
    'focusable' => 'false',
]) }}>{!! $paths !!}</svg>
