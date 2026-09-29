<p class="panel-subtitle">Every issue, return and lost-copy record of the active college with the copy, title, member and renewal count already recorded against it. Read-only — circulation is managed on the Issue / Return screen.</p>
<div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
    <div class="rounded-xl bg-indigo-50 p-4"><p class="text-sm text-indigo-700">Circulation records</p><p class="text-2xl font-bold text-indigo-900">{{ number_format($totals['issues']) }}</p></div>
    <div class="rounded-xl bg-amber-50 p-4"><p class="text-sm text-amber-700">Issued (open)</p><p class="text-2xl font-bold text-amber-900">{{ number_format($totals['issued']) }}</p></div>
    <div class="rounded-xl bg-emerald-50 p-4"><p class="text-sm text-emerald-700">Returned</p><p class="text-2xl font-bold text-emerald-900">{{ number_format($totals['returned']) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Lost</p><p class="text-2xl font-bold text-slate-900">{{ number_format($totals['lost']) }}</p></div>
    <div class="rounded-xl bg-rose-50 p-4"><p class="text-sm text-rose-700">Open and overdue</p><p class="text-2xl font-bold text-rose-900">{{ number_format($totals['open_overdue']) }}</p></div>
    <div class="rounded-xl bg-rose-50 p-4"><p class="text-sm text-rose-700">Returned late</p><p class="text-2xl font-bold text-rose-900">{{ number_format($totals['returned_late']) }}</p></div>
</div>
<p class="mt-3 text-xs text-slate-500">{{ number_format($totals['renewals']) }} renewal{{ $totals['renewals'] === 1 ? '' : 's' }} recorded against these circulation records.</p>
<div class="mt-5 overflow-x-auto">
    <table class="w-full text-left text-sm">
        <thead><tr class="border-b text-slate-500"><th class="py-3 pr-4">Book / Title</th><th class="pr-4">Accession number</th><th class="pr-4">Member</th><th class="pr-4">Issued</th><th class="pr-4">Due</th><th class="pr-4">Returned</th><th class="pr-4">Status</th><th class="text-right">Renewals</th></tr></thead>
        <tbody>
        @forelse($rows as $row)
            <tr class="border-b align-top">
                <td class="py-3 pr-4 font-medium">{{ $row->bookCopy?->book?->title ?? '—' }}<span class="block text-xs font-normal text-slate-500">{{ $row->bookCopy?->book?->category?->name ?? '—' }}</span></td>
                <td class="pr-4">{{ $row->bookCopy?->accession_number ?? '—' }}</td>
                <td class="pr-4">{{ $row->libraryMember?->studentName() ?? '—' }}<span class="block text-xs text-slate-500">{{ $row->libraryMember?->member_code ?? '' }}</span></td>
                <td class="pr-4 whitespace-nowrap">{{ $row->issued_on?->format('d M Y') ?? '—' }}</td>
                <td class="pr-4 whitespace-nowrap">{{ $row->due_on?->format('d M Y') ?? '—' }}</td>
                <td class="pr-4 whitespace-nowrap">{{ $row->returned_on?->format('d M Y') ?? '—' }}</td>
                <td class="pr-4">{{ ucfirst($row->status) }}</td>
                <td class="text-right">{{ number_format((int) $row->renewals_count) }}</td>
            </tr>
        @empty
            <tr><td colspan="8" class="py-6 text-slate-500">No issue / return records match these filters.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@include('library_reports._pagination', ['subject' => 'circulation records'])
