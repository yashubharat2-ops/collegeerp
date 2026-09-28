<p class="panel-subtitle">Discounts / concessions recorded on student fee assignments, newest first. A rejected or cancelled concession stops reducing the payable amount, so the effective total below excludes it while the recorded total keeps the full audit trail.</p>
<div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
    <div class="rounded-xl bg-indigo-50 p-4"><p class="text-sm text-indigo-700">Concessions</p><p class="text-2xl font-bold text-indigo-900">{{ number_format($totals['concessions']) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Recorded value</p><p class="text-2xl font-bold text-slate-900">{{ number_format($totals['recorded'], 2) }}</p></div>
    <div class="rounded-xl bg-emerald-50 p-4"><p class="text-sm text-emerald-700">Applicable (effective)</p><p class="text-2xl font-bold text-emerald-900">{{ number_format($totals['effective'], 2) }}</p></div>
    @foreach($totals['by_status'] as $status => $count)
        <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">{{ ucfirst($status) }}</p><p class="text-2xl font-bold text-slate-900">{{ number_format($count) }}</p></div>
    @endforeach
</div>
<div class="mt-5 overflow-x-auto">
    <table class="w-full text-left text-sm">
        <thead><tr class="border-b text-slate-500"><th class="py-3 pr-4">Recorded</th><th class="pr-4">Student</th><th class="pr-4">Program / year</th><th class="pr-4">Fee structure</th><th class="pr-4">Type</th><th class="pr-4 text-right">Value</th><th class="pr-4 text-right">Amount</th><th class="pr-4">Status</th><th>Approved by</th></tr></thead>
        <tbody>
        @forelse($rows as $row)
            @php($assignment = $row->studentFeeAssignment)
            <tr class="border-b align-top">
                <td class="py-3 pr-4 font-medium whitespace-nowrap">{{ $row->created_at?->format('d M Y') ?? '—' }}</td>
                <td class="pr-4">{{ $assignment?->studentEnrollment?->student?->student_number ?? '—' }}<span class="block">{{ $assignment?->studentEnrollment?->student?->fullName() ?? 'Unknown student' }}</span></td>
                <td class="pr-4">{{ $assignment?->studentEnrollment?->program?->name ?? '—' }} · {{ $assignment?->studentEnrollment?->academicYear?->name ?? '—' }}</td>
                <td class="pr-4">{{ $assignment?->feeStructure?->name ?? '—' }}</td>
                <td class="pr-4">{{ ucfirst($row->type) }}</td>
                <td class="pr-4 text-right">{{ $row->type === \App\Models\FeeConcession::TYPE_PERCENTAGE ? rtrim(rtrim(number_format((float) $row->value, 2), '0'), '.').'%' : number_format((float) $row->value, 2) }}</td>
                <td class="pr-4 text-right font-medium">{{ number_format((float) $row->amount, 2) }}</td>
                <td class="pr-4">{{ ucfirst($row->status) }}<span class="block text-xs text-slate-500">{{ $row->reason ?: '—' }}</span></td>
                <td>{{ $row->approver?->name ?? '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="9" class="py-6 text-slate-500">No concessions match these filters.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@include('finance_reports._pagination', ['subject' => 'concessions'])
