<div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
    <div class="rounded-2xl border border-emerald-200 bg-emerald-50/50 p-4 shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-wider text-emerald-700">Total Issued Certificates</p>
        <p class="mt-1 text-2xl font-bold text-emerald-900">{{ number_format($totals['total_issued'] ?? 0) }}</p>
    </div>
    <div class="rounded-2xl border border-indigo-200 bg-indigo-50/50 p-4 shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-wider text-indigo-700">Verified Issued Certificates</p>
        <p class="mt-1 text-2xl font-bold text-indigo-900">{{ number_format($totals['verified_issued'] ?? 0) }}</p>
    </div>
    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-wider text-slate-600">Unverified Issued Certificates</p>
        <p class="mt-1 text-2xl font-bold text-slate-900">{{ number_format($totals['unverified_issued'] ?? 0) }}</p>
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
                    <th class="px-4 py-3">Issue Date</th>
                    <th class="px-4 py-3">Issued Status &amp; Details</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse($certificates as $certificate)
                <tr class="hover:bg-slate-50/80">
                    <td class="px-4 py-3">
                        <div class="font-semibold text-slate-900">{{ $certificate->number ?? '#' . $certificate->id }}</div>
                        <div class="text-xs text-slate-500">Request #{{ $certificate->id }}</div>
                    </td>
                    <td class="px-4 py-3">
                        <div class="font-semibold text-slate-900">{{ $certificate->student?->fullName() ?? '—' }}</div>
                        <div class="text-xs text-slate-500">
                            {{ $certificate->student?->student_number ?? '—' }}
                            @if($certificate->enrollment?->enrollment_number)
                                · {{ $certificate->enrollment->enrollment_number }}
                            @endif
                        </div>
                        @if($certificate->enrollment?->academicYear?->name || $certificate->enrollment?->program?->name)
                            <div class="text-xs text-slate-500">
                                {{ $certificate->enrollment?->academicYear?->name ?? '' }}
                                {{ $certificate->enrollment?->program?->name ? '· ' . $certificate->enrollment->program->name : '' }}
                            </div>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        <div class="font-medium text-slate-900">{{ $certificate->type?->name ?? '—' }}</div>
                        @if($certificate->type?->code)
                            <div class="text-xs text-slate-500">{{ $certificate->type->code }}</div>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-xs text-slate-600">
                        <div>{{ $certificate->issued_at?->format('Y-m-d') ?? $certificate->created_at?->format('Y-m-d') ?? '—' }}</div>
                        @if($certificate->issuer?->name)
                            <div class="text-slate-500">By {{ $certificate->issuer->name }}</div>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-xs text-slate-700">
                        <div class="flex flex-wrap items-center gap-1.5">
                            <span class="inline-flex rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-semibold text-emerald-800">
                                Issued
                            </span>
                            @if($certificate->template?->name)
                                <span class="text-slate-500">Template: {{ $certificate->template->name }}</span>
                            @endif
                        </div>
                        @if($certificate->purpose)
                            <div class="mt-1 text-slate-500">Purpose: {{ $certificate->purpose }}</div>
                        @endif
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="5" class="px-4 py-10 text-center text-sm text-slate-500">No issued certificates match the selected filters.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @include('certificates.reports._pagination', ['certificates' => $certificates])
</div>
