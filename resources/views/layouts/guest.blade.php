<!doctype html>
{{--
    The signed-out shell: sign in, forgot password, reset password.

    One line on purpose — it carries no navigation and no module markup, so it is
    the one page in the application that never needs the sidebar's components. It
    does need to LOOK like the product with no frontend build present, which is
    why public/css/erp-ui.css is linked here statically as well: this is the first
    screen a visitor sees, and it must not degrade into an unstyled document just
    because `public/build/manifest.json` is missing.

    The static file is linked BEFORE the bundle, so every rule in its `.erp-auth`
    section is a fallback: the dark page and the centred column stay, and the card
    keeps whatever the Tailwind utilities give it. The `erp-auth*` classes are
    additive, and nothing else about this document changed — same head, same
    title, same markup, same forms and routes.
--}}
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ config('app.name', 'College ERP') }}</title>@php $erpThemeCssVersion = is_file(public_path('css/erp-theme.css')) ? (@hash_file('crc32b', public_path('css/erp-theme.css')) ?: filemtime(public_path('css/erp-theme.css')) ?: null) : null; $erpAuthUiCssVersion = is_file(public_path('css/erp-ui.css')) ? (filemtime(public_path('css/erp-ui.css')) ?: null) : null; @endphp<link rel="stylesheet" href="{{ asset('css/erp-theme.css') }}@if ($erpThemeCssVersion)?v={{ $erpThemeCssVersion }}@endif"><link rel="stylesheet" href="{{ asset('css/erp-ui.css') }}@if ($erpAuthUiCssVersion)?v={{ $erpAuthUiCssVersion }}@endif">@if(is_file(public_path('build/manifest.json')) || is_file(public_path('hot')))@vite(['resources/css/app.css','resources/js/app.js'])@endif</head><body class="erp-auth min-h-screen bg-slate-900 text-slate-100"><main class="erp-auth__main flex min-h-screen items-center justify-center px-6"><div class="erp-auth__column w-full max-w-md">@yield('content')</div></main></body></html>
