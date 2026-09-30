@php
    $byType = $summary['by_type'] ?? collect();
@endphp

<p class="panel-subtitle">
    The certificate position of the active college, taken from the existing Certificate Summary: requests, generation,
    issuance and verification, with the type-wise breakdown. Counts are read live from the certificate records of the
    selected college.
</p>

<div class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
    <div class="stat-card"><p class="stat-label">Requests</p><p class="stat-value">{{ number_format($summary['total_requests']) }}</p><p class="stat-hint">{{ number_format($summary['total_types']) }} certificate types ({{ number_format($summary['active_types']) }} active)</p></div>
    <div class="stat-card"><p class="stat-label">Pending requests</p><p class="stat-value">{{ number_format($summary['pending_requests']) }}</p><p class="stat-hint">{{ number_format($summary['generated_certificates']) }} generated</p></div>
    <div class="stat-card"><p class="stat-label">Issued</p><p class="stat-value">{{ number_format($summary['total_issued']) }}</p></div>
    <div class="stat-card"><p class="stat-label">Verified</p><p class="stat-value">{{ number_format($summary['total_verified']) }}</p><p class="stat-hint">{{ number_format($summary['total_verification_lookups']) }} verification lookups</p></div>
</div>

<div class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
    <div class="stat-card"><p class="stat-label">Issued but not yet verified</p><p class="stat-value">{{ number_format($summary['unverified_issued']) }}</p></div>
</div>

<h4 class="mt-8 text-sm font-semibold text-slate-800">Counts by certificate type</h4>
<div class="mt-3 overflow-x-auto">
    <table class="w-full min-w-[760px] text-left text-sm">
        <thead class="border-b bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
            <tr>
                <th class="px-3 py-3">Certificate type</th>
                <th class="px-3 py-3">Code</th>
                <th class="px-3 py-3 text-right">Total</th>
                <th class="px-3 py-3 text-right">Requested</th>
                <th class="px-3 py-3 text-right">Generated</th>
                <th class="px-3 py-3 text-right">Issued</th>
                <th class="px-3 py-3 text-right">Verified</th>
            </tr>
        </thead>
        <tbody class="divide-y">
            @forelse($byType as $typeRow)
                <tr>
                    <td class="px-3 py-3 font-medium">{{ $typeRow->name }}</td>
                    <td class="px-3 py-3 font-mono text-xs">{{ $typeRow->code }}</td>
                    <td class="px-3 py-3 text-right font-mono">{{ number_format((int) ($typeRow->total_count ?? 0)) }}</td>
                    <td class="px-3 py-3 text-right font-mono">{{ number_format((int) ($typeRow->requested_count ?? 0)) }}</td>
                    <td class="px-3 py-3 text-right font-mono">{{ number_format((int) ($typeRow->generated_count ?? 0)) }}</td>
                    <td class="px-3 py-3 text-right font-mono">{{ number_format((int) ($typeRow->issued_count ?? 0)) }}</td>
                    <td class="px-3 py-3 text-right font-mono">{{ number_format((int) ($typeRow->verified_count ?? 0)) }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-3 py-8 text-center text-slate-500">No certificate types match these filters.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
