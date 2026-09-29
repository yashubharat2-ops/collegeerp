<nav aria-label="Certificate Reports" class="flex flex-wrap gap-2 rounded-2xl border border-slate-200 bg-white p-2 shadow-sm">
    @foreach($reports as $key => $label)
        @php $isActive = ($active ?? '') === $key; @endphp
        <a href="{{ route('certificate-reports.index', ['report' => $key]) }}"
           @if($isActive) aria-current="page" @endif
           class="rounded-xl px-3.5 py-2 text-xs font-semibold transition {{ $isActive ? 'bg-indigo-600 text-white shadow-sm' : 'text-slate-700 hover:bg-slate-100' }}">
            {{ $label }}
        </a>
    @endforeach
</nav>
