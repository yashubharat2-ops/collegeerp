@php
    $staff = $summary['staff'];
    $master = $summary['master'];
    $documents = $summary['documents'];
    $attendance = $summary['attendance'];
    $leave = $summary['leave'];
    $payroll = $summary['payroll'];
    $reconciliation = $summary['reconciliation'];
@endphp
<p class="panel-subtitle">The HR position of the active college for the selected staff scope, aggregated live from the existing staff / employee records, departments, designations, employee documents, staff attendance, leave requests and payroll. No figure is recalculated here — every total comes from the same source the operational screens use.</p>
<div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
    <div class="rounded-xl bg-indigo-50 p-4"><p class="text-sm text-indigo-700">Staff records</p><p class="text-2xl font-bold text-indigo-900">{{ number_format($staff['total']) }}</p></div>
    <div class="rounded-xl bg-emerald-50 p-4"><p class="text-sm text-emerald-700">Active staff</p><p class="text-2xl font-bold text-emerald-900">{{ number_format($staff['active']) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Inactive staff</p><p class="text-2xl font-bold text-slate-900">{{ number_format($staff['inactive']) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Staff with documents</p><p class="text-2xl font-bold text-slate-900">{{ number_format($staff['with_documents']) }}</p></div>
</div>

<div class="mt-5 grid gap-5 lg:grid-cols-2">
    <div class="rounded-xl bg-slate-50 p-4">
        <p class="text-sm font-semibold text-slate-700">Master data ({{ number_format($master['departments']) }} departments · {{ number_format($master['designations']) }} designations)</p>
        <dl class="mt-2 space-y-1 text-sm text-slate-700">
            <div class="flex justify-between gap-2"><dt>Departments in the active college</dt><dd class="font-medium">{{ number_format($master['departments']) }}</dd></div>
            <div class="flex justify-between gap-2"><dt>Designations in the active college</dt><dd class="font-medium">{{ number_format($master['designations']) }}</dd></div>
        </dl>
    </div>
    <div class="rounded-xl bg-slate-50 p-4">
        <p class="text-sm font-semibold text-slate-700">Employee documents ({{ number_format($documents['documents']) }} records)</p>
        <dl class="mt-2 space-y-1 text-sm text-slate-700">
            <div class="flex justify-between gap-2"><dt>Staff covered</dt><dd class="font-medium">{{ number_format($documents['staff']) }}</dd></div>
            <div class="flex justify-between gap-2"><dt>Expiring within 30 days</dt><dd class="font-medium">{{ number_format($documents['expiring']) }}</dd></div>
            <div class="flex justify-between gap-2"><dt>Expired</dt><dd class="font-semibold">{{ number_format($documents['expired']) }}</dd></div>
        </dl>
    </div>
    <div class="rounded-xl bg-slate-50 p-4">
        <p class="text-sm font-semibold text-slate-700">Staff attendance ({{ number_format($attendance['records']) }} entries)</p>
        <dl class="mt-2 space-y-1 text-sm text-slate-700">
            <div class="flex justify-between gap-2"><dt>Present</dt><dd class="font-medium">{{ number_format($attendance['present']) }}</dd></div>
            <div class="flex justify-between gap-2"><dt>Late</dt><dd class="font-medium">{{ number_format($attendance['late']) }}</dd></div>
            <div class="flex justify-between gap-2"><dt>Absent</dt><dd class="font-medium">{{ number_format($attendance['absent']) }}</dd></div>
            <div class="flex justify-between gap-2"><dt>On leave</dt><dd class="font-medium">{{ number_format($attendance['leave']) }}</dd></div>
            <div class="flex justify-between gap-2"><dt>Holiday</dt><dd class="font-medium">{{ number_format($attendance['holiday']) }}</dd></div>
            <div class="flex justify-between gap-2"><dt>Attendance rate</dt><dd class="font-semibold">{{ number_format($attendance['rate'], 1) }}%</dd></div>
        </dl>
    </div>
    <div class="rounded-xl bg-slate-50 p-4">
        <p class="text-sm font-semibold text-slate-700">Leave ({{ number_format($leave['requests']) }} requests · {{ number_format($leave['days']) }} days)</p>
        <dl class="mt-2 space-y-1 text-sm text-slate-700">
            <div class="flex justify-between gap-2"><dt>Approved</dt><dd class="font-medium">{{ number_format($leave['approved']) }} ({{ number_format($leave['approved_days']) }} days)</dd></div>
            <div class="flex justify-between gap-2"><dt>Pending</dt><dd class="font-medium">{{ number_format($leave['pending']) }}</dd></div>
            <div class="flex justify-between gap-2"><dt>Rejected</dt><dd class="font-medium">{{ number_format($leave['rejected']) }}</dd></div>
            <div class="flex justify-between gap-2"><dt>Cancelled</dt><dd class="font-medium">{{ number_format($leave['cancelled']) }}</dd></div>
        </dl>
    </div>
    <div class="rounded-xl bg-slate-50 p-4">
        <p class="text-sm font-semibold text-slate-700">Payroll ({{ number_format($payroll['payrolls']) }} records)</p>
        <dl class="mt-2 space-y-1 text-sm text-slate-700">
            <div class="flex justify-between gap-2"><dt>Basic total</dt><dd class="font-medium">{{ number_format($payroll['basic'], 2) }}</dd></div>
            <div class="flex justify-between gap-2"><dt>Gross total</dt><dd class="font-medium">{{ number_format($payroll['gross'], 2) }}</dd></div>
            <div class="flex justify-between gap-2"><dt>Deductions</dt><dd class="font-medium">{{ number_format($payroll['deductions'], 2) }}</dd></div>
            <div class="flex justify-between gap-2"><dt>Net total</dt><dd class="font-semibold">{{ number_format($payroll['net'], 2) }}</dd></div>
        </dl>
    </div>
    <div class="rounded-xl {{ $reconciliation['difference'] === 0.0 ? 'bg-emerald-50' : 'bg-amber-50' }} p-4">
        <p class="text-sm font-semibold {{ $reconciliation['difference'] === 0.0 ? 'text-emerald-800' : 'text-amber-800' }}">Payroll reconciliation</p>
        <p class="mt-1 text-sm {{ $reconciliation['difference'] === 0.0 ? 'text-emerald-800' : 'text-amber-800' }}">
            Gross {{ number_format($payroll['gross'], 2) }} − deductions {{ number_format($payroll['deductions'], 2) }} = net {{ number_format($payroll['net'], 2) }} (difference {{ number_format($reconciliation['difference'], 2) }}).
        </p>
    </div>
</div>

<div class="mt-5 grid gap-5 lg:grid-cols-2">
    <div class="overflow-x-auto">
        <p class="text-sm font-semibold text-slate-700">Staff by department</p>
        <table class="mt-2 w-full text-left text-sm">
            <thead><tr class="border-b text-slate-500"><th class="py-2 pr-4">Department</th><th class="pr-4 text-right">Staff</th><th class="pr-4 text-right">Active</th></tr></thead>
            <tbody>
            @forelse($summary['department_breakdown'] as $row)
                <tr class="border-b"><td class="py-2 pr-4">{{ $row['department'] }}@if($row['code']) ({{ $row['code'] }})@endif</td><td class="pr-4 text-right">{{ number_format($row['staff']) }}</td><td class="pr-4 text-right">{{ number_format($row['active']) }}</td></tr>
            @empty
                <tr><td colspan="3" class="py-4 text-slate-500">No staff are assigned to departments for these filters.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="overflow-x-auto">
        <p class="text-sm font-semibold text-slate-700">Leave days by leave type</p>
        <table class="mt-2 w-full text-left text-sm">
            <thead><tr class="border-b text-slate-500"><th class="py-2 pr-4">Leave type</th><th class="pr-4 text-right">Requests</th><th class="text-right">Days</th></tr></thead>
            <tbody>
            @forelse($leave['by_type'] as $row)
                <tr class="border-b"><td class="py-2 pr-4">{{ $row['leave_type'] }}</td><td class="pr-4 text-right">{{ number_format($row['requests']) }}</td><td class="text-right font-medium">{{ number_format($row['days']) }}</td></tr>
            @empty
                <tr><td colspan="3" class="py-4 text-slate-500">No leave days recorded for these filters.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="mt-5 overflow-x-auto">
    <p class="text-sm font-semibold text-slate-700">Payroll by status</p>
    <table class="mt-2 w-full text-left text-sm">
        <thead><tr class="border-b text-slate-500"><th class="py-2 pr-4">Status</th><th class="pr-4 text-right">Payroll records</th><th class="text-right">Net</th></tr></thead>
        <tbody>
        @foreach($payroll['by_status'] as $row)
            <tr class="border-b"><td class="py-2 pr-4">{{ ucfirst($row['status']) }}</td><td class="pr-4 text-right">{{ number_format($row['payrolls']) }}</td><td class="text-right font-medium">{{ number_format($row['net'], 2) }}</td></tr>
        @endforeach
        </tbody>
    </table>
</div>
