<p class="panel-subtitle">Employee documents of the active college with their issue and expiry dates and the compliance state those dates imply. Only document metadata is reported — stored files are never linked or rendered here.</p>
<div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
    <div class="rounded-xl bg-indigo-50 p-4"><p class="text-sm text-indigo-700">Documents</p><p class="text-2xl font-bold text-indigo-900">{{ number_format($totals['documents']) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Employees covered</p><p class="text-2xl font-bold text-slate-900">{{ number_format($totals['employees']) }}</p></div>
    <div class="rounded-xl bg-rose-50 p-4"><p class="text-sm text-rose-700">Expired</p><p class="text-2xl font-bold text-rose-900">{{ number_format($totals['expired']) }}</p></div>
    <div class="rounded-xl bg-amber-50 p-4"><p class="text-sm text-amber-700">Expiring soon</p><p class="text-2xl font-bold text-amber-900">{{ number_format($totals['expiring']) }}</p></div>
    <div class="rounded-xl bg-emerald-50 p-4"><p class="text-sm text-emerald-700">Valid</p><p class="text-2xl font-bold text-emerald-900">{{ number_format($totals['valid']) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">No expiry date</p><p class="text-2xl font-bold text-slate-900">{{ number_format($totals['none']) }}</p></div>
</div>
<div class="mt-5 overflow-x-auto">
    <table class="w-full text-left text-sm">
        <thead><tr class="border-b text-slate-500"><th class="py-3 pr-4">Employee</th><th class="pr-4">Document</th><th class="pr-4">Type</th><th class="pr-4">Issued</th><th class="pr-4">Expires</th><th>Compliance state</th></tr></thead>
        <tbody>
        @forelse($rows as $row)
            <tr class="border-b align-top">
                <td class="py-3 pr-4 font-medium">{{ $row->employee?->employee_code ?? '—' }}<span class="block font-normal">{{ $row->employee?->full_name ?? 'Unknown employee' }}</span></td>
                <td class="pr-4">{{ $row->document_name }}</td>
                <td class="pr-4">{{ $row->document_type ?: '—' }}</td>
                <td class="pr-4 whitespace-nowrap">{{ $row->issue_date?->format('d M Y') ?? '—' }}</td>
                <td class="pr-4 whitespace-nowrap">{{ $row->expiry_date?->format('d M Y') ?? '—' }}</td>
                <td>{{ $stateLabels[$row->compliance_state] ?? '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="py-6 text-slate-500">No employee documents match these filters.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@include('hr_reports._pagination', ['subject' => 'documents'])
