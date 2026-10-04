@props(['table' => false])

{{--
    Shared horizontal-scroll wrapper for list content that can be wider than the
    page: a wide table, or a long pager.

    Why a component: a table with more columns than fit must scroll INSIDE its own
    box — otherwise the whole page gets a horizontal scrollbar and the filter card
    is clipped on the right. Making that a single shared, reused wrapper means no
    list has to remember it, and the rules live once in
    public/css/erp-list.css (`.erp-list-scroll`), which is a static asset and so
    works with no Vite build.

    Usage:
        <x-list.scroll class="mt-6" table>   … <table> … </x-list.scroll>
        <x-list.scroll class="erp-list-pagination">{{ $items->links() }}</x-list.scroll>

    `table` only tells the stylesheet which child it wraps (an inner table is kept
    at `min-width: 100%` so it still fills the box while it fits); the wrapper
    itself is identical for both uses.
--}}
<div {{ $attributes->merge(['class' => 'erp-list-scroll']) }} @if($table) data-list-table @endif>
    {{ $slot }}
</div>
