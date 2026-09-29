<div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Verifiable Certificates</p>
        <p class="mt-1 text-2xl font-bold text-slate-900">{{ number_format($totals['total'] ?? 0) }}</p>
    </div>
    <div class="rounded-2xl border border-emerald-200 bg-emerald-50/50 p-4 shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-wider text-emerald-700">Verified</p>
        <p class="mt-1 text-2xl font-bold text-emerald-900">{{ number_format($totals['verified'] ?? 0) }}</p>
    </div>
    <div class="rounded-2xl border border-amber-200 bg-amber-50/50 p-4 shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-wider text-amber-700">Unverified</p>
        <p class="mt-1 text-2xl font-bold text-amber-900">{{ number_format($totals['unverified'] ?? 0) }}</p>
    </div>
    <div class="rounded-2xl border border-indigo-200 bg-indigo-50/50 p-4 shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-wider text-indigo-700">Total Verification Lookups</p>
        <p class="mt-1 text-2xl font-bold text-indigo-900">{{ number_format($totals['total_lookups'] ?? 0) }}</p>
    </div>
</div>

<div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">
                <tr>
                    <th class="px-4 py-3">Certificate Number</th>
                    <th class="px-4 py-3">Student</th>
                    <th class="px-4 py-3">Certificate Type</th>
                    <th class="px-4 py-3">Verification Status</th>
                    <th class="px-4 py-3">Verification Date &amp; Details</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse($certificates as $certificate)
                @php $verified = $certificate->isVerified(); @endphp
                <tr class="hover:bg-slate-50/80">
                    <td class="px-4 py-3">
                        <div class="font-semibold text-slate-900">{{ $certificate->number ?? '#' . $certificate->id }}</div>
                        <div class="text-xs text-slate-500">Issued: {{ $certificate->issued_at?->format('Y-m-d') ?? '—' }}</div>
                    </td>
                    <td class="px-4 py-3">
                        <div class="font-semibold text-slate-900">{{ $certificate->student?->fullName() ?? '—' }}</div>
                        <div class="text-xs text-slate-500">
                            {{ $certificate->student?->student_number ?? '—' }}
                            @if($certificate->enrollment?->enrollment_number)
                                · {{ $certificate->enrollment->enrollment_number }}
                            @endif
                        </div>
                    </td>
                    <td class="px-4 py-3">
                        <div class="font-medium text-slate-900">{{ $certificate->type?->name ?? '—' }}</div>
                        @if($certificate->type?->code)
                            <div class="text-xs text-slate-500">{{ $certificate->type->code }}</div>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold
                            {{ $verified ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-700' }}">
                            {{ $verified ? 'Verified' : 'Unverified' }}
                        </span>
                    </td>
                    <td class="px-4 py-3 text-xs text-slate-600">
                        <div>Last Verified: {{ $certificate->last_verified_at?->format('Y-m-d H:i') ?? 'Not yet verified' }}</div>
                        <div>
                            Verification Count: <span class="font-semibold text-slate-800">{{ (int) $certificate->verification_count }}</span>
                            @if($certificate->lastVerifier?->name)
                                · By {{ $certificate->lastVerifier->name }}
                            @endif
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="5" class="px-4 py-10 text-center text-sm text-slate-500">No certificate verification records match the selected filters.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @include('certificates.reports._pagination', ['certificates' => $certificates])
</div>
