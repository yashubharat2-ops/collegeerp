<p class="panel-subtitle">Every payroll run of the active college, newest pay period first, with the amounts the payroll itself recorded. Payroll processing remains the only place these figures are calculated — this report reads them. Cancelled runs are never paid out; the tiles say what the listed set holds and how much of it is processed.</p>
<div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
    <div class="rounded-xl bg-indigo-50 p-4"><p class="text-sm text-indigo-700">Payroll runs</p><p class="text-2xl font-bold text-indigo-900">{{ number_format($totals['payrolls']) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Employees paid</p><p class="text-2xl font-bold text-slate-900">{{ number_format($totals['employees']) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Processed runs</p><p class="text-2xl font-bold text-slate-900">{{ number_format($totals['processed']) }}</p></div>
    <div class="rounded-xl bg-indigo-50 p-4"><p class="text-sm text-indigo-700">Basic</p><p class="text-2xl font-bold text-indigo-900">{{ number_format($totals['basic'], 2) }}</p></div>
    <div class="rounded-xl bg-emerald-50 p-4"><p class="text-sm text-emerald-700">Gross</p><p class="text-2xl font-bold text-emerald-900">{{ number_format($totals['gross'], 2) }}</p></div>
    <div class="rounded-xl bg-rose-50 p-4"><p class="text-sm text-rose-700">Deductions</p><p class="text-2xl font-bold text-rose-900">{{ number_format($totals['deductions'], 2) }}</p></div>
    <div class="rounded-xl bg-emerald-50 p-4"><p class="text-sm text-emerald-700">Net payable</p><p class="text-2xl font-bold text-emerald-900">{{ number_format($totals['net'], 2) }}</p></div>
</div>
<div class="mt-5 overflow-x-auto">
    <table class="w-full text-left text-sm">
        <thead><tr class="border-b text-slate-500"><th class="py-3 pr-4">Pay period</th><th class="pr-4">Employee</th><th class="pr-4">Salary structure</th><th class="pr-4 text-right">Basic</th><th class="pr-4 text-right">Gross</th><th class="pr-4 text-right">Deductions</th><th class="pr-4 text-right">Net</th><th class="pr-4">Status</th><th>Processed</th></tr></thead>
        <tbody>
        @forelse($rows as $row)
            <tr class="border-b align-top">
                <td class="py-3 pr-4 font-medium whitespace-nowrap">{{ $row->pay_period?->format('M Y') ?? '—' }}</td>
                <td class="pr-4">{{ $row->employee?->employee_code ?? '—' }}<span class="block">{{ $row->employee?->full_name ?? 'Unknown employee' }}</span></td>
                <td class="pr-4">{{ $row->salaryStructure?->name ?? '—' }}</td>
                <td class="pr-4 text-right">{{ number_format((float) $row->basic_amount, 2) }}</td>
                <td class="pr-4 text-right">{{ number_format((float) $row->gross_amount, 2) }}</td>
                <td class="pr-4 text-right">{{ number_format((float) $row->total_deductions, 2) }}</td>
                <td class="pr-4 text-right font-medium">{{ number_format((float) $row->net_amount, 2) }}</td>
                <td class="pr-4">{{ ucfirst($row->status) }}</td>
                <td>{{ $row->processed_at?->format('d M Y') ?? '—' }}@if($row->processor)<span class="block text-xs text-slate-500">{{ $row->processor->name }}</span>@endif</td>
            </tr>
        @empty
            <tr><td colspan="9" class="py-6 text-slate-500">No payroll runs match these filters.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@include('hr_reports._pagination', ['subject' => 'payroll runs'])
