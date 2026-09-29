<p class="panel-subtitle">Every library membership of the active college with the student enrollment it references, its validity and the live loan counts. Read-only — memberships are managed on the Library Members screen.</p>
<div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
    <div class="rounded-xl bg-indigo-50 p-4"><p class="text-sm text-indigo-700">Members</p><p class="text-2xl font-bold text-indigo-900">{{ number_format($totals['members']) }}</p></div>
    <div class="rounded-xl bg-emerald-50 p-4"><p class="text-sm text-emerald-700">Active</p><p class="text-2xl font-bold text-emerald-900">{{ number_format($totals['active']) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Inactive</p><p class="text-2xl font-bold text-slate-900">{{ number_format($totals['inactive']) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Suspended / expired</p><p class="text-2xl font-bold text-slate-900">{{ number_format($totals['suspended'] + $totals['expired']) }}</p></div>
</div>
<div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
    <div class="rounded-xl bg-amber-50 p-4"><p class="text-sm text-amber-700">Past expiry date</p><p class="text-2xl font-bold text-amber-900">{{ number_format($totals['past_expiry']) }}</p></div>
    <div class="rounded-xl bg-amber-50 p-4"><p class="text-sm text-amber-700">Members with open issues</p><p class="text-2xl font-bold text-amber-900">{{ number_format($totals['with_open_issues']) }}</p></div>
    <div class="rounded-xl bg-rose-50 p-4"><p class="text-sm text-rose-700">Open (unreturned) copies</p><p class="text-2xl font-bold text-rose-900">{{ number_format($totals['open_issues']) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Expired status</p><p class="text-2xl font-bold text-slate-900">{{ number_format($totals['expired']) }}</p></div>
</div>
<div class="mt-5 overflow-x-auto">
    <table class="w-full text-left text-sm">
        <thead><tr class="border-b text-slate-500"><th class="py-3 pr-4">Member code</th><th class="pr-4">Student</th><th class="pr-4">Enrollment</th><th class="pr-4">Program</th><th class="pr-4">Status</th><th class="pr-4">Valid until</th><th class="pr-4 text-right">Loans</th><th class="pr-4 text-right">Open issues</th><th class="text-right">Overdue issues</th></tr></thead>
        <tbody>
        @forelse($rows as $row)
            <tr class="border-b align-top">
                <td class="py-3 pr-4 font-medium">{{ $row->member_code }}</td>
                <td class="pr-4">{{ $row->studentEnrollment?->student?->fullName() ?? '—' }}<span class="block text-xs text-slate-500">{{ $row->studentEnrollment?->student?->student_number ?? '' }}</span></td>
                <td class="pr-4">{{ $row->studentEnrollment?->enrollment_number ?? '—' }}</td>
                <td class="pr-4">{{ $row->studentEnrollment?->program?->name ?? '—' }}</td>
                <td class="pr-4">{{ ucfirst($row->status) }}</td>
                <td class="pr-4 whitespace-nowrap">{{ $row->expiry_date?->format('d M Y') ?? '—' }}</td>
                <td class="pr-4 text-right">{{ number_format((int) $row->transactions_count) }}</td>
                <td class="pr-4 text-right">{{ number_format((int) $row->open_issues_count) }}</td>
                <td class="text-right">{{ number_format((int) $row->overdue_issues_count) }}</td>
            </tr>
        @empty
            <tr><td colspan="9" class="py-6 text-slate-500">No library members match these filters.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@include('library_reports._pagination', ['subject' => 'library members'])
