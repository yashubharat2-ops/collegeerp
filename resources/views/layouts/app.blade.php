<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') · {{ config('app.name', 'College ERP') }}</title>

    @php
        /*
         * The sidebar owns two static assets of its own, linked straight from
         * `public/` (never through the Vite tags below): the navigation must keep
         * its dark navy chrome, its sizing and its interactions when no build and
         * no dev server exist — `public/` is served by the web server alone.
         *
         * The stylesheet gets the file mtime as a cache buster, so a stale copy can
         * never be served after a deploy; the `?: null` guard keeps the tag usable
         * when the build directory is not readable from disk. The script deliberately
         * carries no query string: every external script rendered by this layout must
         * stay `<script type="module" src="….js">` (asserted by
         * tests/Feature/Programs/ProgramNameEscapeTest.php, which requires the src to
         * end in `.js`), and static assets under `public/` keep their Last-Modified /
         * ETag headers, so the browser revalidates the file on its own.
         */
        $erpSidebarCssVersion = filemtime(public_path('css/erp-sidebar.css')) ?: null;
        $erpUserMenuCssVersion = filemtime(public_path('css/erp-user-menu.css')) ?: null;
        $erpDropdownCssVersion = filemtime(public_path('css/erp-dropdown.css')) ?: null;
        $erpListCssVersion = filemtime(public_path('css/erp-list.css')) ?: null;
    @endphp
    <link rel="stylesheet" href="{{ asset('css/erp-sidebar.css') }}@if ($erpSidebarCssVersion)?v={{ $erpSidebarCssVersion }}@endif">
    {{-- The signed-in user panel in the header is its own component with its own
         stylesheet, so the sidebar file stays authoritative for the sidebar only. --}}
    <link rel="stylesheet" href="{{ asset('css/erp-user-menu.css') }}@if ($erpUserMenuCssVersion)?v={{ $erpUserMenuCssVersion }}@endif">
    {{-- List dropdown menus (the Export menu of the page header and the bulk bar).
         Static like the two above for the same reason: a menu must render as a menu
         even when no Vite build exists, or when the bundle predates a component. --}}
    <link rel="stylesheet" href="{{ asset('css/erp-dropdown.css') }}@if ($erpDropdownCssVersion)?v={{ $erpDropdownCssVersion }}@endif">
    {{-- Shared list/table responsive foundation (filters, table, pagination): the
         rules that keep a list inside the content column instead of overflowing it.
         Static for the same reason — a layout guarantee must not depend on a build. --}}
    <link rel="stylesheet" href="{{ asset('css/erp-list.css') }}@if ($erpListCssVersion)?v={{ $erpListCssVersion }}@endif">

    {{-- Application-wide styling is still built by Vite; link it only when a build (public/build/manifest.json) or a running dev server (public/hot) exists, so a page render never depends on running npm. --}}
    @if (is_file(public_path('build/manifest.json')) || is_file(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endif
</head>
<body class="bg-slate-100 text-slate-900">
    <div class="min-h-screen lg:flex">
        @include('layouts.sidebar')

        <section class="min-w-0 flex-1">
            <header class="no-print flex items-center justify-between border-b border-slate-200 bg-white px-6 py-4">
                <div>
                    <div class="flex items-center gap-3">
                        {{-- Drawer trigger: the sidebar is off-canvas below 1024px. --}}
                        <button type="button" class="erp-nav-open-button" data-nav-drawer-open aria-controls="erp-sidebar" aria-label="Open menu">
                            <x-nav.icon name="menu" :size="18" />
                        </button>
                        <p class="text-sm text-slate-500">{{ app(\App\Support\Tenancy\TenantContext::class)->college()?->name ?? 'Platform' }}</p>
                        @isset($colleges)
                            @if ($colleges->count() > 1)
                                <form method="POST" action="{{ route('college-context.switch') }}">
                                    @csrf
                                    <select class="rounded-lg border-slate-300 text-xs" name="college_id" onchange="this.form.submit()">
                                        @foreach ($colleges as $college)
                                            <option value="{{ $college->id }}" @selected(app(\App\Support\Tenancy\TenantContext::class)->id() === $college->id)>{{ $college->name }}</option>
                                        @endforeach
                                    </select>
                                </form>
                            @endif
                        @endisset
                    </div>
                    <h1 class="text-xl font-semibold">@yield('title', 'Dashboard')</h1>
                </div>
                <div class="flex items-center gap-4">
                    <button class="relative text-slate-500" aria-label="Notifications">
                        ♢<span class="absolute -right-1 -top-1 h-2 w-2 rounded-full bg-indigo-500"></span>
                    </button>
                    {{-- The account area lives here only: the sidebar no longer
                         duplicates it. Avatar, name, e-mail and every entry of the
                         panel are defined once, in <x-user.menu>. --}}
                    <x-user.menu />
                </div>
            </header>

            <main class="p-6">
                @if (session('success'))
                    <div class="alert-success">{{ session('success') }}</div>
                @endif
                @if ($errors->any())
                    <div class="alert-error">{{ $errors->first() }}</div>
                @endif
                @yield('content')
            </main>
        </section>
    </div>

    {{-- The sidebar behaviour loads as a module script: browsers defer modules by
         default, which is what the component needs (it reads the rendered markup), and
         `type="module"` is the form this layout allows for external scripts. The file is
         already a strict-mode IIFE, so module semantics need no other change. --}}
    <script type="module" src="{{ asset('js/erp-sidebar.js') }}"></script>
    {{-- Same loading rules as the sidebar script: external module script, no inline
         code, and a src that ends in `.js` (see ProgramNameEscapeTest). --}}
    <script type="module" src="{{ asset('js/erp-user-menu.js') }}"></script>
    <script type="module" src="{{ asset('js/erp-list.js') }}"></script>
    {{-- List toolbars: the Export dropdowns (page-level and bulk bar). Same
         loading rules: external module script, no inline code. --}}
    <script type="module" src="{{ asset('js/erp-dropdown.js') }}"></script>
    {{-- Printable documents: the "Print / save as PDF" button and the report page
         that opens the dialog itself. --}}
    <script type="module" src="{{ asset('js/erp-print.js') }}"></script>
    @stack('scripts')
</body>
</html>
