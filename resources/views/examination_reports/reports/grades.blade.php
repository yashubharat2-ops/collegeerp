<p class="panel-subtitle">Published results grouped by the stored overall grade, ordered by average percentage (highest first). Grade values are read as stored — never recalculated.</p>
<div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
    <div class="rounded-xl bg-indigo-50 p-4"><p class="text-sm text-indigo-700">Results</p><p class="text-2xl font-bold text-indigo-900">{{ number_format($totalResults) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Graded</p><p class="text-2xl font-bold text-slate-900">{{ number_format($gradedCount) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Grade bands</p><p class="text-2xl font-bold text-slate-900">{{ number_format($rows->total()) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">On this page</p><p class="text-2xl font-bold text-slate-900">{{ $rows->count() }}</p></div>
</div>
<div class="mt-5 overflow-x-auto">
    <table class="w-full text-left text-sm">
        <thead><tr class="border-b text-slate-500"><th class="py-3 pr-4">Grade</th><th class="pr-4 text-right">Results</th><th class="pr-4 text-right">Average %</th><th class="pr-4 text-right">Pass</th><th class="text-right">Fail</th></tr></thead>
        <tbody>
        @forelse($rows as $row)
            <tr class="border-b align-top">
                <td class="py-3 pr-4 font-medium">{{ $row->overall_grade ?? 'Not graded' }}</td>
                <td class="pr-4 text-right">{{ number_format($row->total) }}</td>
                <td class="pr-4 text-right">{{ $row->avg_percentage === null ? '—' : round((float) $row->avg_percentage, 2).'%' }}</td>
                <td class="pr-4 text-right">{{ number_format($row->pass_count) }}</td>
                <td class="text-right">{{ number_format($row->fail_count) }}</td>
            </tr>
        @empty
            <tr><td colspan="5" class="py-6 text-slate-500">No published results match these filters.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@include('examination_reports._pagination', ['subject' => 'grade bands'])
