<p class="panel-subtitle">Fee structures with their fee heads, configured value (the total of the ACTIVE heads — the same sum the assignment snapshot is taken from) and how many student fee assignments use them. The aggregates cover the whole filtered set.</p>
<div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
    <div class="rounded-xl bg-indigo-50 p-4"><p class="text-sm text-indigo-700">Fee structures</p><p class="text-2xl font-bold text-indigo-900">{{ number_format($totals['structures']) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Fee heads</p><p class="text-2xl font-bold text-slate-900">{{ number_format($totals['fee_heads']) }}</p></div>
    <div class="rounded-xl bg-emerald-50 p-4"><p class="text-sm text-emerald-700">Configured value</p><p class="text-2xl font-bold text-emerald-900">{{ number_format($totals['configured_value'], 2) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Assignments</p><p class="text-2xl font-bold text-slate-900">{{ number_format($totals['assignments']) }}</p></div>
</div>
<div class="mt-5 overflow-x-auto">
    <table class="w-full text-left text-sm">
        <thead><tr class="border-b text-slate-500"><th class="py-3 pr-4">Fee structure</th><th class="pr-4">Academic year</th><th class="pr-4">Term</th><th class="pr-4">Program / department</th><th class="pr-4 text-right">Fee heads</th><th class="pr-4 text-right">Configured value</th><th class="pr-4 text-right">Assignments</th><th>Status</th></tr></thead>
        <tbody>
        @forelse($rows as $row)
            <tr class="border-b align-top">
                <td class="py-3 pr-4 font-medium">{{ $row->name }}<span class="block text-xs text-slate-500">{{ $row->code }}</span></td>
                <td class="pr-4">{{ $row->academicYear?->name ?? '—' }}</td>
                <td class="pr-4">{{ $row->academicTerm?->name ?? 'Full year' }}</td>
                <td class="pr-4">{{ $row->program?->name ?? '—' }}<span class="block text-xs text-slate-500">{{ $row->program?->department?->name ?? '—' }}</span></td>
                <td class="pr-4 text-right">{{ number_format($row->items_count) }}</td>
                <td class="pr-4 text-right font-medium">{{ number_format((float) $row->configured_total, 2) }}</td>
                <td class="pr-4 text-right">{{ number_format($row->assignments_count) }}</td>
                <td>{{ ucfirst($row->status) }}</td>
            </tr>
        @empty
            <tr><td colspan="8" class="py-6 text-slate-500">No fee structures match these filters.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@include('finance_reports._pagination', ['subject' => 'fee structures'])
