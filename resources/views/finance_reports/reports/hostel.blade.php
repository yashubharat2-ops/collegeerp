<p class="panel-subtitle">Hostel fee charges raised through the existing Finance integration, with the hostel and room of the student's allocation and the live ledger position of each charge (collections are the shared Finance payment rows). Newest charge first.</p>
<div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
    <div class="rounded-xl bg-indigo-50 p-4"><p class="text-sm text-indigo-700">Charges</p><p class="text-2xl font-bold text-indigo-900">{{ number_format($totals['assignments']) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Assigned</p><p class="text-2xl font-bold text-slate-900">{{ number_format($totals['assigned'], 2) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Refunded</p><p class="text-2xl font-bold text-slate-900">{{ number_format($totals['refunded'], 2) }}</p></div>
    <div class="rounded-xl bg-emerald-50 p-4"><p class="text-sm text-emerald-700">Net collected</p><p class="text-2xl font-bold text-emerald-900">{{ number_format($totals['net_collected'], 2) }}</p></div>
    <div class="rounded-xl bg-amber-50 p-4"><p class="text-sm text-amber-700">Outstanding ({{ number_format($totals['outstanding_assignments']) }})</p><p class="text-2xl font-bold text-amber-900">{{ number_format($totals['outstanding'], 2) }}</p></div>
</div>
<div class="mt-5 overflow-x-auto">
    <table class="w-full text-left text-sm">
        <thead><tr class="border-b text-slate-500"><th class="py-3 pr-4">Student</th><th class="pr-4">Program / year</th><th class="pr-4">Hostel · room</th><th class="pr-4">Fee structure</th><th class="pr-4">Effective from</th><th class="pr-4 text-right">Amount</th><th class="pr-4 text-right">Paid</th><th class="pr-4 text-right">Refunded</th><th class="pr-4 text-right">Net collected</th><th class="pr-4 text-right">Outstanding</th><th>Status</th></tr></thead>
        <tbody>
        @forelse($rows as $row)
            @php($ledger = $row->ledger ?? [])
            @php($allocation = $row->hostelAllocation)
            <tr class="border-b align-top">
                <td class="py-3 pr-4 font-medium">{{ $allocation?->studentEnrollment?->student?->student_number ?? '—' }}<span class="block">{{ $allocation?->studentEnrollment?->student?->fullName() ?? 'Unknown student' }}</span></td>
                <td class="pr-4">{{ $allocation?->studentEnrollment?->program?->name ?? '—' }} · {{ $row->academicYear?->name ?? '—' }}</td>
                <td class="pr-4">{{ $allocation?->hostel?->name ?? '—' }}<span class="block text-xs text-slate-500">{{ $allocation?->room?->room_number ?? '—' }}</span></td>
                <td class="pr-4">{{ $row->feeStructure?->name ?? '—' }}</td>
                <td class="pr-4 whitespace-nowrap">{{ $row->effective_from?->format('d M Y') ?? '—' }}</td>
                <td class="pr-4 text-right font-medium">{{ number_format((float) $row->assigned_amount, 2) }}</td>
                <td class="pr-4 text-right">{{ number_format((float) ($ledger['paid'] ?? 0), 2) }}</td>
                <td class="pr-4 text-right">{{ number_format((float) ($ledger['refunded'] ?? 0), 2) }}</td>
                <td class="pr-4 text-right">{{ number_format((float) ($ledger['net_collected'] ?? 0), 2) }}</td>
                <td class="pr-4 text-right font-medium">{{ number_format((float) ($ledger['outstanding'] ?? 0), 2) }}</td>
                <td>{{ ucfirst($row->status) }}<span class="block text-xs text-slate-500">{{ ucfirst((string) ($ledger['status'] ?? '—')) }}</span></td>
            </tr>
        @empty
            <tr><td colspan="11" class="py-6 text-slate-500">No hostel fee charges match these filters.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@include('finance_reports._pagination', ['subject' => 'hostel fee charges'])
