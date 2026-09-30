@php
    $staff = $summary['staff'];
    $documents = $summary['documents'];
    $attendance = $summary['attendance'];
    $leave = $summary['leave'];
    $payroll = $summary['payroll'];
    $reconciliation = $summary['reconciliation'];
@endphp

<p class="panel-subtitle">
    The HR position of the active college for the selected staff scope, taken from the existing HR Summary: staff
    records, employee documents, attendance, leave and payroll, with the payroll reconciliation. Every total comes from
    the records the operational screens use.
</p>

<div class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
    <div class="stat-card"><p class="stat-label">Staff records</p><p class="stat-value">{{ number_format($staff['total']) }}</p></div>
    <div class="stat-card"><p class="stat-label">Active staff</p><p class="stat-value">{{ number_format($staff['active']) }}</p></div>
    <div class="stat-card"><p class="stat-label">Staff with documents</p><p class="stat-value">{{ number_format($staff['with_documents']) }}</p></div>
    <div class="stat-card">
        <p class="stat-label">Net payroll</p>
        <p class="stat-value">{{ number_format((float) $payroll['net'], 2) }}</p>
        <p class="stat-hint">{{ number_format($payroll['payrolls']) }} payroll records</p>
    </div>
</div>

<div class="mt-6 grid gap-5 lg:grid-cols-2">
    <div class="stat-card">
        <p class="stat-label">Employee documents ({{ number_format($documents['documents']) }} records)</p>
        <dl class="mt-3 space-y-1 text-sm text-slate-700">
            <div class="flex justify-between gap-2"><dt>Staff covered</dt><dd class="font-medium">{{ number_format($documents['staff']) }}</dd></div>
            <div class="flex justify-between gap-2"><dt>Expiring within 30 days</dt><dd class="font-medium">{{ number_format($documents['expiring']) }}</dd></div>
            <div class="flex justify-between gap-2"><dt>Expired</dt><dd class="font-semibold">{{ number_format($documents['expired']) }}</dd></div>
        </dl>
    </div>
    <div class="stat-card">
        <p class="stat-label">Staff attendance ({{ number_format($attendance['records']) }} entries)</p>
        <dl class="mt-3 space-y-1 text-sm text-slate-700">
            <div class="flex justify-between gap-2"><dt>Present</dt><dd class="font-medium">{{ number_format($attendance['present']) }}</dd></div>
            <div class="flex justify-between gap-2"><dt>Late</dt><dd class="font-medium">{{ number_format($attendance['late']) }}</dd></div>
            <div class="flex justify-between gap-2"><dt>Absent</dt><dd class="font-medium">{{ number_format($attendance['absent']) }}</dd></div>
            <div class="flex justify-between gap-2"><dt>On leave</dt><dd class="font-medium">{{ number_format($attendance['leave']) }}</dd></div>
            <div class="flex justify-between gap-2"><dt>Attendance rate</dt><dd class="font-semibold">{{ number_format((float) $attendance['rate'], 1) }}%</dd></div>
        </dl>
    </div>
    <div class="stat-card">
        <p class="stat-label">Leave ({{ number_format($leave['requests']) }} requests · {{ number_format((float) $leave['days'], 1) }} days)</p>
        <dl class="mt-3 space-y-1 text-sm text-slate-700">
            <div class="flex justify-between gap-2"><dt>Approved</dt><dd class="font-medium">{{ number_format($leave['approved']) }}</dd></div>
            <div class="flex justify-between gap-2"><dt>Pending</dt><dd class="font-medium">{{ number_format($leave['pending']) }}</dd></div>
            <div class="flex justify-between gap-2"><dt>Rejected</dt><dd class="font-medium">{{ number_format($leave['rejected']) }}</dd></div>
            <div class="flex justify-between gap-2"><dt>Cancelled</dt><dd class="font-medium">{{ number_format($leave['cancelled']) }}</dd></div>
        </dl>
    </div>
    <div class="stat-card">
        <p class="stat-label">Payroll ({{ number_format($payroll['payrolls']) }} records)</p>
        <dl class="mt-3 space-y-1 text-sm text-slate-700">
            <div class="flex justify-between gap-2"><dt>Basic total</dt><dd class="font-medium">{{ number_format((float) $payroll['basic'], 2) }}</dd></div>
            <div class="flex justify-between gap-2"><dt>Gross total</dt><dd class="font-medium">{{ number_format((float) $payroll['gross'], 2) }}</dd></div>
            <div class="flex justify-between gap-2"><dt>Deductions</dt><dd class="font-medium">{{ number_format((float) $payroll['deductions'], 2) }}</dd></div>
            <div class="flex justify-between gap-2"><dt>Net total</dt><dd class="font-semibold">{{ number_format((float) $payroll['net'], 2) }}</dd></div>
            <div class="flex justify-between gap-2"><dt>Reconciliation difference</dt><dd class="font-semibold">{{ number_format((float) $reconciliation['difference'], 2) }}</dd></div>
        </dl>
    </div>
</div>

<div class="mt-6 grid gap-5 lg:grid-cols-2">
    <div class="overflow-x-auto">
        <h4 class="text-sm font-semibold text-slate-800">Staff by department</h4>
        <table class="mt-3 w-full text-left text-sm">
            <thead class="border-b bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
                <tr><th class="px-3 py-3">Department</th><th class="px-3 py-3 text-right">Staff</th><th class="px-3 py-3 text-right">Active</th></tr>
            </thead>
            <tbody class="divide-y">
                @forelse($summary['department_breakdown'] as $row)
                    <tr>
                        <td class="px-3 py-3">{{ $row['department'] }}@if($row['code']) <span class="font-mono text-xs text-slate-500">{{ $row['code'] }}</span>@endif</td>
                        <td class="px-3 py-3 text-right font-mono">{{ number_format($row['staff']) }}</td>
                        <td class="px-3 py-3 text-right font-mono">{{ number_format($row['active']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="px-3 py-6 text-slate-500">No staff are assigned to departments for these filters.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="overflow-x-auto">
        <h4 class="text-sm font-semibold text-slate-800">Payroll by status</h4>
        <table class="mt-3 w-full text-left text-sm">
            <thead class="border-b bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
                <tr><th class="px-3 py-3">Status</th><th class="px-3 py-3 text-right">Payroll records</th><th class="px-3 py-3 text-right">Net</th></tr>
            </thead>
            <tbody class="divide-y">
                @forelse($payroll['by_status'] as $row)
                    <tr>
                        <td class="px-3 py-3">{{ ucfirst((string) $row['status']) }}</td>
                        <td class="px-3 py-3 text-right font-mono">{{ number_format($row['payrolls']) }}</td>
                        <td class="px-3 py-3 text-right font-mono">{{ number_format((float) $row['net'], 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="px-3 py-6 text-slate-500">No payroll records match these filters.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
