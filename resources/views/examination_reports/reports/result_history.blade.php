<p class="panel-subtitle">Students with published results in the active filters, newest publication first. Counts, best percentage and the latest published examination are aggregated from the stored results.</p>
<div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
    <div class="rounded-xl bg-indigo-50 p-4"><p class="text-sm text-indigo-700">Students</p><p class="text-2xl font-bold text-indigo-900">{{ number_format($rows->total()) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Published results</p><p class="text-2xl font-bold text-slate-900">{{ number_format($resultsCount) }}</p></div>
    <div class="rounded-xl bg-emerald-50 p-4"><p class="text-sm text-emerald-700">Passed results</p><p class="text-2xl font-bold text-emerald-900">{{ number_format($passCount) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">On this page</p><p class="text-2xl font-bold text-slate-900">{{ $rows->count() }}</p></div>
</div>
<div class="mt-5 overflow-x-auto">
    <table class="w-full text-left text-sm">
        <thead><tr class="border-b text-slate-500"><th class="py-3 pr-4">Student</th><th class="pr-4 text-right">Results</th><th class="pr-4 text-right">Best %</th><th class="pr-4">Latest examination</th><th class="pr-4">Latest grade</th><th class="pr-4">Latest status</th><th class="text-right">Last published</th></tr></thead>
        <tbody>
        @forelse($rows as $row)
            <tr class="border-b align-top">
                <td class="py-3 pr-4 font-medium">{{ $row->student?->student_number ?? '—' }}<span class="block">{{ $row->student?->fullName() ?? 'Unknown student' }}</span></td>
                <td class="pr-4 text-right">{{ number_format($row->results_count) }}</td>
                <td class="pr-4 text-right">{{ $row->best_percentage === null ? '—' : $row->best_percentage.'%' }}</td>
                <td class="pr-4">{{ $row->latest?->examination?->name ?? '—' }}</td>
                <td class="pr-4">{{ $row->latest?->overall_grade ?? '—' }}</td>
                <td class="pr-4">{{ $row->latest ? ucfirst($row->latest->result_status) : '—' }}</td>
                <td class="text-right whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($row->last_published_at)?->format('d M Y H:i') ?? '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="7" class="py-6 text-slate-500">No students with published results match these filters.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@include('examination_reports._pagination', ['subject' => 'students'])
