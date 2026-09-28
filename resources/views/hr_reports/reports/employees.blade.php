<p class="panel-subtitle">Every staff / employee record of the active college with the department, designation, employment type and employee documents already recorded against it. Read-only — staff details are managed on the Staff / Employee screen.</p>
<div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
    <div class="rounded-xl bg-indigo-50 p-4"><p class="text-sm text-indigo-700">Staff records</p><p class="text-2xl font-bold text-indigo-900">{{ number_format($totals['staff']) }}</p></div>
    <div class="rounded-xl bg-emerald-50 p-4"><p class="text-sm text-emerald-700">Active</p><p class="text-2xl font-bold text-emerald-900">{{ number_format($totals['active']) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Inactive</p><p class="text-2xl font-bold text-slate-900">{{ number_format($totals['inactive']) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">With documents</p><p class="text-2xl font-bold text-slate-900">{{ number_format($totals['with_documents']) }}</p></div>
</div>
<div class="mt-5 overflow-x-auto">
    <table class="w-full text-left text-sm">
        <thead><tr class="border-b text-slate-500"><th class="py-3 pr-4">Staff / Employee</th><th class="pr-4">Employee code</th><th class="pr-4">Department</th><th class="pr-4">Designation</th><th class="pr-4">Employment type</th><th class="pr-4">Joining date</th><th class="pr-4">Status</th><th class="text-right">Documents</th></tr></thead>
        <tbody>
        @forelse($rows as $row)
            <tr class="border-b align-top">
                <td class="py-3 pr-4 font-medium">{{ $row->full_name }}<span class="block text-xs font-normal text-slate-500">{{ $row->email ?: '—' }}</span></td>
                <td class="pr-4">{{ $row->employee_code }}</td>
                <td class="pr-4">{{ $row->department?->name ?? '—' }}</td>
                <td class="pr-4">{{ $row->designationMaster?->name ?? $row->designation ?? '—' }}</td>
                <td class="pr-4">{{ $row->employment_type ? ucfirst(str_replace('_', ' ', $row->employment_type)) : '—' }}</td>
                <td class="pr-4 whitespace-nowrap">{{ $row->joining_date?->format('d M Y') ?? '—' }}</td>
                <td class="pr-4">{{ $row->status ? ucfirst($row->status) : '—' }}</td>
                <td class="text-right">{{ number_format((int) $row->documents_count) }}</td>
            </tr>
        @empty
            <tr><td colspan="8" class="py-6 text-slate-500">No staff match these filters.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@include('hr_reports._pagination', ['subject' => 'staff records'])
