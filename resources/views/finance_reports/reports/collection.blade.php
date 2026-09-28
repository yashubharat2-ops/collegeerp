<p class="panel-subtitle">Every recorded collection for the active college, newest payment date first. Cancelled / reversed collections are excluded — they never count as money collected. The totals cover the whole filtered set, not just this page.</p>
<div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
    <div class="rounded-xl bg-indigo-50 p-4"><p class="text-sm text-indigo-700">Collections</p><p class="text-2xl font-bold text-indigo-900">{{ number_format($totals['payments']) }}</p></div>
    <div class="rounded-xl bg-emerald-50 p-4"><p class="text-sm text-emerald-700">Amount collected</p><p class="text-2xl font-bold text-emerald-900">{{ number_format($totals['total'], 2) }}</p></div>
    @foreach($totals['modes'] as $mode)
        <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">{{ ucfirst(str_replace('_', ' ', $mode['mode'])) }} ({{ number_format($mode['payments']) }})</p><p class="text-2xl font-bold text-slate-900">{{ number_format($mode['total'], 2) }}</p></div>
    @endforeach
</div>
<div class="mt-5 overflow-x-auto">
    <table class="w-full text-left text-sm">
        <thead><tr class="border-b text-slate-500"><th class="py-3 pr-4">Payment date</th><th class="pr-4">Receipt no</th><th class="pr-4">Student</th><th class="pr-4">Class / section</th><th class="pr-4">Fee type</th><th class="pr-4">Mode</th><th class="pr-4 text-right">Amount</th><th class="pr-4">Reference</th><th>Collected by</th></tr></thead>
        <tbody>
        @forelse($rows as $row)
            <tr class="border-b align-top">
                <td class="py-3 pr-4 font-medium whitespace-nowrap">{{ $row->payment_date?->format('d M Y') ?? '—' }}</td>
                <td class="pr-4">{{ $row->payment_number }}</td>
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
                <td class="pr-4">{{ $row->reference_number ?: '—' }}</td>
                <td>{{ $row->collector?->name ?? '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="9" class="py-6 text-slate-500">No collections match these filters.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@include('finance_reports._pagination', ['subject' => 'collections'])
