<nav class="no-print mt-5 flex flex-wrap gap-2" aria-label="Academic report views">
    @foreach($reports as $key => $label)
        <a href="{{ route('academic-reports.index', ['report' => $key]) }}"
           @if($selected === $key) aria-current="page" @endif
           class="rounded-lg px-3 py-2 text-xs font-semibold {{ $selected === $key ? 'bg-indigo-600 text-white' : 'bg-slate-100 text-slate-700 hover:bg-indigo-50 hover:text-indigo-700' }}">
            {{ $label }}
        </a>
    @endforeach
</nav>
