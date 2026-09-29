<p class="panel-subtitle">Copies the Library has marked lost or damaged, with their title, where they sit in the catalogue and the last circulation recorded against them. Read-only — copy status is managed on the Book Copies screen, and any fine raised for a lost copy is written by the existing fine service, not by this report.</p>
<div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
    <div class="rounded-xl bg-rose-50 p-4"><p class="text-sm text-rose-700">Lost / damaged copies</p><p class="text-2xl font-bold text-rose-900">{{ number_format($totals['copies']) }}</p></div>
    <div class="rounded-xl bg-rose-50 p-4"><p class="text-sm text-rose-700">Lost</p><p class="text-2xl font-bold text-rose-900">{{ number_format($totals['lost']) }}</p></div>
    <div class="rounded-xl bg-rose-50 p-4"><p class="text-sm text-rose-700">Damaged</p><p class="text-2xl font-bold text-rose-900">{{ number_format($totals['damaged']) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Titles affected</p><p class="text-2xl font-bold text-slate-900">{{ number_format($totals['titles']) }}</p></div>
</div>
<div class="mt-5 overflow-x-auto">
    <table class="w-full text-left text-sm">
        <thead><tr class="border-b text-slate-500"><th class="py-3 pr-4">Accession number</th><th class="pr-4">Book / Title</th><th class="pr-4">Category</th><th class="pr-4">Status</th><th class="pr-4">Condition</th><th class="pr-4">Last member</th><th class="pr-4">Last issued</th><th class="text-right">Last returned</th></tr></thead>
        <tbody>
        @forelse($rows as $row)
            @php $last = $row->transactions->first(); @endphp
            <tr class="border-b align-top">
                <td class="py-3 pr-4 font-medium">{{ $row->accession_number }}</td>
                <td class="pr-4">{{ $row->book?->title ?? '—' }}<span class="block text-xs text-slate-500">{{ $row->book?->code ?? '—' }}</span></td>
                <td class="pr-4">{{ $row->book?->category?->name ?? '—' }}</td>
                <td class="pr-4">{{ ucfirst($row->status) }}</td>
                <td class="pr-4">{{ $row->condition ? ucfirst($row->condition) : '—' }}</td>
                <td class="pr-4">{{ $last?->libraryMember?->studentName() ?? '—' }}<span class="block text-xs text-slate-500">{{ $last?->libraryMember?->member_code ?? '' }}</span></td>
                <td class="pr-4 whitespace-nowrap">{{ $last?->issued_on?->format('d M Y') ?? '—' }}</td>
                <td class="text-right whitespace-nowrap">{{ $last?->returned_on?->format('d M Y') ?? '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="8" class="py-6 text-slate-500">No lost or damaged copies match these filters.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@include('library_reports._pagination', ['subject' => 'lost / damaged copies'])
