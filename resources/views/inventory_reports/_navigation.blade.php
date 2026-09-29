<nav class="mt-5 flex flex-wrap gap-2" aria-label="Inventory / Asset Reports">
    @foreach($reports as $key => $label)
        <a
            class="rounded-lg px-3 py-2 text-xs font-semibold {{ $report === $key ? 'bg-indigo-600 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' }}"
            href="{{ route('inventory-reports.index', ['report' => $key]) }}"
            @if($report === $key) aria-current="page" @endif
        >{{ $label }}</a>
    @endforeach
</nav>
