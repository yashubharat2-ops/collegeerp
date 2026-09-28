<p class="panel-subtitle">Published results grouped by program, with the stored pass / fail statuses per program (program alphabetical). The cards reflect the active filters.</p>
<div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
    <div class="rounded-xl bg-indigo-50 p-4"><p class="text-sm text-indigo-700">Results</p><p class="text-2xl font-bold text-indigo-900">{{ number_format($totalResults) }}</p></div>
    <div class="rounded-xl bg-emerald-50 p-4"><p class="text-sm text-emerald-700">Passed</p><p class="text-2xl font-bold text-emerald-900">{{ number_format($passCount) }}</p></div>
    <div class="rounded-xl bg-rose-50 p-4"><p class="text-sm text-rose-700">Failed</p><p class="text-2xl font-bold text-rose-900">{{ number_format($failCount) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Programs</p><p class="text-2xl font-bold text-slate-900">{{ number_format($rows->total()) }}</p></div>
    <div class="rounded-xl bg-emerald-50 p-4"><p class="text-sm text-emerald-700">Pass rate</p><p class="text-2xl font-bold text-emerald-900">{{ $passRate === null ? '—' : $passRate.'%' }}</p></div>
</div>
<div class="mt-5 overflow-x-auto">
    <table class="w-full text-left text-sm">
        <thead><tr class="border-b text-slate-500"><th class="py-3 pr-4">Program</th><th class="pr-4 text-right">Results</th><th class="pr-4 text-right">Pass</th><th class="pr-4 text-right">Fail</th><th class="pr-4 text-right">Absent</th><th class="pr-4 text-right">Withheld</th><th class="pr-4 text-right">Incomplete</th><th class="text-right">Pass rate</th></tr></thead>
        <tbody>
        @forelse($rows as $row)
            <tr class="border-b align-top">
                <td class="py-3 pr-4 font-medium">{{ $row->name }}<span class="block text-xs text-slate-500">{{ $row->code }}</span></td>
                <td class="pr-4 text-right">{{ number_format($row->total) }}</td>
                <td class="pr-4 text-right">{{ number_format($row->pass_count) }}</td>
                <td class="pr-4 text-right">{{ number_format($row->fail_count) }}</td>
                <td class="pr-4 text-right">{{ number_format($row->absent_count) }}</td>
                <td class="pr-4 text-right">{{ number_format($row->withheld_count) }}</td>
                <td class="pr-4 text-right">{{ number_format($row->incomplete_count) }}</td>
                <td class="text-right">{{ $row->pass_rate === null ? '—' : $row->pass_rate.'%' }}</td>
            </tr>
        @empty
            <tr><td colspan="8" class="py-6 text-slate-500">No published results match these filters.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@include('examination_reports._pagination', ['subject' => 'programs'])
