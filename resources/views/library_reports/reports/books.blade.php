<p class="panel-subtitle">Every catalogued book of the active college with the category, publisher, credited authors and the live copy counts already recorded against it. Read-only — titles are managed on the Books screen.</p>
<div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
    <div class="rounded-xl bg-indigo-50 p-4"><p class="text-sm text-indigo-700">Books</p><p class="text-2xl font-bold text-indigo-900">{{ number_format($totals['books']) }}</p></div>
    <div class="rounded-xl bg-emerald-50 p-4"><p class="text-sm text-emerald-700">Active</p><p class="text-2xl font-bold text-emerald-900">{{ number_format($totals['active']) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Inactive</p><p class="text-2xl font-bold text-slate-900">{{ number_format($totals['inactive']) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Without copies</p><p class="text-2xl font-bold text-slate-900">{{ number_format($totals['without_copies']) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Copies of these books</p><p class="text-2xl font-bold text-slate-900">{{ number_format($totals['copies']) }}</p></div>
</div>
<div class="mt-5 overflow-x-auto">
    <table class="w-full text-left text-sm">
        <thead><tr class="border-b text-slate-500"><th class="py-3 pr-4">Title</th><th class="pr-4">Code / ISBN</th><th class="pr-4">Category</th><th class="pr-4">Author(s)</th><th class="pr-4">Publisher</th><th class="pr-4">Language</th><th class="pr-4">Status</th><th class="pr-4 text-right">Copies</th><th class="pr-4 text-right">Available</th><th class="text-right">Issued</th></tr></thead>
        <tbody>
        @forelse($rows as $row)
            <tr class="border-b align-top">
                <td class="py-3 pr-4 font-medium">{{ $row->title }}<span class="block text-xs font-normal text-slate-500">{{ $row->edition ? 'Edition '.$row->edition : '—' }}{{ $row->publication_year ? ' · '.$row->publication_year : '' }}</span></td>
                <td class="pr-4">{{ $row->code }}<span class="block text-xs text-slate-500">{{ $row->isbn ?: 'No ISBN' }}</span></td>
                <td class="pr-4">{{ $row->category?->name ?? '—' }}</td>
                <td class="pr-4">{{ $row->authors->isNotEmpty() ? $row->authors->pluck('name')->join(', ') : '—' }}</td>
                <td class="pr-4">{{ $row->publisher?->name ?? '—' }}</td>
                <td class="pr-4">{{ $row->language ?: '—' }}</td>
                <td class="pr-4">{{ ucfirst($row->status) }}</td>
                <td class="pr-4 text-right">{{ number_format((int) $row->copies_count) }}</td>
                <td class="pr-4 text-right">{{ number_format((int) $row->available_copies_count) }}</td>
                <td class="text-right">{{ number_format((int) $row->issued_copies_count) }}</td>
            </tr>
        @empty
            <tr><td colspan="10" class="py-6 text-slate-500">No books match these filters.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@include('library_reports._pagination', ['subject' => 'books'])
