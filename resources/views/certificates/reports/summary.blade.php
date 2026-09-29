<div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Total Certificate Requests</p>
        <p class="mt-2 text-3xl font-bold text-slate-900">{{ number_format($summary['total_requests'] ?? 0) }}</p>
        <p class="mt-2 text-xs text-slate-600">
            {{ number_format($summary['pending_requests'] ?? 0) }} requested ·
            {{ number_format($summary['generated_certificates'] ?? 0) }} generated ·
            {{ number_format($summary['total_issued'] ?? 0) }} issued
        </p>
    </div>

    <div class="rounded-2xl border border-amber-200 bg-amber-50/40 p-5 shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-wider text-amber-700">Pending Requests</p>
        <p class="mt-2 text-3xl font-bold text-amber-900">{{ number_format($summary['pending_requests'] ?? 0) }}</p>
        <p class="mt-2 text-xs text-amber-800">
            {{ number_format($summary['generated_certificates'] ?? 0) }} generated awaiting issuance
        </p>
    </div>

    <div class="rounded-2xl border border-emerald-200 bg-emerald-50/40 p-5 shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-wider text-emerald-700">Total Issued Certificates</p>
        <p class="mt-2 text-3xl font-bold text-emerald-900">{{ number_format($summary['total_issued'] ?? 0) }}</p>
        <p class="mt-2 text-xs text-emerald-800">
            {{ number_format($summary['total_verified'] ?? 0) }} verified ·
            {{ number_format($summary['unverified_issued'] ?? 0) }} unverified
        </p>
    </div>

    <div class="rounded-2xl border border-indigo-200 bg-indigo-50/40 p-5 shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-wider text-indigo-700">Total Verified Certificates</p>
        <p class="mt-2 text-3xl font-bold text-indigo-900">{{ number_format($summary['total_verified'] ?? 0) }}</p>
        <p class="mt-2 text-xs text-indigo-800">
            {{ number_format($summary['total_verification_lookups'] ?? 0) }} total verification lookups
        </p>
    </div>
</div>

<div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
    <div class="border-b border-slate-100 px-5 py-4">
        <h2 class="text-sm font-semibold text-slate-900">Counts by Certificate Type</h2>
    </div>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">
                <tr>
                    <th class="px-4 py-3">Certificate Type</th>
                    <th class="px-4 py-3">Code</th>
                    <th class="px-4 py-3">Total Requests</th>
                    <th class="px-4 py-3">Pending (Requested)</th>
                    <th class="px-4 py-3">Generated</th>
                    <th class="px-4 py-3">Issued</th>
                    <th class="px-4 py-3">Verified</th>
                    <th class="px-4 py-3">Report Link</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse($summary['by_type'] ?? [] as $typeRow)
                <tr class="hover:bg-slate-50/80">
                    <td class="px-4 py-3 font-semibold text-slate-900">{{ $typeRow->name }}</td>
                    <td class="px-4 py-3 font-mono text-xs text-slate-600">{{ $typeRow->code }}</td>
                    <td class="px-4 py-3 font-semibold text-slate-900">{{ number_format((int) ($typeRow->total_count ?? 0)) }}</td>
                    <td class="px-4 py-3 text-amber-800">{{ number_format((int) ($typeRow->requested_count ?? 0)) }}</td>
                    <td class="px-4 py-3 text-indigo-800">{{ number_format((int) ($typeRow->generated_count ?? 0)) }}</td>
                    <td class="px-4 py-3 text-emerald-800">{{ number_format((int) ($typeRow->issued_count ?? 0)) }}</td>
                    <td class="px-4 py-3 text-sky-800">{{ number_format((int) ($typeRow->verified_count ?? 0)) }}</td>
                    <td class="px-4 py-3">
                        <a href="{{ route('certificate-reports.index', ['report' => 'requests', 'certificate_type_id' => $typeRow->id, 'from' => $filters['from'] ?? null, 'to' => $filters['to'] ?? null]) }}"
                           class="font-semibold text-indigo-600 hover:underline">
                            View Requests →
                        </a>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="8" class="px-4 py-10 text-center text-sm text-slate-500">No certificate types configured for this college.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
