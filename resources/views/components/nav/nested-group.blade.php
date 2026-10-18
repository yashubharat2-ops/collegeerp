@props([
    'id',
    'label',
    'icon' => null,
    'perm' => null,
])

{{--
    Nested collapsible parent inside a top-level Admission group.

    Transfers and Settings are not top-level modules; they are collapsible
    parents that live inside Admission. This component reuses the same
    collapsible logic as <x-nav.group> (data-nav-group, data-nav-open,
    aria-expanded, nav-items) but with a visually nested style:

    - No large module icon; the label is the only text
    - Indented under its parent (parent's nav-items already has 38px padding)
    - Chevron still indicates open/closed

    Behaviour: server-side open detection is identical to nav.group – the
    nested group is open exactly when one of its child rows carries
    aria-current="page". This ensures Transfer In/Out and ID Card Setting
    automatically expand both their own parent and the outer Admission group.
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
<li class="nav-group nav-group--nested" data-nested-group="{{ $id }}" data-nav-open="{{ $open ? 'true' : 'false' }}" @if ($open) data-nav-active="true" @endif>
<button type="button" class="nav-group__head nav-group__head--nested" id="nav-head-{{ $id }}" aria-expanded="{{ $open ? 'true' : 'false' }}" aria-controls="nav-items-{{ $id }}">
@if($icon)
<span class="nav-group__icon nav-group__icon--nested"><x-nav.icon :name="$icon" :size="16" /></span>
@endif
<div class="nav-group__label nav-group__label--nested">{{ $label }}</div>
<span class="nav-group__chevron"><x-nav.icon name="chevron-right" :size="12" /></span>
</button>
<div class="nav-items nav-items--nested" id="nav-items-{{ $id }}" @if (! $open) hidden @endif>{!! $items !!}</div>
</li>
@endif
