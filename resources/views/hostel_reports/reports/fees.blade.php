<p class="panel-subtitle">Assigned, paid, refunded, net-collected and outstanding figures come from the existing HostelFeeService / shared FeeLedger over Finance payments and refunds. Date filters overlap fee-assignment effective dates. Cancelled fee assignments are excluded from payable totals, consistent with the existing Hostel Fee logic.</p>

<h4 class="mt-5 text-sm font-semibold text-slate-800">Hostel Fee Summary</h4>
<div class="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
    <div class="stat-card"><p class="stat-label">Assigned</p><p class="stat-value">{{ number_format((float) $feeReport['totals']['assigned'], 2) }}</p></div>
    <div class="stat-card"><p class="stat-label">Paid</p><p class="stat-value">{{ number_format((float) $feeReport['totals']['net_collected'], 2) }}</p><p class="stat-hint">Gross {{ number_format((float) $feeReport['totals']['paid'], 2) }}</p></div>
    <div class="stat-card"><p class="stat-label">Refunded</p><p class="stat-value">{{ number_format((float) $feeReport['totals']['refunded'], 2) }}</p></div>
    <div class="stat-card"><p class="stat-label">Outstanding / due</p><p class="stat-value">{{ number_format((float) $feeReport['totals']['outstanding'], 2) }}</p></div>
    <div class="stat-card"><p class="stat-label">Assignments</p><p class="stat-value">{{ $feeReport['totals']['assignments'] }}</p><p class="stat-hint">{{ $feeReport['totals']['outstanding_assignments'] }} with a balance</p></div>
</div>

<h4 class="mt-8 text-sm font-semibold text-slate-800">Fee totals by hostel</h4>
<div class="mt-3 overflow-x-auto">
    <table class="w-full min-w-[900px] text-left text-sm">
        <thead class="border-b bg-slate-50 text-slate-500"><tr>
            <th class="px-3 py-3">Hostel</th><th class="px-3 py-3">Assignments</th><th class="px-3 py-3">Assigned</th><th class="px-3 py-3">Paid</th><th class="px-3 py-3">Refunded</th><th class="px-3 py-3">Net collected</th><th class="px-3 py-3">Outstanding</th>
        </tr></thead>
        <tbody class="divide-y">
            @forelse($feeReport['hostels'] as $row)
                <tr>
                    <td class="px-3 py-3 font-medium">{{ $row['hostel']->name }}</td>
                    <td class="px-3 py-3">{{ $row['assignments'] }}</td>
                    <td class="px-3 py-3">{{ number_format((float) $row['assigned'], 2) }}</td>
                    <td class="px-3 py-3">{{ number_format((float) $row['paid'], 2) }}</td>
                    <td class="px-3 py-3">{{ number_format((float) $row['refunded'], 2) }}</td>
                    <td class="px-3 py-3">{{ number_format((float) $row['net_collected'], 2) }}</td>
                    <td class="px-3 py-3">{{ number_format((float) $row['outstanding'], 2) }}</td>
                </tr>
            @empty
                <tr><td class="px-3 py-8 text-center text-slate-500" colspan="7">No hostel fee assignments match these filters.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<h4 class="mt-8 text-sm font-semibold text-slate-800">Fee assignment ledger</h4>
<div class="mt-3 overflow-x-auto">
    <table class="w-full min-w-[1320px] text-left text-sm">
        <thead class="border-b bg-slate-50 text-slate-500"><tr>
            <th class="px-3 py-3">Student / enrollment</th><th class="px-3 py-3">Class / section</th><th class="px-3 py-3">Hostel / room / bed</th><th class="px-3 py-3">Fee structure</th><th class="px-3 py-3">Effective period</th><th class="px-3 py-3">Assigned</th><th class="px-3 py-3">Paid</th><th class="px-3 py-3">Refunded</th><th class="px-3 py-3">Net collected</th><th class="px-3 py-3">Due</th><th class="px-3 py-3">Ledger / assignment status</th>
        </tr></thead>
        <tbody class="divide-y">
            @forelse($feeReport['rows'] as $assignment)
                @php($ledger = $assignment->ledger ?? [])
                @php($allocation = $assignment->hostelAllocation)
                @php($enrollment = $allocation?->studentEnrollment)
                @php($student = $enrollment?->student)
                <tr>
                    <td class="px-3 py-3 font-medium">{{ $student?->student_number ?? '—' }}<span class="block">{{ $student?->fullName() ?? 'Unknown student' }}</span><span class="block text-xs text-slate-500">{{ $enrollment?->enrollment_number ?? '—' }} · {{ $enrollment?->academicYear?->name ?? $assignment->academicYear?->name ?? '—' }}</span></td>
                    <td class="px-3 py-3">{{ $enrollment?->program?->name ?? '—' }} / {{ $enrollment?->section?->name ?? '—' }}</td>
                    <td class="px-3 py-3">{{ $allocation?->hostel?->name ?? '—' }}<span class="block text-xs text-slate-500">{{ $allocation?->room?->room_number ?? '—' }} / {{ $allocation?->bed?->bed_number ?? '—' }}</span></td>
                    <td class="px-3 py-3">{{ $assignment->feeStructure?->name ?? '—' }}<span class="block text-xs text-slate-500">{{ $assignment->feeStructure?->code ?? '' }}</span></td>
                    <td class="px-3 py-3 whitespace-nowrap">{{ $assignment->effective_from?->format('d M Y') ?? '—' }} – {{ $assignment->effective_until?->format('d M Y') ?? 'Open' }}</td>
                    <td class="px-3 py-3">{{ number_format((float) ($ledger['assigned'] ?? $assignment->assigned_amount), 2) }}</td>
                    <td class="px-3 py-3">{{ number_format((float) ($ledger['paid'] ?? 0), 2) }}</td>
                    <td class="px-3 py-3">{{ number_format((float) ($ledger['refunded'] ?? 0), 2) }}</td>
                    <td class="px-3 py-3">{{ number_format((float) ($ledger['net_collected'] ?? 0), 2) }}</td>
                    <td class="px-3 py-3 font-semibold">{{ number_format((float) ($ledger['outstanding'] ?? 0), 2) }}</td>
                    <td class="px-3 py-3">{{ ucfirst($ledger['status'] ?? '—') }}<span class="block text-xs text-slate-500">Assignment: {{ ucfirst($assignment->status) }}</span></td>
                </tr>
            @empty
                <tr><td class="px-3 py-8 text-center text-slate-500" colspan="11">No hostel fee assignments match these filters.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<div class="no-print mt-4 flex flex-wrap items-center justify-between gap-3 text-xs text-slate-500">
    <span>Showing {{ $feeReport['rows']->firstItem() ?? 0 }}–{{ $feeReport['rows']->lastItem() ?? 0 }} of {{ $feeReport['rows']->total() }} fee assignments.</span>
    {{ $feeReport['rows']->links() }}
</div>
