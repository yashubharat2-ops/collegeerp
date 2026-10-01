@props([
    'label',
    'route' => null,
    'params' => [],
    'href' => null,
    'perm' => null,
    'pattern' => null,
    'query' => null,
])

{{--
    One module child row of the ERP sidebar.

    Presentation contract (public/css/erp-sidebar.css):
      * 34px tall, 13px label, indented 38px from the sidebar's left edge;
      * the label lives in a bare <span> so the row can truncate cleanly;
      * the current page is marked with `aria-current="page"` only — the active
        styling keys off that semantic attribute instead of a second
        presentational class, so there is one hook per concern.

    Behaviour contract: the row renders only when the signed-in user holds at
    least one of the permission slugs in `perm` ("|"-separated, i.e. the OR of
    the `@if(… hasPermission(…))` guards this component replaces), and it links
    to exactly the URL those guards used to build, in the same order.
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

    // Current-route detection: the route name, widened to its resource prefix,
    // so create/edit/show screens still highlight the row that owns them.
    $patterns = $pattern === null
        ? ($route === null ? [] : [preg_replace('/\.(index|list)$/', '.*', $route)])
        : array_map('trim', explode('|', $pattern));

    $current = $allowed
        && $patterns !== []
        && request()->routeIs($patterns)
        && collect((array) $query)->every(
            fn ($value, $key): bool => (string) request()->query($key) === (string) $value
        );
@endphp

@if ($allowed)
<a class="nav-link" href="{{ $href ?? route($route, $params) }}"@if ($current) aria-current="page"@endif><span>{{ $label }}</span></a>
@endif
