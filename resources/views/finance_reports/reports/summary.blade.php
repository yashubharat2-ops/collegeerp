@php
    $student = $summary['student_fees'];
    $transport = $summary['transport_fees'];
    $hostel = $summary['hostel_fees'];
    $collections = $summary['collections'];
    $concessions = $summary['concessions'];
    $refunds = $summary['refunds'];
    $reconciliation = $summary['reconciliation'];
@endphp
<p class="panel-subtitle">The financial position of the active college for the selected academic year and program, aggregated live from the existing fee records and services: the student fee ledger, all collections recorded through Finance, discounts / concessions, refunds, and the transport and hostel fee charges. No figure is recalculated here — every total comes from the same source the operational screens use.</p>
<div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
    <div class="rounded-xl bg-indigo-50 p-4"><p class="text-sm text-indigo-700">Total fee value</p><p class="text-2xl font-bold text-indigo-900">{{ number_format($summary['fee_value_total'], 2) }}</p></div>
    <div class="rounded-xl bg-emerald-50 p-4"><p class="text-sm text-emerald-700">Net collected</p><p class="text-2xl font-bold text-emerald-900">{{ number_format($summary['net_collected'], 2) }}</p></div>
    <div class="rounded-xl bg-amber-50 p-4"><p class="text-sm text-amber-700">Outstanding</p><p class="text-2xl font-bold text-amber-900">{{ number_format($summary['outstanding_total'], 2) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Collections</p><p class="text-2xl font-bold text-slate-900">{{ number_format($collections['payments']) }}</p></div>
</div>

<div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
    <div class="rounded-xl bg-slate-50 p-4">
        <p class="text-sm font-semibold text-slate-700">Student fees ({{ number_format($student['assignments']) }} assignments)</p>
        <dl class="mt-2 space-y-1 text-sm text-slate-700">
            <div class="flex justify-between gap-2"><dt>Assigned</dt><dd class="font-medium">{{ number_format($student['assigned'], 2) }}</dd></div>
            <div class="flex justify-between gap-2"><dt>Concessions</dt><dd class="font-medium">{{ number_format($student['concession'], 2) }}</dd></div>
            <div class="flex justify-between gap-2"><dt>Paid</dt><dd class="font-medium">{{ number_format($student['paid'], 2) }}</dd></div>
            <div class="flex justify-between gap-2"><dt>Refunded</dt><dd class="font-medium">{{ number_format($student['refunded'], 2) }}</dd></div>
            <div class="flex justify-between gap-2"><dt>Net collected</dt><dd class="font-medium">{{ number_format($student['net_collected'], 2) }}</dd></div>
            <div class="flex justify-between gap-2"><dt>Outstanding</dt><dd class="font-semibold">{{ number_format($student['outstanding'], 2) }}</dd></div>
        </dl>
    </div>
    <div class="rounded-xl bg-slate-50 p-4">
        <p class="text-sm font-semibold text-slate-700">Transport fees ({{ number_format($transport['assignments']) }} charges)</p>
        <dl class="mt-2 space-y-1 text-sm text-slate-700">
            <div class="flex justify-between gap-2"><dt>Assigned</dt><dd class="font-medium">{{ number_format($transport['assigned'], 2) }}</dd></div>
            <div class="flex justify-between gap-2"><dt>Paid</dt><dd class="font-medium">{{ number_format($transport['paid'], 2) }}</dd></div>
            <div class="flex justify-between gap-2"><dt>Refunded</dt><dd class="font-medium">{{ number_format($transport['refunded'], 2) }}</dd></div>
            <div class="flex justify-between gap-2"><dt>Net collected</dt><dd class="font-medium">{{ number_format($transport['net_collected'], 2) }}</dd></div>
            <div class="flex justify-between gap-2"><dt>Outstanding</dt><dd class="font-semibold">{{ number_format($transport['outstanding'], 2) }}</dd></div>
        </dl>
    </div>
    <div class="rounded-xl bg-slate-50 p-4">
        <p class="text-sm font-semibold text-slate-700">Hostel fees ({{ number_format($hostel['assignments']) }} charges)</p>
        <dl class="mt-2 space-y-1 text-sm text-slate-700">
            <div class="flex justify-between gap-2"><dt>Assigned</dt><dd class="font-medium">{{ number_format($hostel['assigned'], 2) }}</dd></div>
            <div class="flex justify-between gap-2"><dt>Paid</dt><dd class="font-medium">{{ number_format($hostel['paid'], 2) }}</dd></div>
            <div class="flex justify-between gap-2"><dt>Refunded</dt><dd class="font-medium">{{ number_format($hostel['refunded'], 2) }}</dd></div>
            <div class="flex justify-between gap-2"><dt>Net collected</dt><dd class="font-medium">{{ number_format($hostel['net_collected'], 2) }}</dd></div>
            <div class="flex justify-between gap-2"><dt>Outstanding</dt><dd class="font-semibold">{{ number_format($hostel['outstanding'], 2) }}</dd></div>
        </dl>
    </div>
    <div class="rounded-xl bg-slate-50 p-4">
        <p class="text-sm font-semibold text-slate-700">Discounts &amp; refunds</p>
        <dl class="mt-2 space-y-1 text-sm text-slate-700">
            <div class="flex justify-between gap-2"><dt>Concessions recorded ({{ number_format($concessions['concessions']) }})</dt><dd class="font-medium">{{ number_format($concessions['recorded'], 2) }}</dd></div>
            <div class="flex justify-between gap-2"><dt>Concessions applicable</dt><dd class="font-medium">{{ number_format($concessions['effective'], 2) }}</dd></div>
            <div class="flex justify-between gap-2"><dt>Refunds recorded ({{ number_format($refunds['refunds']) }})</dt><dd class="font-medium">{{ number_format($refunds['recorded'], 2) }}</dd></div>
            <div class="flex justify-between gap-2"><dt>Refunds effective</dt><dd class="font-medium">{{ number_format($refunds['effective'], 2) }}</dd></div>
        </dl>
    </div>
</div>

<div class="mt-5 grid gap-5 lg:grid-cols-2">
    <div class="overflow-x-auto">
        <p class="text-sm font-semibold text-slate-700">Collections by fee type</p>
        <table class="mt-2 w-full text-left text-sm">
            <thead><tr class="border-b text-slate-500"><th class="py-2 pr-4">Fee type</th><th class="pr-4 text-right">Collections</th><th class="text-right">Amount</th></tr></thead>
            <tbody>
            @forelse($collections['by_type'] as $row)
                <tr class="border-b"><td class="py-2 pr-4">{{ $row['fee_type'] === 'tuition' ? 'Tuition / student fees' : ucfirst($row['fee_type']) }}</td><td class="pr-4 text-right">{{ number_format($row['payments']) }}</td><td class="text-right font-medium">{{ number_format($row['total'], 2) }}</td></tr>
            @empty
                <tr><td colspan="3" class="py-4 text-slate-500">No collections match these filters.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="overflow-x-auto">
        <p class="text-sm font-semibold text-slate-700">Collections by payment mode</p>
        <table class="mt-2 w-full text-left text-sm">
            <thead><tr class="border-b text-slate-500"><th class="py-2 pr-4">Mode</th><th class="pr-4 text-right">Collections</th><th class="text-right">Amount</th></tr></thead>
            <tbody>
            @forelse($collections['modes'] as $row)
                <tr class="border-b"><td class="py-2 pr-4">{{ ucfirst(str_replace('_', ' ', $row['mode'])) }}</td><td class="pr-4 text-right">{{ number_format($row['payments']) }}</td><td class="text-right font-medium">{{ number_format($row['total'], 2) }}</td></tr>
            @empty
                <tr><td colspan="3" class="py-4 text-slate-500">No collections match these filters.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="mt-5 rounded-xl {{ $reconciliation['balanced'] ? 'bg-emerald-50' : 'bg-amber-50' }} p-4">
    <p class="text-sm font-semibold {{ $reconciliation['balanced'] ? 'text-emerald-800' : 'text-amber-800' }}">Student fee ledger reconciliation</p>
    <p class="mt-1 text-sm {{ $reconciliation['balanced'] ? 'text-emerald-800' : 'text-amber-800' }}">
        Assigned {{ number_format($reconciliation['assigned'], 2) }}
        − concessions {{ number_format($reconciliation['concession'], 2) }}
        − net collected {{ number_format($reconciliation['net_collected'], 2) }}
        = outstanding {{ number_format($reconciliation['outstanding'], 2) }}
        (difference {{ number_format($reconciliation['difference'], 2) }}).
    </p>
</div>
