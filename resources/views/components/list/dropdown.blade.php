@props([
    'label',
    'size' => 'md',
    'align' => 'right',
])

{{--
    Reusable list dropdown: an indigo ERP trigger button plus a white menu panel.

    It is deliberately generic so every list module can use it (the Students list
    uses it for Export in the page header AND in the bulk selection bar). The
    shell owns only the chrome and the open/close contract:

      - `data-dropdown`        : the root the shared script binds to
      - `data-dropdown-trigger`: the button that toggles the panel
      - `data-dropdown-menu`   : the panel (toggled by `js/erp-dropdown.js`)

    Behaviour lives in public/js/erp-dropdown.js — no inline script, as the
    layout requires. Items are NOT intercepted: an <a> keeps its navigation and a
    [data-bulk-action] button keeps its own handler (js/erp-list.js), so putting a
    dropdown inside the bulk bar cannot swallow a bulk action.

    The panel is always in the DOM (only hidden), so the bulk action script binds
    its items whether or not the menu has been opened. Items come from the caller
    — typically <x-list.dropdown-item> — so the same shell serves links (page-level
    export of the filtered list) and buttons (bulk export of the selection).

    Styling: public/css/erp-dropdown.css, a static asset linked straight from the
    layout, so the menu is a proper white floating panel with no Vite build — the
    same convention as the sidebar and the signed-in user panel. `.erp-list-dropdown`
    is the scoping root; the trigger keeps the shared indigo `.button`.
--}}
@php
    $triggerClass = $size === 'sm'
        ? 'button inline-flex items-center gap-1.5 !py-2 !text-xs font-semibold'
        : 'button inline-flex items-center gap-1.5';
    /*
     * The panel is positioned by public/css/erp-dropdown.css, not by Tailwind
     * utilities: the menu must sit correctly even when the Vite bundle is stale or
     * absent (which is exactly how it once rendered as inline text). The root
     * carries the size so the compact bulk-bar trigger is small regardless of the
     * build, and the panel carries an alignment modifier instead of `right-0`.
     */
    $panelClass = 'dropdown-panel dropdown-panel--'.($align === 'left' ? 'left' : 'right');
@endphp

<div class="erp-list-dropdown" data-dropdown data-dropdown-size="{{ $size }}">
    <button
        type="button"
        class="{{ $triggerClass }}"
        data-dropdown-trigger
        aria-haspopup="true"
        aria-expanded="false"
    >
        {{ $label }}
        <x-nav.icon name="chevron-down" :size="14" />
    </button>

    <div class="{{ $panelClass }} hidden" data-dropdown-menu role="menu">
        {{ $slot }}
    </div>
</div>
