@php
    $books = $summary['books'];
    $copies = $summary['copies'];
    $members = $summary['members'];
    $circulation = $summary['circulation'];
    $fines = $summary['fines'];
@endphp

<p class="panel-subtitle">
    The library position of the active college, taken from the existing Library Summary: catalogue, copies, memberships,
    circulation, renewals and the stored fines. Copy statuses and fine statuses are the stored ones — nothing is
    re-derived here.
</p>

<div class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
    <div class="stat-card"><p class="stat-label">Titles</p><p class="stat-value">{{ number_format($books['total']) }}</p><p class="stat-hint">{{ number_format($books['active']) }} active</p></div>
    <div class="stat-card"><p class="stat-label">Copies</p><p class="stat-value">{{ number_format($copies['total']) }}</p><p class="stat-hint">{{ number_format($copies['available']) }} available</p></div>
    <div class="stat-card"><p class="stat-label">Members</p><p class="stat-value">{{ number_format($members['total']) }}</p><p class="stat-hint">{{ number_format($members['active']) }} active</p></div>
    <div class="stat-card"><p class="stat-label">On loan</p><p class="stat-value">{{ number_format($circulation['issued']) }}</p><p class="stat-hint">{{ number_format($circulation['overdue']) }} overdue</p></div>
    <div class="stat-card"><p class="stat-label">Outstanding fines</p><p class="stat-value">{{ number_format((float) $fines['outstanding'], 2) }}</p><p class="stat-hint">{{ number_format($fines['fines']) }} fine records</p></div>
</div>

<div class="mt-6 grid gap-5 lg:grid-cols-2">
    <div class="overflow-x-auto">
        <h4 class="text-sm font-semibold text-slate-800">Copies by status</h4>
        <table class="mt-3 w-full text-left text-sm">
            <thead class="border-b bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
                <tr><th class="px-3 py-3">Status</th><th class="px-3 py-3 text-right">Copies</th></tr>
            </thead>
            <tbody class="divide-y">
                <tr><td class="px-3 py-3">Available</td><td class="px-3 py-3 text-right font-mono">{{ number_format($copies['available']) }}</td></tr>
                <tr><td class="px-3 py-3">Issued / on loan</td><td class="px-3 py-3 text-right font-mono">{{ number_format($copies['issued']) }}</td></tr>
                <tr><td class="px-3 py-3">Lost</td><td class="px-3 py-3 text-right font-mono">{{ number_format($copies['lost']) }}</td></tr>
                <tr><td class="px-3 py-3">Damaged</td><td class="px-3 py-3 text-right font-mono">{{ number_format($copies['damaged']) }}</td></tr>
                <tr><td class="px-3 py-3">Withdrawn</td><td class="px-3 py-3 text-right font-mono">{{ number_format($copies['withdrawn']) }}</td></tr>
            </tbody>
        </table>
    </div>
    <div class="overflow-x-auto">
        <h4 class="text-sm font-semibold text-slate-800">Circulation</h4>
        <table class="mt-3 w-full text-left text-sm">
            <thead class="border-b bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
                <tr><th class="px-3 py-3">Measure</th><th class="px-3 py-3 text-right">Count</th></tr>
            </thead>
            <tbody class="divide-y">
                <tr><td class="px-3 py-3">Issues recorded</td><td class="px-3 py-3 text-right font-mono">{{ number_format($circulation['issues']) }}</td></tr>
                <tr><td class="px-3 py-3">Currently issued</td><td class="px-3 py-3 text-right font-mono">{{ number_format($circulation['issued']) }}</td></tr>
                <tr><td class="px-3 py-3">Returned</td><td class="px-3 py-3 text-right font-mono">{{ number_format($circulation['returned']) }}</td></tr>
                <tr><td class="px-3 py-3">Overdue</td><td class="px-3 py-3 text-right font-mono">{{ number_format($circulation['overdue']) }}</td></tr>
                <tr><td class="px-3 py-3">Renewals</td><td class="px-3 py-3 text-right font-mono">{{ number_format($circulation['renewals']) }}</td></tr>
            </tbody>
        </table>
    </div>
</div>

<h4 class="mt-8 text-sm font-semibold text-slate-800">Fines by status</h4>
<div class="mt-3 overflow-x-auto">
    <table class="w-full min-w-[560px] text-left text-sm">
        <thead class="border-b bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
            <tr><th class="px-3 py-3">Status</th><th class="px-3 py-3 text-right">Fines</th><th class="px-3 py-3 text-right">Assessed</th><th class="px-3 py-3 text-right">Paid</th></tr>
        </thead>
        <tbody class="divide-y">
            @forelse($fines['by_status'] as $row)
                <tr>
                    <td class="px-3 py-3">{{ ucfirst(str_replace('_', ' ', (string) $row->status)) }}</td>
                    <td class="px-3 py-3 text-right font-mono">{{ number_format((int) $row->total) }}</td>
                    <td class="px-3 py-3 text-right font-mono">{{ number_format((float) $row->assessed, 2) }}</td>
                    <td class="px-3 py-3 text-right font-mono">{{ number_format((float) $row->paid, 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="px-3 py-6 text-slate-500">No library fines are recorded.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
