@props([
    'id',
    'label',
    'icon' => 'grid',
    'perm' => null,
    'navigation' => null,
])

{{--
    One collapsible module of the ERP sidebar:

        [18px icon]  Module name                     [14px chevron]
            └ child rows (only while the group is open)

    Anatomy notes:
      * the row is a real <button>, so it is keyboard operable and announces
        itself through `aria-expanded` / `aria-controls`;
      * icon, label and chevron are three flex items — the label takes the
        flexible space, which is what keeps the chevron pinned to the far right
        instead of drifting next to the text;
      * the label is a block element (not a bare text node) so a module name
        that does not fit the row truncates rather than wrapping word by
        word;
      * the label text is deliberately not mirrored into a `data-tip`
        attribute — the rail tooltip reads it from `.nav-group__label`, which
        keeps exactly one copy of every module name in the markup.

    State comes from the server, not from localStorage: a group is open — and
    flagged `data-nav-active` — exactly when one of its rendered child rows
    carries `aria-current="page"`. That keeps load, refresh, back/forward and
    deep links on one rule, and the right group is open with or without
    JavaScript.

    Markup is kept on single lines on purpose: the sidebar's markup contract is
    asserted in tests by tag-level patterns, so the elements must not be
    scattered over several lines.
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

    $items = trim($slot->toHtml());
    $open = $allowed && $items !== '' && str_contains($items, 'aria-current="page"');
@endphp

@if ($allowed && $items !== '')
<li class="nav-group" data-nav-group="{{ $id }}" data-nav-open="{{ $open ? 'true' : 'false' }}" @if ($open) data-nav-active="true" @endif>
<button type="button" class="nav-group__head" id="nav-head-{{ $id }}" aria-expanded="{{ $open ? 'true' : 'false' }}" aria-controls="nav-items-{{ $id }}">
<span class="nav-group__icon"><x-nav.icon :name="$icon" :size="18" /></span>
<div class="nav-group__label">{{ $label }}</div>
<span class="nav-group__chevron"><x-nav.icon name="chevron-right" :size="14" /></span>
</button>
<div class="nav-items" id="nav-items-{{ $id }}" @if ($navigation) data-navigation="{{ $navigation }}" @endif @if (! $open) hidden @endif>{!! $items !!}</div>
</li>
@endif
