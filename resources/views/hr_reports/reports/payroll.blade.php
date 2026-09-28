<p class="panel-subtitle">Every payroll record processed by the Staff Salary / Payroll module for the active college, newest pay period first. The money columns are exactly what Payroll stored when the record was processed — nothing is recalculated here.</p>
<div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
    <div class="rounded-xl bg-indigo-50 p-4"><p class="text-sm text-indigo-700">Payroll records</p><p class="text-2xl font-bold text-indigo-900">{{ number_format($totals['payrolls']) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Basic total</p><p class="text-2xl font-bold text-slate-900">{{ number_format($totals['basic'], 2) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Gross total</p><p class="text-2xl font-bold text-slate-900">{{ number_format($totals['gross'], 2) }}</p></div>
    <div class="rounded-xl bg-rose-50 p-4"><p class="text-sm text-rose-700">Deductions</p><p class="text-2xl font-bold text-rose-900">{{ number_format($totals['deductions'], 2) }}</p></div>
    <div class="rounded-xl bg-emerald-50 p-4"><p class="text-sm text-emerald-700">Net total</p><p class="text-2xl font-bold text-emerald-900">{{ number_format($totals['net'], 2) }}</p></div>
</div>
<div class="mt-5 overflow-x-auto">
    <table class="w-full text-left text-sm">
        <thead><tr class="border-b text-slate-500"><th class="py-3 pr-4">Staff / Employee</th><th class="pr-4">Pay period</th><th class="pr-4">Salary structure</th><th class="pr-4 text-right">Basic</th><th class="pr-4 text-right">Gross</th><th class="pr-4 text-right">Deductions</th><th class="pr-4 text-right">Net</th><th class="pr-4">Status</th><th>Processed</th></tr></thead>
        <tbody>
        @forelse($rows as $row)
            <tr class="border-b align-top">
                <td class="py-3 pr-4 font-medium">{{ $row->employee?->full_name ?? 'Unknown employee' }}<span class="block text-xs font-normal text-slate-500">{{ $row->employee?->employee_code }} · {{ $row->employee?->department?->name ?? 'No department' }}</span></td>
                <td class="pr-4 whitespace-nowrap">{{ $row->pay_period?->format('M Y') ?? '—' }}</td>
                <td class="pr-4">{{ $row->salaryStructure?->name ?? '—' }}</td>
                <td class="pr-4 text-right">{{ number_format((float) $row->basic_amount, 2) }}</td>
                <td class="pr-4 text-right">{{ number_format((float) $row->gross_amount, 2) }}</td>
                <td class="pr-4 text-right">{{ number_format((float) $row->total_deductions, 2) }}</td>
                <td class="pr-4 text-right font-semibold">{{ number_format((float) $row->net_amount, 2) }}</td>
                <td class="pr-4">{{ ucfirst($row->status) }}</td>
                <td class="whitespace-nowrap">{{ $row->processed_at?->format('d M Y') ?? '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="9" class="py-6 text-slate-500">No payroll records match these filters.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<div class="mt-6 overflow-x-auto">
    <p class="text-sm font-semibold text-slate-700">Payroll by status</p>
    <table class="mt-2 w-full text-left text-sm">
        <thead><tr class="border-b text-slate-500"><th class="py-2 pr-4">Status</th><th class="pr-4 text-right">Payroll records</th><th class="text-right">Net</th></tr></thead>
        <tbody>
        @foreach($by_status as $row)
            <tr class="border-b"><td class="py-2 pr-4">{{ ucfirst($row['status']) }}</td><td class="pr-4 text-right">{{ number_format($row['payrolls']) }}</td><td class="text-right font-medium">{{ number_format($row['net'], 2) }}</td></tr>
        @endforeach
        </tbody>
    </table>
</div>
@include('hr_reports._pagination', ['subject' => 'payroll records'])
