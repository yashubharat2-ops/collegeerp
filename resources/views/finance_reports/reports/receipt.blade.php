<p class="panel-subtitle">Issued receipts — the printable projection of the completed collections above, newest payment date first. The receipt number is the payment number, so searching by receipt number searches the payment records. Each row shows how much of that receipt has since been refunded (valid refunds only).</p>
<div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
    <div class="rounded-xl bg-indigo-50 p-4"><p class="text-sm text-indigo-700">Receipts</p><p class="text-2xl font-bold text-indigo-900">{{ number_format($totals['receipts']) }}</p></div>
    <div class="rounded-xl bg-emerald-50 p-4"><p class="text-sm text-emerald-700">Issued value</p><p class="text-2xl font-bold text-emerald-900">{{ number_format($totals['issued'], 2) }}</p></div>
    <div class="rounded-xl bg-amber-50 p-4"><p class="text-sm text-amber-700">Refunded</p><p class="text-2xl font-bold text-amber-900">{{ number_format($totals['refunded'], 2) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Net collected</p><p class="text-2xl font-bold text-slate-900">{{ number_format($totals['net'], 2) }}</p></div>
</div>
<div class="mt-5 overflow-x-auto">
    <table class="w-full text-left text-sm">
        <thead><tr class="border-b text-slate-500"><th class="py-3 pr-4">Receipt no</th><th class="pr-4">Payment date</th><th class="pr-4">Student</th><th class="pr-4">Class / section</th><th class="pr-4">Fee type</th><th class="pr-4">Mode</th><th class="pr-4 text-right">Amount</th><th class="pr-4 text-right">Refunded</th><th class="pr-4 text-right">Net</th><th>Collected by</th></tr></thead>
        <tbody>
        @forelse($rows as $row)
            @php($refunded = (float) ($row->refunded_amount ?? 0))
            <tr class="border-b align-top">
                <td class="py-3 pr-4 font-medium">{{ $row->payment_number }}</td>
                <td class="pr-4 whitespace-nowrap">{{ $row->payment_date?->format('d M Y') ?? '—' }}</td>
                <td class="pr-4">{{ $row->studentEnrollment?->student?->student_number ?? '—' }}<span class="block">{{ $row->studentEnrollment?->student?->fullName() ?? 'Unknown student' }}</span></td>
                <td class="pr-4">{{ $row->studentEnrollment?->program?->name ?? '—' }} · {{ $row->studentEnrollment?->section?->name ?? '—' }}</td>
                <td class="pr-4">
                    @if($row->student_fee_assignment_id)
                        Tuition / student fees
                    @elseif($row->transport_fee_assignment_id)
                        Transport fee
                    @elseif($row->hostel_fee_assignment_id)
                        Hostel fee
                    @else
                        —
                    @endif
                </td>
                <td class="pr-4">{{ ucfirst(str_replace('_', ' ', $row->payment_mode)) }}</td>
                <td class="pr-4 text-right font-medium">{{ number_format((float) $row->amount, 2) }}</td>
                <td class="pr-4 text-right">{{ number_format($refunded, 2) }}</td>
                <td class="pr-4 text-right">{{ number_format(round((float) $row->amount - $refunded, 2), 2) }}</td>
                <td>{{ $row->collector?->name ?? '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="10" class="py-6 text-slate-500">No receipts match these filters.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@include('finance_reports._pagination', ['subject' => 'receipts'])
