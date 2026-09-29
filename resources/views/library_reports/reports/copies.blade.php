<p class="panel-subtitle">Every physical copy of the active college with the title it belongs to, where it is shelved, its condition and the status circulation last recorded for it. Read-only — copies are managed on the Book Copies screen.</p>
<div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
    <div class="rounded-xl bg-indigo-50 p-4"><p class="text-sm text-indigo-700">Copies</p><p class="text-2xl font-bold text-indigo-900">{{ number_format($totals['copies']) }}</p></div>
    <div class="rounded-xl bg-emerald-50 p-4"><p class="text-sm text-emerald-700">Available</p><p class="text-2xl font-bold text-emerald-900">{{ number_format($totals['available']) }}</p></div>
    <div class="rounded-xl bg-amber-50 p-4"><p class="text-sm text-amber-700">On loan</p><p class="text-2xl font-bold text-amber-900">{{ number_format($totals['on_loan']) }}</p></div>
    <div class="rounded-xl bg-rose-50 p-4"><p class="text-sm text-rose-700">Lost</p><p class="text-2xl font-bold text-rose-900">{{ number_format($totals['lost']) }}</p></div>
    <div class="rounded-xl bg-rose-50 p-4"><p class="text-sm text-rose-700">Damaged</p><p class="text-2xl font-bold text-rose-900">{{ number_format($totals['damaged']) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Withdrawn</p><p class="text-2xl font-bold text-slate-900">{{ number_format($totals['withdrawn']) }}</p></div>
</div>
<div class="mt-5 overflow-x-auto">
    <table class="w-full text-left text-sm">
        <thead><tr class="border-b text-slate-500"><th class="py-3 pr-4">Accession number</th><th class="pr-4">Book / Title</th><th class="pr-4">Category</th><th class="pr-4">Copy no.</th><th class="pr-4">Barcode</th><th class="pr-4">Location</th><th class="pr-4">Condition</th><th class="pr-4">Status</th><th class="text-right">Acquired</th></tr></thead>
        <tbody>
        @forelse($rows as $row)
            <tr class="border-b align-top">
                <td class="py-3 pr-4 font-medium">{{ $row->accession_number }}</td>
                <td class="pr-4">{{ $row->book?->title ?? '—' }}<span class="block text-xs text-slate-500">{{ $row->book?->code ?? '—' }}</span></td>
                <td class="pr-4">{{ $row->book?->category?->name ?? '—' }}</td>
                <td class="pr-4">{{ $row->copy_number }}</td>
                <td class="pr-4">{{ $row->barcode ?: '—' }}</td>
                <td class="pr-4">{{ $row->location ?: '—' }}</td>
                <td class="pr-4">{{ $row->condition ? ucfirst($row->condition) : '—' }}</td>
                <td class="pr-4">{{ ucfirst($row->status) }}</td>
                <td class="text-right whitespace-nowrap">{{ $row->acquired_on?->format('d M Y') ?? '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="9" class="py-6 text-slate-500">No book copies match these filters.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@include('library_reports._pagination', ['subject' => 'book copies'])
