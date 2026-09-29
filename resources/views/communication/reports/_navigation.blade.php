<nav aria-label="Communication Reports" class="flex flex-wrap gap-2 rounded-2xl border border-slate-200 bg-white p-2 shadow-sm">
    @foreach($reports as $key => $label)
        @php $isActive = ($active ?? '') === $key; @endphp
        <a href="{{ route('communication-reports.index', ['report' => $key]) }}"
           @if($isActive) aria-current="page" style="background-color: #0d9488; color: #ffffff;" @endif
           class="rounded-xl px-3.5 py-2 text-xs font-semibold transition {{ $isActive ? 'bg-teal-600 bg-indigo-600 !text-white text-white shadow-sm' : 'text-slate-700 hover:bg-slate-100' }}">
            {{ $label }}
        </a>
    @endforeach
</nav>
