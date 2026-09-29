@php
    $ledgerText = fn (array $ledger): string => ucfirst($ledger['status'] ?? '—');
@endphp
<p class="panel-subtitle">Every transport fee assignment of the active college with the assigned / collected / due figures derived from the same Finance records the Transport Fees screen uses — the stored assignment amount and the existing fee_payments / fee_refunds rows, read through the shared TransportFeeService ledger. No amount is recalculated or stored here.</p>
<div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
    <div class="rounded-xl bg-indigo-50 p-4"><p class="text-sm text-indigo-700">Fee assignments</p><p class="text-2xl font-bold text-indigo-900">{{ number_format($totals['assignments']) }}</p></div>
    <div class="rounded-xl bg-indigo-50 p-4"><p class="text-sm text-indigo-700">Assigned</p><p class="text-2xl font-bold text-indigo-900">{{ number_format($totals['assigned'], 2) }}</p></div>
    <div class="rounded-xl bg-emerald-50 p-4"><p class="text-sm text-emerald-700">Collected</p><p class="text-2xl font-bold text-emerald-900">{{ number_format($totals['net_collected'], 2) }}</p></div>
    <div class="rounded-xl bg-rose-50 p-4"><p class="text-sm text-rose-700">Due</p><p class="text-2xl font-bold text-rose-900">{{ number_format($totals['outstanding'], 2) }}</p></div>
    <div class="rounded-xl bg-amber-50 p-4"><p class="text-sm text-amber-700">With a balance</p><p class="text-2xl font-bold text-amber-900">{{ number_format($totals['outstanding_assignments']) }}</p></div>
</div>
<div class="mt-5 overflow-x-auto">
    <table class="w-full text-left text-sm">
        <thead><tr class="border-b text-slate-500">
            <th class="py-3 pr-4">Student</th><th class="pr-4">Route / Stop</th><th class="pr-4">Fee structure</th>
            <th class="pr-4">Effective from</th><th class="pr-4 text-right">Assigned</th><th class="pr-4 text-right">Collected</th>
            <th class="pr-4 text-right">Due</th><th class="pr-4">Payment</th><th class="text-right">Status</th>
        </tr></thead>
        <tbody>
        @forelse($rows as $assignment)
            @php($ledger = $assignment->ledger ?? ['assigned' => 0, 'net_collected' => 0, 'outstanding' => 0, 'status' => null])
            <tr class="border-b align-top">
                <td class="py-3 pr-4 font-medium">{{ $assignment->studentTransportAssignment?->studentEnrollment?->student?->fullName() ?? '—' }}<span class="block text-xs font-normal text-slate-500">{{ $assignment->studentTransportAssignment?->studentEnrollment?->student?->student_number }}</span></td>
                <td class="pr-4">{{ $assignment->studentTransportAssignment?->transportRoute?->name ?? '—' }}<span class="block text-xs font-normal text-slate-500">{{ $assignment->studentTransportAssignment?->transportStop?->name ?? '—' }}</span></td>
                <td class="pr-4">{{ $assignment->transportFeeStructure?->name ?? '—' }}<span class="block text-xs font-normal text-slate-500">{{ $assignment->transportFeeStructure?->code }}</span></td>
                <td class="pr-4">{{ $assignment->effective_from?->format('d M Y') ?? '—' }}</td>
                <td class="pr-4 text-right">{{ number_format((float) $ledger['assigned'], 2) }}</td>
                <td class="pr-4 text-right">{{ number_format((float) $ledger['net_collected'], 2) }}</td>
                <td class="pr-4 text-right">{{ number_format((float) $ledger['outstanding'], 2) }}</td>
                <td class="pr-4">{{ $ledgerText((array) $ledger) }}</td>
                <td class="text-right">{{ ucfirst($assignment->status) }}</td>
            </tr>
        @empty
            <tr><td colspan="9" class="py-6 text-slate-500">No transport fee assignments match these filters.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@include('transport.reports._pagination', ['subject' => 'fee assignments'])
