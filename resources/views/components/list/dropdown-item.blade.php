@props(['href' => null])

{{--
    One entry of a list dropdown (<x-list.dropdown>).

    Renders an <a> when a destination is given (a plain link: the page-level
    export of the current filtered list) and a <button type="button"> otherwise
    (an action: the bulk export of the selection, where the caller passes
    `data-bulk-action="…"` and js/erp-list.js posts it to the bulk endpoint).

    Any extra attribute is passed straight through to the element, which is how
    the bulk items carry their action name — the shared bulk script binds every
    [data-bulk-action] found inside the bar, hidden menu or not.
--}}
@if($href)
    <a href="{{ $href }}" class="dropdown-item" role="menuitem" {{ $attributes }}>{{ $slot }}</a>
@else
    <button type="button" class="dropdown-item" role="menuitem" {{ $attributes }}>{{ $slot }}</button>
@endif
