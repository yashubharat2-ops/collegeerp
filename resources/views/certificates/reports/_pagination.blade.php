@if(isset($certificates) && $certificates->hasPages())
<div class="border-t border-slate-100 px-4 py-3">
    {{ $certificates->links() }}
</div>
@endif
