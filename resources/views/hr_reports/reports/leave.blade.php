<p class="panel-subtitle">Every leave request of the active college, newest leave date first, with the request's own recorded day count. A date range selects the requests that overlap the period. The tiles cover the whole filtered set.</p>
<div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
    <div class="rounded-xl bg-indigo-50 p-4"><p class="text-sm text-indigo-700">Requests</p><p class="text-2xl font-bold text-indigo-900">{{ number_format($totals['requests']) }}</p></div>
    <div class="rounded-xl bg-emerald-50 p-4"><p class="text-sm text-emerald-700">Leave days</p><p class="text-2xl font-bold text-emerald-900">{{ number_format($totals['days'], 2) }}</p></div>
    <div class="rounded-xl bg-emerald-50 p-4"><p class="text-sm text-emerald-700">Approved ({{ number_format($totals['approved']) }})</p><p class="text-2xl font-bold text-emerald-900">{{ number_format($totals['approved_days'], 2) }} days</p></div>
    <div class="rounded-xl bg-amber-50 p-4"><p class="text-sm text-amber-700">Pending ({{ number_format($totals['pending']) }})</p><p class="text-2xl font-bold text-amber-900">{{ number_format($totals['pending_days'], 2) }} days</p></div>
    <div class="rounded-xl bg-rose-50 p-4"><p class="text-sm text-rose-700">Rejected ({{ number_format($totals['rejected']) }})</p><p class="text-2xl font-bold text-rose-900">{{ number_format($totals['rejected_days'], 2) }} days</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Cancelled ({{ number_format($totals['cancelled']) }})</p><p class="text-2xl font-bold text-slate-900">{{ number_format($totals['cancelled_days'], 2) }} days</p></div>
</div>
<div class="mt-5 overflow-x-auto">
    <table class="w-full text-left text-sm">
        <thead><tr class="border-b text-slate-500"><th class="py-3 pr-4">Employee</th><th class="pr-4">Leave type</th><th class="pr-4">From</th><th class="pr-4">To</th><th class="pr-4 text-right">Days</th><th class="pr-4">Status</th><th>Reason</th></tr></thead>
        <tbody>
        @forelse($rows as $row)
            <tr class="border-b align-top">
                <td class="py-3 pr-4 font-medium">{{ $row->employee?->employee_code ?? '—' }}<span class="block font-normal">{{ $row->employee?->full_name ?? 'Unknown employee' }}</span></td>
                <td class="pr-4">{{ $row->leaveType?->name ?? '—' }}</td>
                <td class="pr-4 whitespace-nowrap">{{ $row->from_date?->format('d M Y') ?? '—' }}</td>
                <td class="pr-4 whitespace-nowrap">{{ $row->to_date?->format('d M Y') ?? '—' }}</td>
                <td class="pr-4 text-right font-medium">{{ number_format((float) $row->days, 2) }}</td>
                <td class="pr-4">{{ ucfirst($row->status) }}</td>
                <td>{{ $row->reason ?: '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="7" class="py-6 text-slate-500">No leave requests match these filters.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@include('hr_reports._pagination', ['subject' => 'leave requests'])
