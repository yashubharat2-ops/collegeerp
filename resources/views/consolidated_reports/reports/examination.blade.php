@php
    $rows = $examinations['rows'];
@endphp

<p class="panel-subtitle">
    Examinations with their schedule, subject, attendance, marks, result and publication counts, plus the pass / fail
    position of the published results — both taken from the existing Examination Reports derivations. Results that are
    not published are never counted.
</p>

<div class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
    <div class="stat-card"><p class="stat-label">Examinations</p><p class="stat-value">{{ number_format($rows->total()) }}</p><p class="stat-hint">Matching the selected filters</p></div>
    <div class="stat-card"><p class="stat-label">Published results</p><p class="stat-value">{{ number_format($results['totalResults']) }}</p></div>
    <div class="stat-card"><p class="stat-label">Passed</p><p class="stat-value">{{ number_format($results['passCount']) }}</p></div>
    <div class="stat-card"><p class="stat-label">Failed</p><p class="stat-value">{{ number_format($results['failCount']) }}</p></div>
    <div class="stat-card">
        <p class="stat-label">Pass rate</p>
        <p class="stat-value">{{ $results['passRate'] === null ? '—' : number_format((float) $results['passRate'], 1).'%' }}</p>
        <p class="stat-hint">Passed ÷ published results</p>
    </div>
</div>

<h4 class="mt-8 text-sm font-semibold text-slate-800">Examination summary</h4>
<div class="mt-3 overflow-x-auto">
    <table class="w-full min-w-[1080px] text-left text-sm">
        <thead class="border-b bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
            <tr>
                <th class="px-3 py-3">Examination</th>
                <th class="px-3 py-3">Academic year</th>
                <th class="px-3 py-3">Term</th>
                <th class="px-3 py-3 text-right">Schedules</th>
                <th class="px-3 py-3 text-right">Subjects</th>
                <th class="px-3 py-3 text-right">Attendance</th>
                <th class="px-3 py-3 text-right">Marks</th>
                <th class="px-3 py-3 text-right">Results</th>
                <th class="px-3 py-3 text-right">Published</th>
                <th class="px-3 py-3 text-right">Passed</th>
            </tr>
        </thead>
        <tbody class="divide-y">
            @forelse($rows as $examination)
                <tr>
                    <td class="px-3 py-3 font-medium">{{ $examination->name }}<span class="block font-mono text-xs text-slate-500">{{ $examination->code }}</span></td>
                    <td class="px-3 py-3">{{ $examination->academicYear?->name ?? '—' }}</td>
                    <td class="px-3 py-3">{{ $examination->academicTerm?->name ?? '—' }}</td>
                    <td class="px-3 py-3 text-right font-mono">{{ number_format((int) $examination->schedules_count) }}</td>
                    <td class="px-3 py-3 text-right font-mono">{{ number_format((int) $examination->subjects_count) }}</td>
                    <td class="px-3 py-3 text-right font-mono">{{ number_format((int) $examination->attendance_count) }}</td>
                    <td class="px-3 py-3 text-right font-mono">{{ number_format((int) $examination->marks_count) }}</td>
                    <td class="px-3 py-3 text-right font-mono">{{ number_format((int) $examination->results_count) }}</td>
                    <td class="px-3 py-3 text-right font-mono">{{ number_format((int) $examination->published_count) }}</td>
                    <td class="px-3 py-3 text-right font-mono">{{ number_format((int) $examination->pass_count) }}</td>
                </tr>
            @empty
                <tr><td colspan="10" class="px-3 py-8 text-center text-slate-500">No examinations match these filters.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

@include('consolidated_reports._pagination', ['rows' => $rows, 'subject' => 'examinations'])
