<p class="panel-subtitle">The live fee ledger: assigned amount, applicable concessions, collected, refunded and outstanding per student fee assignment. Every figure comes from the same ledger the Due / Outstanding screen uses, so the two can never disagree. Cancelled assignments carry no payable balance.</p>
<div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
    <div class="rounded-xl bg-indigo-50 p-4"><p class="text-sm text-indigo-700">Assignments</p><p class="text-2xl font-bold text-indigo-900">{{ number_format($totals['assignments']) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Assigned</p><p class="text-2xl font-bold text-slate-900">{{ number_format($totals['assigned'], 2) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Concessions</p><p class="text-2xl font-bold text-slate-900">{{ number_format($totals['concession'], 2) }}</p></div>
    <div class="rounded-xl bg-emerald-50 p-4"><p class="text-sm text-emerald-700">Net collected</p><p class="text-2xl font-bold text-emerald-900">{{ number_format($totals['net_collected'], 2) }}</p></div>
    <div class="rounded-xl bg-amber-50 p-4"><p class="text-sm text-amber-700">Outstanding</p><p class="text-2xl font-bold text-amber-900">{{ number_format($totals['outstanding'], 2) }}</p></div>
</div>
<div class="mt-5 overflow-x-auto">
    <table class="w-full text-left text-sm">
        <thead><tr class="border-b text-slate-500"><th class="py-3 pr-4">Student</th><th class="pr-4">Program / year</th><th class="pr-4">Fee structure</th><th class="pr-4 text-right">Assigned</th><th class="pr-4 text-right">Concessions</th><th class="pr-4 text-right">Paid</th><th class="pr-4 text-right">Refunded</th><th class="pr-4 text-right">Net collected</th><th class="pr-4 text-right">Outstanding</th><th>Status</th></tr></thead>
        <tbody>
        @forelse($rows as $row)
            @php($ledger = $row->ledger ?? [])
            <tr class="border-b align-top">
                <td class="py-3 pr-4 font-medium">{{ $row->studentEnrollment?->student?->student_number ?? '—' }}<span class="block">{{ $row->studentEnrollment?->student?->fullName() ?? 'Unknown student' }}</span></td>
                <td class="pr-4">{{ $row->studentEnrollment?->program?->name ?? '—' }} · {{ $row->studentEnrollment?->academicYear?->name ?? '—' }}</td>
                <td class="pr-4">{{ $row->feeStructure?->name ?? '—' }}<span class="block text-xs text-slate-500">{{ ucfirst($row->status) }}</span></td>
                <td class="pr-4 text-right">{{ number_format((float) ($ledger['assigned'] ?? $row->assigned_amount), 2) }}</td>
                <td class="pr-4 text-right">{{ number_format((float) ($ledger['concession'] ?? 0), 2) }}</td>
                <td class="pr-4 text-right">{{ number_format((float) ($ledger['paid'] ?? 0), 2) }}</td>
                <td class="pr-4 text-right">{{ number_format((float) ($ledger['refunded'] ?? 0), 2) }}</td>
                <td class="pr-4 text-right">{{ number_format((float) ($ledger['net_collected'] ?? 0), 2) }}</td>
                <td class="pr-4 text-right font-medium">{{ number_format((float) ($ledger['outstanding'] ?? 0), 2) }}</td>
                <td>{{ ucfirst((string) ($ledger['status'] ?? '—')) }}</td>
            </tr>
        @empty
            <tr><td colspan="10" class="py-6 text-slate-500">No fee assignments match these filters.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@include('finance_reports._pagination', ['subject' => 'fee assignments'])
