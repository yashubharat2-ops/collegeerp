<div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Total Requests</p>
        <p class="mt-1 text-2xl font-bold text-slate-900">{{ number_format($totals['total'] ?? 0) }}</p>
    </div>
    <div class="rounded-2xl border border-amber-200 bg-amber-50/50 p-4 shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-wider text-amber-700">Requested (Pending)</p>
        <p class="mt-1 text-2xl font-bold text-amber-900">{{ number_format($totals['requested'] ?? 0) }}</p>
    </div>
    <div class="rounded-2xl border border-indigo-200 bg-indigo-50/50 p-4 shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-wider text-indigo-700">Generated</p>
        <p class="mt-1 text-2xl font-bold text-indigo-900">{{ number_format($totals['generated'] ?? 0) }}</p>
    </div>
    <div class="rounded-2xl border border-emerald-200 bg-emerald-50/50 p-4 shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-wider text-emerald-700">Issued</p>
        <p class="mt-1 text-2xl font-bold text-emerald-900">{{ number_format($totals['issued'] ?? 0) }}</p>
    </div>
</div>

<div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">
                <tr>
                    <th class="px-4 py-3">Request / Certificate #</th>
                    <th class="px-4 py-3">Student</th>
                    <th class="px-4 py-3">Enrollment</th>
                    <th class="px-4 py-3">Academic Year / Program</th>
                    <th class="px-4 py-3">Certificate Type</th>
                    <th class="px-4 py-3">Request Date</th>
                    <th class="px-4 py-3">Request Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse($certificates as $certificate)
                <tr class="hover:bg-slate-50/80">
                    <td class="px-4 py-3">
                        <div class="font-semibold text-slate-900">#{{ $certificate->id }}</div>
                        @if($certificate->number)
                            <div class="text-xs font-medium text-indigo-700">{{ $certificate->number }}</div>
                        @endif
                        @if($certificate->purpose)
                            <div class="text-xs text-slate-500">Purpose: {{ $certificate->purpose }}</div>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        <div class="font-semibold text-slate-900">{{ $certificate->student?->fullName() ?? '—' }}</div>
                        <div class="text-xs text-slate-500">{{ $certificate->student?->student_number ?? '—' }}</div>
                    </td>
                    <td class="px-4 py-3 text-slate-700">
                        <div class="font-medium">{{ $certificate->enrollment?->enrollment_number ?? '—' }}</div>
                        @if($certificate->enrollment?->status)
                            <div class="text-xs text-slate-500 capitalize">{{ $certificate->enrollment->status }}</div>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-xs text-slate-600">
                        <div>{{ $certificate->enrollment?->academicYear?->name ?? '—' }}</div>
                        <div>
                            {{ $certificate->enrollment?->program?->name ?? '—' }}
                            @if($certificate->enrollment?->section?->name)
                                · {{ $certificate->enrollment->section->name }}
                            @endif
                        </div>
                    </td>
                    <td class="px-4 py-3">
                        <div class="font-medium text-slate-900">{{ $certificate->type?->name ?? '—' }}</div>
                        @if($certificate->type?->code)
                            <div class="text-xs text-slate-500">{{ $certificate->type->code }}</div>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-xs text-slate-600">
                        <div>{{ $certificate->created_at?->format('Y-m-d') ?? '—' }}</div>
                        @if($certificate->requester?->name)
                            <div class="text-slate-500">By {{ $certificate->requester->name }}</div>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold
                            {{ $certificate->status === 'issued' ? 'bg-emerald-100 text-emerald-800' : ($certificate->status === 'generated' ? 'bg-indigo-100 text-indigo-800' : 'bg-amber-100 text-amber-800') }}">
                            {{ ucfirst($certificate->status) }}
                        </span>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" class="px-4 py-10 text-center text-sm text-slate-500">No certificate requests match the selected filters.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @include('certificates.reports._pagination', ['certificates' => $certificates])
</div>
