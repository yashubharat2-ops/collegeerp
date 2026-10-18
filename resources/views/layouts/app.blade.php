<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') · {{ config('app.name', 'College ERP') }}</title>

    {{-- Shared shell styles are public assets; each URL is versioned from its file contents below. --}}
    @php
        /*
         * Version public stylesheets from their contents so a changed asset gets
         * a changed URL. Missing files remain linkable without a version query.
         */
        $erpCssVer = static function (string $rel): ?string {
            $p = public_path($rel);
            if (! is_file($p)) return null;
            // Content hash is authoritative; mtime is fallback if hash is disabled.
            $h = @hash_file('crc32b', $p);
            if (is_string($h) && $h !== '') return $h;
            $m = @filemtime($p);
            return $m ? (string) $m : null;
        };
        $erpSidebarCssVersion = $erpCssVer('css/erp-sidebar.css');
        $erpUserMenuCssVersion = $erpCssVer('css/erp-user-menu.css');
        $erpDropdownCssVersion = $erpCssVer('css/erp-dropdown.css');
        $erpListCssVersion = $erpCssVer('css/erp-list.css');
        $erpStudentFormCssVersion = $erpCssVer('css/erp-student-form.css');
        $erpUiCssVersion = $erpCssVer('css/erp-ui.css');
        unset($erpCssVer);

        /*
         * The top bar's notification affordance. It is the SAME gate <x-user.menu>
         * applies to its own Notifications row — `notifications.view` plus a
         * Route::has probe — so the chrome can never offer a link that answers 403,
         * and both entry points agree. There is no unread count anywhere in the
         * application, so there is no badge: the old decorative dot was removed with
         * it rather than left claiming data the ERP does not have.
         */
        $erpMaySeeNotifications = ($erpNotifyUser = auth()->user()) !== null
            && $erpNotifyUser->hasPermission('notifications.view')
            && \Illuminate\Support\Facades\Route::has('notifications.index');
        unset($erpNotifyUser);
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
    {{-- Shared shell geometry and chrome, including the viewport-independent app row. --}}
    <link rel="stylesheet" href="{{ asset('css/erp-ui.css') }}@if ($erpUiCssVersion)?v={{ $erpUiCssVersion }}@endif">
    @if (config('app.debug'))
        <!-- ERP shell asset debug: erp-ui={{ asset('css/erp-ui.css') }}?v={{ $erpUiCssVersion }} public_path={{ public_path('css/erp-ui.css') }} exists={{ is_file(public_path('css/erp-ui.css')) ? 'yes' : 'no' }} build={{ is_file(public_path('build/manifest.json')) ? 'yes' : 'no' }} -->
    @endif

    {{-- Application-wide styling is still built by Vite; link it only when a build (public/build/manifest.json) or a running dev server (public/hot) exists, so a page render never depends on running npm. --}}
    @if (is_file(public_path('build/manifest.json')) || is_file(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endif

    {{-- Student Create/Edit form: the compact data-entry grid and its input chrome,
         as a static asset like the four links above — the form must render as an
         ERP screen even when no bundle exists, and linking it after the Vite block
         means the form's own rules always win over a stale one. --}}
    <link rel="stylesheet" href="{{ asset('css/erp-student-form.css') }}@if ($erpStudentFormCssVersion)?v={{ $erpStudentFormCssVersion }}@endif">
    @stack('styles')

</head>
<body class="bg-slate-100 text-slate-900">
    {{-- Skip link: the shell renders ~130 navigation rows before the page content, so
         without this a keyboard user tabs through the whole sidebar to reach the first
         field of a form. It is the first focusable element in the document, off-screen
         until it takes focus, and it targets the <main> landmark below (tabindex="-1"
         is what lets focus actually move there). Styled by public/css/erp-ui.css. --}}
    <a class="erp-skip-link" href="#erp-main">Skip to main content</a>
    <div class="erp-app-shell">
        @include('layouts.sidebar')

        <section class="erp-app-main">
            <header class="erp-topbar no-print flex items-center justify-between border-b border-slate-200 bg-white px-6 py-4">
                <div class="erp-topbar__lead">
                    <div class="flex items-center gap-3">
                        {{-- Drawer trigger: the sidebar is off-canvas below 1024px. --}}
                        <button type="button" class="erp-nav-open-button" data-nav-drawer-open aria-controls="erp-sidebar" aria-label="Open menu">
                            <x-nav.icon name="menu" :size="18" />
                        </button>
                        <p class="erp-topbar__tenant text-sm text-slate-500">{{ app(\App\Support\Tenancy\TenantContext::class)->college()?->name ?? 'Platform' }}</p>
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
                    <h1 class="erp-topbar__title text-xl font-semibold">@yield('title', 'Dashboard')</h1>
                </div>
                <div class="erp-topbar__actions flex items-center gap-4">
                    {{-- Notifications: a real link when the viewer may open the screen, and
                         the same "visible but disabled, with the reason in the tooltip"
                         affordance <x-user.menu> uses when they may not. The previous
                         markup was an inert button with a decorative dot. --}}
                    @if ($erpMaySeeNotifications)
                        <a class="erp-notify" href="{{ route('notifications.index') }}" aria-label="Notifications">
                            <x-nav.icon name="bell" :size="18" />
                        </a>
                    @else
                        <button type="button" class="erp-notify erp-notify--disabled" aria-label="Notifications" aria-disabled="true" title="Notifications are not available to your role.">
                            <x-nav.icon name="bell" :size="18" />
                        </button>
                    @endif
                    {{-- The account area lives here only: the sidebar no longer
                         duplicates it. Avatar, name, e-mail and every entry of the
                         panel are defined once, in <x-user.menu>. --}}
                    <x-user.menu />
                </div>
            </header>

            <main class="erp-content" id="erp-main" tabindex="-1">
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
