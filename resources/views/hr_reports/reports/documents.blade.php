<p class="panel-subtitle">Every employee document stored against the staff of the active college, with the employee, document type and the status derived live from the stored expiry date. The status shown here is never written back to the document record.</p>
<div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
    <div class="rounded-xl bg-indigo-50 p-4"><p class="text-sm text-indigo-700">Documents</p><p class="text-2xl font-bold text-indigo-900">{{ number_format($totals['documents']) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Staff covered</p><p class="text-2xl font-bold text-slate-900">{{ number_format($totals['staff']) }}</p></div>
    <div class="rounded-xl bg-amber-50 p-4"><p class="text-sm text-amber-700">Expiring within 30 days</p><p class="text-2xl font-bold text-amber-900">{{ number_format($totals['expiring']) }}</p></div>
    <div class="rounded-xl bg-rose-50 p-4"><p class="text-sm text-rose-700">Expired</p><p class="text-2xl font-bold text-rose-900">{{ number_format($totals['expired']) }}</p></div>
</div>
<div class="mt-5 overflow-x-auto">
    <table class="w-full text-left text-sm">
        <thead><tr class="border-b text-slate-500"><th class="py-3 pr-4">Staff / Employee</th><th class="pr-4">Document</th><th class="pr-4">Type</th><th class="pr-4">Issued</th><th class="pr-4">Expires</th><th class="pr-4 text-right">Size (KB)</th><th>Status</th></tr></thead>
        <tbody>
        @forelse($rows as $row)
            <tr class="border-b align-top">
                <td class="py-3 pr-4 font-medium">{{ $row->employee?->full_name ?? 'Unknown employee' }}<span class="block text-xs font-normal text-slate-500">{{ $row->employee?->employee_code }}</span></td>
                <td class="pr-4">{{ $row->document_name }}<span class="block text-xs text-slate-500">{{ $row->original_filename }}</span></td>
                <td class="pr-4">{{ $row->documentTypeMaster?->name ?? $row->document_type ?? '—' }}</td>
                <td class="pr-4 whitespace-nowrap">{{ $row->issue_date?->format('d M Y') ?? '—' }}</td>
                <td class="pr-4 whitespace-nowrap">{{ $row->expiry_date?->format('d M Y') ?? 'No expiry' }}</td>
                <td class="pr-4 text-right">{{ number_format($row->sizeInKb(), 1) }}</td>
                <td>{{ ucfirst(\App\Domain\HR\Services\HrReportService::documentStatus($row)) }}</td>
            </tr>
        @empty
            <tr><td colspan="7" class="py-6 text-slate-500">No employee documents match these filters.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@include('hr_reports._pagination', ['subject' => 'documents'])
