<p class="panel-subtitle">Refunds recorded against actual collections, newest first. A rejected or cancelled refund stops reducing the net collected amount, so the effective total below excludes it while the recorded total keeps the full audit trail.</p>
<div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
    <div class="rounded-xl bg-indigo-50 p-4"><p class="text-sm text-indigo-700">Refunds</p><p class="text-2xl font-bold text-indigo-900">{{ number_format($totals['refunds']) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Recorded value</p><p class="text-2xl font-bold text-slate-900">{{ number_format($totals['recorded'], 2) }}</p></div>
    <div class="rounded-xl bg-amber-50 p-4"><p class="text-sm text-amber-700">Refunded (effective)</p><p class="text-2xl font-bold text-amber-900">{{ number_format($totals['effective'], 2) }}</p></div>
    @foreach($totals['by_status'] as $status => $count)
        <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">{{ ucfirst($status) }}</p><p class="text-2xl font-bold text-slate-900">{{ number_format($count) }}</p></div>
    @endforeach
</div>
<div class="mt-5 overflow-x-auto">
    <table class="w-full text-left text-sm">
        <thead><tr class="border-b text-slate-500"><th class="py-3 pr-4">Refund date</th><th class="pr-4">Refund no</th><th class="pr-4">Receipt no</th><th class="pr-4">Student</th><th class="pr-4">Program / year</th><th class="pr-4 text-right">Amount</th><th class="pr-4">Status</th><th>Approved / processed by</th></tr></thead>
        <tbody>
        @forelse($rows as $row)
            @php($payment = $row->payment)
            <tr class="border-b align-top">
                <td class="py-3 pr-4 font-medium whitespace-nowrap">{{ $row->refund_date?->format('d M Y') ?? '—' }}</td>
                <td class="pr-4">{{ $row->refund_number }}</td>
                <td class="pr-4">{{ $payment?->payment_number ?? '—' }}</td>
                <td class="pr-4">{{ $payment?->studentEnrollment?->student?->student_number ?? '—' }}<span class="block">{{ $payment?->studentEnrollment?->student?->fullName() ?? 'Unknown student' }}</span></td>
                <td class="pr-4">{{ $payment?->studentEnrollment?->program?->name ?? '—' }} · {{ $payment?->studentEnrollment?->academicYear?->name ?? '—' }}</td>
                <td class="pr-4 text-right font-medium">{{ number_format((float) $row->amount, 2) }}</td>
                <td class="pr-4">{{ ucfirst($row->status) }}<span class="block text-xs text-slate-500">{{ $row->reason ?: '—' }}</span></td>
                <td>{{ $row->approver?->name ?? '—' }}<span class="block text-xs text-slate-500">{{ $row->processor?->name ?? '—' }}</span></td>
            </tr>
        @empty
            <tr><td colspan="8" class="py-6 text-slate-500">No refunds match these filters.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@include('finance_reports._pagination', ['subject' => 'refunds'])
