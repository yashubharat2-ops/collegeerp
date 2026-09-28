<p class="panel-subtitle">Publication progress per examination — aggregate counts only (total, calculated, published, unpublished). Unpublished student rows are never listed here.</p>
<div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
    <div class="rounded-xl bg-indigo-50 p-4"><p class="text-sm text-indigo-700">Results</p><p class="text-2xl font-bold text-indigo-900">{{ number_format($resultsCount) }}</p></div>
    <div class="rounded-xl bg-emerald-50 p-4"><p class="text-sm text-emerald-700">Published</p><p class="text-2xl font-bold text-emerald-900">{{ number_format($publishedCount) }}</p></div>
    <div class="rounded-xl bg-amber-50 p-4"><p class="text-sm text-amber-700">Unpublished</p><p class="text-2xl font-bold text-amber-900">{{ number_format($unpublishedCount) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Examinations</p><p class="text-2xl font-bold text-slate-900">{{ number_format($rows->total()) }}</p></div>
</div>
<div class="mt-5 overflow-x-auto">
    <table class="w-full text-left text-sm">
        <thead><tr class="border-b text-slate-500"><th class="py-3 pr-4">Examination</th><th class="pr-4">Term</th><th class="pr-4">Dates</th><th class="pr-4">Status</th><th class="pr-4 text-right">Results</th><th class="pr-4 text-right">Calculated</th><th class="pr-4 text-right">Published</th><th class="pr-4 text-right">Unpublished</th><th class="pr-4 text-right">% published</th><th class="text-right">Last published</th></tr></thead>
        <tbody>
        @forelse($rows as $row)
            <tr class="border-b align-top">
                <td class="py-3 pr-4 font-medium">{{ $row->name }}<span class="block text-xs text-slate-500">{{ $row->code }}</span></td>
                <td class="pr-4">{{ $row->academicTerm?->name ?? '—' }}</td>
                <td class="pr-4 whitespace-nowrap">{{ $row->start_date?->format('d M Y') ?? '—' }} – {{ $row->end_date?->format('d M Y') ?? '—' }}</td>
                <td class="pr-4">{{ ucfirst($row->status) }}</td>
                <td class="pr-4 text-right">{{ number_format($row->results_count) }}</td>
                <td class="pr-4 text-right">{{ number_format($row->calculated_count) }}</td>
                <td class="pr-4 text-right">{{ number_format($row->published_count) }}</td>
                <td class="pr-4 text-right">{{ number_format((int) $row->results_count - (int) $row->published_count) }}</td>
                <td class="pr-4 text-right">{{ $row->publish_rate === null ? '—' : $row->publish_rate.'%' }}</td>
                <td class="text-right whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($row->last_published_at)?->format('d M Y H:i') ?? '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="10" class="py-6 text-slate-500">No examinations match these filters.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@include('examination_reports._pagination', ['subject' => 'examinations'])
