@php
    $student = $summary['student_fees'];
    $transport = $summary['transport_fees'];
    $hostel = $summary['hostel_fees'];
    $collections = $summary['collections'];
    $concessions = $summary['concessions'];
    $refunds = $summary['refunds'];
    $reconciliation = $summary['reconciliation'];
    $ledgers = [
        'Student fees' => $student,
        'Transport fees' => $transport,
        'Hostel fees' => $hostel,
    ];
@endphp

<p class="panel-subtitle">
    The financial position of the active college for the selected academic year and program, taken from the existing
    Financial Summary: the student fee ledger, transport and hostel charges, all collections recorded through Finance,
    discounts / concessions and refunds. Balances come from the services that own them, so nothing is recalculated here.
</p>

<div class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
    <div class="stat-card"><p class="stat-label">Total fee value</p><p class="stat-value">{{ number_format((float) $summary['fee_value_total'], 2) }}</p></div>
    <div class="stat-card"><p class="stat-label">Net collected</p><p class="stat-value">{{ number_format((float) $summary['net_collected'], 2) }}</p></div>
    <div class="stat-card"><p class="stat-label">Outstanding</p><p class="stat-value">{{ number_format((float) $summary['outstanding_total'], 2) }}</p></div>
    <div class="stat-card"><p class="stat-label">Collections</p><p class="stat-value">{{ number_format($collections['payments']) }}</p><p class="stat-hint">Completed payment rows</p></div>
</div>

<h4 class="mt-8 text-sm font-semibold text-slate-800">Fee ledgers</h4>
<div class="mt-3 overflow-x-auto">
    <table class="w-full min-w-[880px] text-left text-sm">
        <thead class="border-b bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
            <tr>
                <th class="px-3 py-3">Ledger</th>
                <th class="px-3 py-3 text-right">Charges</th>
                <th class="px-3 py-3 text-right">Assigned</th>
                <th class="px-3 py-3 text-right">Concessions</th>
                <th class="px-3 py-3 text-right">Paid</th>
                <th class="px-3 py-3 text-right">Refunded</th>
                <th class="px-3 py-3 text-right">Net collected</th>
                <th class="px-3 py-3 text-right">Outstanding</th>
            </tr>
        </thead>
        <tbody class="divide-y">
            @foreach($ledgers as $label => $ledger)
                <tr>
                    <td class="px-3 py-3 font-medium">{{ $label }}</td>
                    <td class="px-3 py-3 text-right font-mono">{{ number_format($ledger['assignments']) }}</td>
                    <td class="px-3 py-3 text-right font-mono">{{ number_format((float) $ledger['assigned'], 2) }}</td>
                    <td class="px-3 py-3 text-right font-mono">{{ array_key_exists('concession', $ledger) ? number_format((float) $ledger['concession'], 2) : '—' }}</td>
                    <td class="px-3 py-3 text-right font-mono">{{ number_format((float) $ledger['paid'], 2) }}</td>
                    <td class="px-3 py-3 text-right font-mono">{{ number_format((float) $ledger['refunded'], 2) }}</td>
                    <td class="px-3 py-3 text-right font-mono">{{ number_format((float) $ledger['net_collected'], 2) }}</td>
                    <td class="px-3 py-3 text-right font-mono">{{ number_format((float) $ledger['outstanding'], 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>

<div class="mt-6 grid gap-5 lg:grid-cols-2">
    <div class="overflow-x-auto">
        <h4 class="text-sm font-semibold text-slate-800">Collections by fee type</h4>
        <table class="mt-3 w-full text-left text-sm">
            <thead class="border-b bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
                <tr><th class="px-3 py-3">Fee type</th><th class="px-3 py-3 text-right">Collections</th><th class="px-3 py-3 text-right">Amount</th></tr>
            </thead>
            <tbody class="divide-y">
                @forelse($collections['by_type'] as $row)
                    <tr>
                        <td class="px-3 py-3">{{ $row['fee_type'] === 'tuition' ? 'Tuition / student fees' : ucfirst($row['fee_type']) }}</td>
                        <td class="px-3 py-3 text-right font-mono">{{ number_format($row['payments']) }}</td>
                        <td class="px-3 py-3 text-right font-mono">{{ number_format((float) $row['total'], 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="px-3 py-6 text-slate-500">No collections match these filters.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="overflow-x-auto">
        <h4 class="text-sm font-semibold text-slate-800">Collections by payment mode</h4>
        <table class="mt-3 w-full text-left text-sm">
            <thead class="border-b bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
                <tr><th class="px-3 py-3">Mode</th><th class="px-3 py-3 text-right">Collections</th><th class="px-3 py-3 text-right">Amount</th></tr>
            </thead>
            <tbody class="divide-y">
                @forelse($collections['modes'] as $row)
                    <tr>
                        <td class="px-3 py-3">{{ ucfirst(str_replace('_', ' ', (string) $row['mode'])) }}</td>
                        <td class="px-3 py-3 text-right font-mono">{{ number_format($row['payments']) }}</td>
                        <td class="px-3 py-3 text-right font-mono">{{ number_format((float) $row['total'], 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="px-3 py-6 text-slate-500">No collections match these filters.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="mt-6 grid gap-4 sm:grid-cols-2">
    <div class="stat-card">
        <p class="stat-label">Discounts / concessions</p>
        <p class="stat-value">{{ number_format((float) $concessions['recorded'], 2) }}</p>
        <p class="stat-hint">{{ number_format($concessions['concessions']) }} recorded · {{ number_format((float) $concessions['effective'], 2) }} applicable</p>
    </div>
    <div class="stat-card">
        <p class="stat-label">Refunds</p>
        <p class="stat-value">{{ number_format((float) $refunds['recorded'], 2) }}</p>
        <p class="stat-hint">{{ number_format($refunds['refunds']) }} recorded · {{ number_format((float) $refunds['effective'], 2) }} effective</p>
    </div>
</div>

<div class="mt-6 rounded-xl {{ $reconciliation['balanced'] ? 'bg-emerald-50' : 'bg-amber-50' }} p-4">
    <p class="text-sm font-semibold {{ $reconciliation['balanced'] ? 'text-emerald-800' : 'text-amber-800' }}">Student fee ledger reconciliation</p>
    <p class="mt-1 text-sm {{ $reconciliation['balanced'] ? 'text-emerald-800' : 'text-amber-800' }}">
        Assigned {{ number_format((float) $reconciliation['assigned'], 2) }}
        − concessions {{ number_format((float) $reconciliation['concession'], 2) }}
        − net collected {{ number_format((float) $reconciliation['net_collected'], 2) }}
        = outstanding {{ number_format((float) $reconciliation['outstanding'], 2) }}
        (difference {{ number_format((float) $reconciliation['difference'], 2) }}).
    </p>
</div>
