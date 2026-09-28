<nav class="no-print mt-5 flex flex-wrap gap-2" aria-label="Finance report views" data-report-nav>
    @foreach($reports as $key => $label)
        <a href="{{ route('finance-reports.index', ['report' => $key]) }}"
           @if($selected === $key) aria-current="page" @endif
           class="rounded-lg px-3 py-2 text-xs font-semibold {{ $selected === $key ? 'bg-indigo-600 text-white' : 'bg-slate-100 text-slate-700 hover:bg-indigo-50 hover:text-indigo-700' }}">
            {{ $label }}
        </a>
    @endforeach
</nav>
<div class="no-print mt-3 flex flex-wrap items-center gap-3 text-xs text-slate-500" data-shared-reports>
    <span>Other report modules:</span>
    @if(auth()->user()?->can('viewAny', \App\Models\StudentReport::class))
        <a class="font-semibold text-indigo-700 hover:underline" href="{{ route('student-reports.index') }}">Student Reports</a>
    @endif
    @if(auth()->user()?->can('viewAny', \App\Models\AcademicReport::class))
        <a class="font-semibold text-indigo-700 hover:underline" href="{{ route('academic-reports.index') }}">Academic Reports</a>
    @endif
    @if(auth()->user()?->can('viewAny', \App\Models\ExaminationReport::class))
        <a class="font-semibold text-indigo-700 hover:underline" href="{{ route('examination-reports.index') }}">Examination Reports</a>
    @endif
    @if(auth()->user()?->can('viewAny', \App\Models\FeeReport::class))
        <a class="font-semibold text-indigo-700 hover:underline" href="{{ route('fee-reports.index') }}">Fee Reports</a>
    @endif
</div>
