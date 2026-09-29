<div class="no-print mt-5 flex flex-wrap items-center justify-between gap-3 text-xs text-slate-500">
    <span>Showing {{ $rows->firstItem() ?? 0 }}–{{ $rows->lastItem() ?? 0 }} of {{ $rows->total() }} {{ $subject }}. Printing shows the current page only.</span>
    {{ $rows->links() }}
</div>
