@extends('layouts.app')

@section('title', 'Result Calculation')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Result Calculation</h2>
            <p class="panel-subtitle">
                Calculate results from captured exam marks. Calculation is re-runnable and transaction-safe — it never duplicates results and never publishes anything.
            </p>
        </div>
    </div>

    @if($errors->any())
        <div class="alert-error mt-4">
            <ul class="list-inside list-disc space-y-1">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="GET" action="{{ route('result-calculation.index') }}" class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <select class="input" name="examination_id" onchange="this.form.submit()">
            <option value="">Select examination…</option>
            @foreach($examinations as $exam)
                <option value="{{ $exam->id }}" @selected((int) $filters['examination_id'] === $exam->id)>{{ $exam->name }} ({{ $exam->code }})</option>
            @endforeach
        </select>
        <select class="input" name="grade_scale_id">
            <option value="">Grade / Pass-Fail scale…</option>
            @foreach($gradeScales as $scale)
                <option value="{{ $scale->id }}" @selected((int) $filters['grade_scale_id'] === $scale->id)>{{ $scale->name }} ({{ $scale->code }})</option>
            @endforeach
        </select>
        <select class="input" name="program_id">
            <option value="">All programs</option>
            @foreach($programs as $prog)
                <option value="{{ $prog->id }}" @selected((int) $filters['program_id'] === $prog->id)>{{ $prog->name }} ({{ $prog->code }})</option>
            @endforeach
        </select>
        <select class="input" name="section_id">
            <option value="">All sections</option>
            @foreach($sections as $sec)
                <option value="{{ $sec->id }}" @selected((int) $filters['section_id'] === $sec->id)>{{ $sec->name }} ({{ $sec->code }})</option>
            @endforeach
        </select>
        <select class="input" name="student_enrollment_id">
            <option value="">All eligible students</option>
            @foreach($enrollments as $enrollment)
                <option value="{{ $enrollment->id }}" @selected((int) $filters['student_enrollment_id'] === $enrollment->id)>
                    {{ $enrollment->enrollment_number }} — {{ $enrollment->student?->full_name }}
                </option>
            @endforeach
        </select>
        <div class="flex gap-2">
            <button class="button" type="submit">Apply scope</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('result-calculation.index') }}">Clear</a>
        </div>
    </form>

    @if($examination)
        <div class="mt-6 rounded-2xl border border-indigo-100 bg-indigo-50/50 p-4">
            <p class="text-sm font-semibold text-slate-900">Ready to calculate for {{ $examination->name }}</p>
            <p class="mt-1 text-xs text-slate-500">
                Exam schedules: {{ $summary['schedules'] }} ·
                Results stored: {{ $summary['total'] }} ·
                Calculated: {{ $summary['calculated'] }} ·
                Incomplete: {{ $summary['incomplete'] }} ·
                Failed: {{ $summary['failed'] }} ·
                Published: {{ $summary['published'] }}
            </p>
        </div>

        {{--
            Confirmation is handled by an inline onsubmit confirm(): no new JS
            dependency is introduced for this screen.
        --}}
        <form method="POST" action="{{ route('result-calculation.calculate') }}" class="mt-4">
            @csrf
            <input type="hidden" name="examination_id" value="{{ $examination->id }}">
            <input type="hidden" name="grade_scale_id" value="{{ $filters['grade_scale_id'] }}">
            <input type="hidden" name="program_id" value="{{ $filters['program_id'] }}">
            <input type="hidden" name="section_id" value="{{ $filters['section_id'] }}">
            <input type="hidden" name="student_enrollment_id" value="{{ $filters['student_enrollment_id'] }}">
            <button class="button" type="submit" onclick="return confirm('Calculate results for this scope? Existing calculated results are updated in place — no duplicates are created and nothing is published.')">
                Calculate results
            </button>
        </form>

        @can('recalculate', App\Models\ExamResult::class)
            <form method="POST" action="{{ route('result-calculation.recalculate') }}" class="mt-3">
                @csrf
                <input type="hidden" name="examination_id" value="{{ $examination->id }}">
                <input type="hidden" name="grade_scale_id" value="{{ $filters['grade_scale_id'] }}">
                <input type="hidden" name="program_id" value="{{ $filters['program_id'] }}">
                <input type="hidden" name="section_id" value="{{ $filters['section_id'] }}">
                <input type="hidden" name="student_enrollment_id" value="{{ $filters['student_enrollment_id'] }}">
                <button class="button !bg-slate-700" type="submit" onclick="return confirm('Recalculate results for this scope? Existing calculated results are refreshed in place — no duplicates are created.')">
                    Recalculate results
                </button>
            </form>
        @endcan
    @else
        <p class="mt-6 text-sm text-slate-500">Select an examination to see the calculation summary and actions.</p>
    @endif

    @if($canViewResults && $results)
        {{--
            The calculation worklist: the results inside the scope above. It lists
            RESULTS, so it is rendered only for a user who may read results
            (`results.view`) — the `result_calculation.*` permissions grant the
            engine, never per-student result data. Unpublished rows appear only
            with `results.view_unpublished`, exactly as on the Results screen.

            Bulk selection is export-only: nothing here recalculates or publishes.
            The handler re-queries the ticked ids inside the active college and
            re-checks each one through the Results policy before the CSV endpoint
            streams anything.
        --}}
        {{-- Explicit local selection scope: the shared script resolves this page's
             selectable list here, so the bar can never pick up controls from the
             scope/filter forms above. --}}
        <div class="mt-8" data-bulk-scope>
            <h3 class="text-sm font-semibold text-slate-900">Results in this scope</h3>
            <p class="mt-1 text-xs text-slate-500">
                Read-only worklist of the calculated results for the scope above.
                {{ $results->total() }} result(s).
            </p>

            <x-list.bulk-selection-bar module="result_calculation">
                <button type="button" data-bulk-action="export"
                        class="button !py-2 !text-xs font-semibold">
                    Export selected
                </button>
            </x-list.bulk-selection-bar>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b text-slate-500">
                            <th class="w-10 py-3"><x-list.select-all /></th>
                            <th>Enrollment No.</th>
                            <th>Student</th>
                            <th>Program / Section</th>
                            <th>Grade scale</th>
                            <th>Percentage</th>
                            <th>Calculation</th>
                            <th>Result</th>
                            <th>Publishing</th>
                            <th class="text-right">View</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($results as $result)
                            <tr class="border-b">
                                <td class="py-3"><x-list.row-checkbox :id="$result->id" /></td>
                                <td class="font-medium">{{ $result->studentEnrollment?->enrollment_number }}</td>
                                <td>{{ $result->studentEnrollment?->student?->full_name }}</td>
                                <td>
                                    {{ $result->studentEnrollment?->program?->name }}
                                    <span class="text-xs text-slate-500">/ {{ $result->studentEnrollment?->section?->name }}</span>
                                </td>
                                <td>{{ $result->gradeScale?->name ?? '—' }}</td>
                                <td>{{ $result->percentage !== null ? $result->percentage.'%' : '—' }}</td>
                                <td>
                                    <span class="rounded-full px-2 py-1 text-xs font-semibold {{ $result->calculation_status === 'calculated' ? 'bg-emerald-100 text-emerald-700' : ($result->calculation_status === 'failed' ? 'bg-rose-100 text-rose-700' : 'bg-amber-100 text-amber-700') }}">{{ ucfirst($result->calculation_status) }}</span>
                                </td>
                                <td>{{ ucfirst($result->result_status) }}</td>
                                <td>
                                    <span class="rounded-full px-2 py-1 text-xs font-semibold {{ $result->isPublished() ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">{{ ucfirst($result->publication_status) }}</span>
                                </td>
                                <td class="text-right">
                                    @can('view', $result)
                                        <a class="text-xs font-semibold text-indigo-600 hover:underline" href="{{ route('results.show', $result) }}">Open</a>
                                    @else
                                        <span class="text-xs text-slate-400">—</span>
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td class="py-6 text-slate-500" colspan="10">No results in this scope yet. Mark entry and calculate to produce them.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-4">{{ $results->links() }}</div>
        </div>
    @endif
</div>
@endsection
