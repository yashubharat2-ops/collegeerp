<nav class="no-print mt-5 flex flex-wrap gap-2" aria-label="Hostel report views" data-report-nav>
    @foreach($reports as $key => $label)
        <a href="{{ route('hostel-reports.index', ['report' => $key]) }}"
           @if($report === $key) aria-current="page" @endif
           class="rounded-lg px-3 py-2 text-xs font-semibold {{ $report === $key ? 'bg-indigo-600 text-white' : 'bg-slate-100 text-slate-700 hover:bg-indigo-50 hover:text-indigo-700' }}">
            {{ $label }}
        </a>
    @endforeach
</nav>
