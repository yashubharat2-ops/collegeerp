@props([
    'label',
    'perm' => null,
])

{{--
    A non-interactive sub-group label inside a collapsible module group.

        Group (42px, icon + label + chevron)
          ── Sub-group label (28px, uppercase)     ← this component
             • child row (34px)
             • child row
          ── Sub-group label
             • child row

    It exists so a wide module (Academics, Inventory, Examinations, Reports…) can
    be scanned without turning every row into a top-level group of its own.

    Three contracts make it safe to introduce into the existing shell:

    1. IT IS NOT A LINK AND NOT A GROUP. The markup is a bare <p> carrying
       `erp-nav__sub-label` — never `nav-group__head`, never `nav-link`, never an
       <a>. Every navigation test in this repository either counts
       `class="nav-link"` occurrences inside a group, or delimits a group by the
       next `nav-group__head` / `nav-group__label"` occurrence; a <p> is invisible
       to all of them, so row counts and group boundaries are unchanged by
       construction.

    2. IT NEVER RENDERS WITHOUT A ROW UNDERNEATH IT. `perm` is the OR of the
       permissions of the rows in its section — the same "|"-separated syntax and
       the same `hasPermission()` check `<x-nav.link>` and `<x-nav.group>` use. A
       viewer who holds none of them sees neither the label nor its rows, so a
       permission-filtered sidebar never shows a heading with nothing beneath it.
       (public/js/erp-sidebar.js applies the same rule while the menu search is
       filtering.)

    3. THE ACCESSIBLE NAME OF THE LABEL IS PERMISSION-GATED WITH THE ROWS, and
       the element is exposed to assistive technology as a plain paragraph. It is
       not announced as a link, a button or a menu item, because it is none.
--}}
@php
    $user = auth()->user();
    $slugs = $perm === null ? [] : array_values(array_filter(array_map('trim', explode('|', $perm))));
    $allowed = $slugs === [];
    foreach ($slugs as $slug) {
        if ($user?->hasPermission($slug)) {
            $allowed = true;
            break;
        }
    }
@endphp

@if ($allowed)
    <p class="erp-nav__sub-label" data-nav-sub-label>{{ $label }}</p>
@endif
