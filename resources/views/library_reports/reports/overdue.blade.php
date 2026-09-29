<p class="panel-subtitle">Copies that were due back before today and are still issued, were returned after their due date, or were lost after it. The overdue definition is the one the Library already applies — a copy is overdue while it is issued past its due date. Nothing is recalculated here and no fine is raised by this report.</p>
<div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
    <div class="rounded-xl bg-rose-50 p-4"><p class="text-sm text-rose-700">Overdue records</p><p class="text-2xl font-bold text-rose-900">{{ number_format($totals['overdue']) }}</p></div>
    <div class="rounded-xl bg-amber-50 p-4"><p class="text-sm text-amber-700">Still issued today</p><p class="text-2xl font-bold text-amber-900">{{ number_format($totals['still_issued']) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Returned late</p><p class="text-2xl font-bold text-slate-900">{{ number_format($totals['returned_late']) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Lost</p><p class="text-2xl font-bold text-slate-900">{{ number_format($totals['lost']) }}</p></div>
</div>
<div class="mt-5 overflow-x-auto">
    <table class="w-full text-left text-sm">
        <thead><tr class="border-b text-slate-500"><th class="py-3 pr-4">Book / Title</th><th class="pr-4">Accession number</th><th class="pr-4">Member</th><th class="pr-4">Due</th><th class="pr-4">Returned / status</th><th class="pr-4 text-right">Days overdue</th><th class="pr-4">Status</th></tr></thead>
        <tbody>
        @forelse($rows as $row)
            <tr class="border-b align-top">
                <td class="py-3 pr-4 font-medium">{{ $row->bookCopy?->book?->title ?? '—' }}<span class="block text-xs font-normal text-slate-500">{{ $row->bookCopy?->book?->code ?? '—' }}</span></td>
                <td class="pr-4">{{ $row->bookCopy?->accession_number ?? '—' }}</td>
                <td class="pr-4">{{ $row->libraryMember?->studentName() ?? '—' }}<span class="block text-xs text-slate-500">{{ $row->libraryMember?->member_code ?? '' }}</span></td>
                <td class="pr-4 whitespace-nowrap">{{ $row->due_on?->format('d M Y') ?? '—' }}</td>
                <td class="pr-4 whitespace-nowrap">{{ $row->returned_on?->format('d M Y') ?? 'Still issued' }}</td>
                <td class="pr-4 text-right">{{ number_format(\App\Domain\Library\Services\LibraryReportService::daysOverdue($row)) }}</td>
                <td class="pr-4">{{ ucfirst($row->status) }}</td>
            </tr>
        @empty
            <tr><td colspan="7" class="py-6 text-slate-500">No overdue copies match these filters.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@include('library_reports._pagination', ['subject' => 'overdue records'])
