<p class="panel-subtitle">Every leave request recorded in the Leave Management module for the active college, newest leave start first. A date range selects the requests that overlap it. Totals cover the whole filtered set, not just this page.</p>
<div class="mt-5 grid gap-3 sm:grid-cols-3 lg:grid-cols-4">
    <div class="rounded-xl bg-indigo-50 p-4"><p class="text-sm text-indigo-700">Requests</p><p class="text-2xl font-bold text-indigo-900">{{ number_format($totals['requests']) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Days requested</p><p class="text-2xl font-bold text-slate-900">{{ number_format($totals['days']) }}</p></div>
    <div class="rounded-xl bg-emerald-50 p-4"><p class="text-sm text-emerald-700">Approved</p><p class="text-2xl font-bold text-emerald-900">{{ number_format($totals['approved']) }}</p></div>
    <div class="rounded-xl bg-amber-50 p-4"><p class="text-sm text-amber-700">Pending</p><p class="text-2xl font-bold text-amber-900">{{ number_format($totals['pending']) }}</p></div>
    <div class="rounded-xl bg-rose-50 p-4"><p class="text-sm text-rose-700">Rejected</p><p class="text-2xl font-bold text-rose-900">{{ number_format($totals['rejected']) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Cancelled</p><p class="text-2xl font-bold text-slate-900">{{ number_format($totals['cancelled']) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Approved days</p><p class="text-2xl font-bold text-slate-900">{{ number_format($totals['approved_days']) }}</p></div>
</div>
<div class="mt-5 overflow-x-auto">
    <table class="w-full text-left text-sm">
        <thead><tr class="border-b text-slate-500"><th class="py-3 pr-4">Staff / Employee</th><th class="pr-4">Leave type</th><th class="pr-4">From</th><th class="pr-4">To</th><th class="pr-4 text-right">Days</th><th class="pr-4">Status</th><th>Approved by</th></tr></thead>
        <tbody>
        @forelse($rows as $row)
            <tr class="border-b align-top">
                <td class="py-3 pr-4 font-medium">{{ $row->employee?->full_name ?? 'Unknown employee' }}<span class="block text-xs font-normal text-slate-500">{{ $row->employee?->employee_code }} · {{ $row->employee?->department?->name ?? 'No department' }}</span></td>
                <td class="pr-4">{{ $row->leaveType?->name ?? 'Unknown leave type' }}</td>
                <td class="pr-4 whitespace-nowrap">{{ $row->from_date?->format('d M Y') ?? '—' }}</td>
                <td class="pr-4 whitespace-nowrap">{{ $row->to_date?->format('d M Y') ?? '—' }}</td>
                <td class="pr-4 text-right">{{ number_format((int) $row->days) }}</td>
                <td class="pr-4">{{ ucfirst($row->status) }}</td>
                <td>{{ $row->approver?->name ?? '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="7" class="py-6 text-slate-500">No leave requests match these filters.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<div class="mt-6 overflow-x-auto">
    <p class="text-sm font-semibold text-slate-700">Days by leave type</p>
    <table class="mt-2 w-full text-left text-sm">
        <thead><tr class="border-b text-slate-500"><th class="py-2 pr-4">Leave type</th><th class="pr-4">Type status</th><th class="pr-4 text-right">Requests</th><th class="text-right">Days</th></tr></thead>
        <tbody>
        @forelse($by_type as $row)
            <tr class="border-b"><td class="py-2 pr-4">{{ $row['leave_type'] }}@if($row['code']) ({{ $row['code'] }})@endif</td><td class="pr-4">{{ $row['type_status'] ? ucfirst($row['type_status']) : '—' }}</td><td class="pr-4 text-right">{{ number_format($row['requests']) }}</td><td class="text-right font-medium">{{ number_format($row['days']) }}</td></tr>
        @empty
            <tr><td colspan="4" class="py-4 text-slate-500">No leave days recorded for these filters.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@include('hr_reports._pagination', ['subject' => 'leave requests'])
