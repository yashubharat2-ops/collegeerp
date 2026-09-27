<p class="panel-subtitle">Transfer requests and TC issuance, including rejected and cancelled requests. Academic filters use the linked enrollment, when present.</p>
<div class="mt-5 overflow-x-auto">
    <table class="w-full text-left text-sm">
        <thead><tr class="border-b text-slate-500"><th class="py-3 pr-4">Student</th><th class="pr-4">Enrollment</th><th class="pr-4">Transfer date / destination</th><th class="pr-4">Request</th><th>TC number / status</th></tr></thead>
        <tbody>
        @forelse($rows as $transfer)
            <tr class="border-b align-top">
                <td class="py-3 pr-4"><a class="font-semibold text-indigo-700 hover:underline" href="{{ route('student-reports.profile', $transfer->student) }}">{{ $transfer->student->student_number }}</a><span class="block">{{ $transfer->student->fullName() }}</span></td>
                <td class="pr-4">{{ $transfer->enrollment?->enrollment_number ?? '—' }}<span class="block text-xs text-slate-500">{{ $transfer->enrollment?->academicYear?->name ?? '—' }} · {{ $transfer->enrollment?->program?->name ?? 'No program' }} · {{ $transfer->enrollment?->section?->name ?? 'No section' }}</span></td>
                <td class="pr-4">{{ $transfer->transfer_date?->format('d M Y') ?? '—' }}<span class="block text-xs text-slate-500">{{ $transfer->destination_institution ?? 'No destination recorded' }}</span></td>
                <td class="pr-4">{{ ucfirst($transfer->status) }}</td>
                <td>{{ $transfer->tc_number ?? '—' }}<span class="block text-xs text-slate-500">{{ ucfirst($transfer->tc_status) }} · Issued {{ $transfer->tc_issue_date?->format('d M Y') ?? '—' }}</span></td>
            </tr>
        @empty
            <tr><td colspan="5" class="py-6 text-slate-500">No transfer / TC records match these filters.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@include('student_reports._pagination', ['subject' => 'transfers'])
