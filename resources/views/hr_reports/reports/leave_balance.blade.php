<p class="panel-subtitle">One row per employee: the annual entitlement held by the active leave types, the days taken in the filtered period (approved requests), the days still awaiting a decision, and the balance. Only recorded requests move a balance — nothing is estimated.</p>
<div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
    <div class="rounded-xl bg-indigo-50 p-4"><p class="text-sm text-indigo-700">Employees</p><p class="text-2xl font-bold text-indigo-900">{{ number_format($totals['employees']) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Entitlement (per employee)</p><p class="text-2xl font-bold text-slate-900">{{ number_format($totals['employees'] > 0 ? $totals['entitlement'] / $totals['employees'] : 0, 2) }} days</p></div>
    <div class="rounded-xl bg-emerald-50 p-4"><p class="text-sm text-emerald-700">Approved days</p><p class="text-2xl font-bold text-emerald-900">{{ number_format($totals['approved_days'], 2) }}</p></div>
    <div class="rounded-xl bg-amber-50 p-4"><p class="text-sm text-amber-700">Pending days</p><p class="text-2xl font-bold text-amber-900">{{ number_format($totals['pending_days'], 2) }}</p></div>
</div>
<div class="mt-5 overflow-x-auto">
    <table class="w-full text-left text-sm">
        <thead><tr class="border-b text-slate-500"><th class="py-3 pr-4">Employee</th><th class="pr-4">Department</th><th class="pr-4 text-right">Entitlement</th><th class="pr-4 text-right">Approved</th><th class="pr-4 text-right">Pending</th><th class="text-right">Balance</th></tr></thead>
        <tbody>
        @forelse($rows as $row)
            <tr class="border-b align-top">
                <td class="py-3 pr-4 font-medium">{{ $row->employee_code }}<span class="block font-normal">{{ $row->full_name }}</span></td>
                <td class="pr-4">{{ $row->department?->name ?? '—' }}</td>
                <td class="pr-4 text-right">{{ number_format((float) $row->entitlement_days, 2) }}</td>
                <td class="pr-4 text-right">{{ number_format((float) $row->availed_days, 2) }}</td>
                <td class="pr-4 text-right">{{ number_format((float) $row->pending_days, 2) }}</td>
                <td class="text-right font-medium">{{ number_format((float) $row->balance_days, 2) }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="py-6 text-slate-500">No employees match these filters.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@include('hr_reports._pagination', ['subject' => 'employees'])
