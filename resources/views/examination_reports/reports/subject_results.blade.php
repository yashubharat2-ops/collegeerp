<p class="panel-subtitle">Subject-level aggregates over published results only — appearances, pass/fail and averages grouped by subject (ordered by subject name).</p>
<div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
    <div class="rounded-xl bg-indigo-50 p-4"><p class="text-sm text-indigo-700">Subjects</p><p class="text-2xl font-bold text-indigo-900">{{ number_format($subjectsCount) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Result items</p><p class="text-2xl font-bold text-slate-900">{{ number_format($itemsCount) }}</p></div>
    <div class="rounded-xl bg-emerald-50 p-4"><p class="text-sm text-emerald-700">Passed items</p><p class="text-2xl font-bold text-emerald-900">{{ number_format($passCount) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">On this page</p><p class="text-2xl font-bold text-slate-900">{{ $rows->count() }}</p></div>
</div>
<div class="mt-5 overflow-x-auto">
    <table class="w-full text-left text-sm">
        <thead><tr class="border-b text-slate-500"><th class="py-3 pr-4">Subject</th><th class="pr-4 text-right">Results</th><th class="pr-4 text-right">Appeared</th><th class="pr-4 text-right">Average marks</th><th class="pr-4 text-right">Max</th><th class="pr-4 text-right">Pass</th><th class="pr-4 text-right">Fail</th><th class="pr-4 text-right">Absent</th><th class="pr-4 text-right">Withheld</th><th class="text-right">Incomplete</th></tr></thead>
        <tbody>
        @forelse($rows as $row)
            <tr class="border-b align-top">
                <td class="py-3 pr-4 font-medium">{{ $row->name }}<span class="block text-xs text-slate-500">{{ $row->code }}</span></td>
                <td class="pr-4 text-right">{{ number_format($row->items_count) }}</td>
                <td class="pr-4 text-right">{{ number_format($row->appeared_count) }}</td>
                <td class="pr-4 text-right">{{ $row->avg_marks ?? '—' }}</td>
                <td class="pr-4 text-right">{{ $row->max_marks ?? '—' }}</td>
                <td class="pr-4 text-right">{{ number_format($row->pass_count) }}</td>
                <td class="pr-4 text-right">{{ number_format($row->fail_count) }}</td>
                <td class="pr-4 text-right">{{ number_format($row->absent_count) }}</td>
                <td class="pr-4 text-right">{{ number_format($row->withheld_count) }}</td>
                <td class="text-right">{{ number_format($row->incomplete_count) }}</td>
            </tr>
        @empty
            <tr><td colspan="10" class="py-6 text-slate-500">No published subject results match these filters.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@include('examination_reports._pagination', ['subject' => 'subjects'])
