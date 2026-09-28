<p class="panel-subtitle">Each existing staff department of the active college with the live counts of the staff records filed under it. The counts honour the staff filters of this report; departments without matching staff are shown with zeros.</p>
<div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
    <div class="rounded-xl bg-indigo-50 p-4"><p class="text-sm text-indigo-700">Departments (this page)</p><p class="text-2xl font-bold text-indigo-900">{{ number_format($totals['departments']) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Staff (this page)</p><p class="text-2xl font-bold text-slate-900">{{ number_format($totals['staff']) }}</p></div>
    <div class="rounded-xl bg-emerald-50 p-4"><p class="text-sm text-emerald-700">Active staff</p><p class="text-2xl font-bold text-emerald-900">{{ number_format($totals['active']) }}</p></div>
    <div class="rounded-xl bg-amber-50 p-4"><p class="text-sm text-amber-700">With documents</p><p class="text-2xl font-bold text-amber-900">{{ number_format($totals['with_documents']) }}</p></div>
</div>
<div class="mt-5 overflow-x-auto">
    <table class="w-full text-left text-sm">
        <thead><tr class="border-b text-slate-500"><th class="py-3 pr-4">Department</th><th class="pr-4">Code</th><th class="pr-4 text-right">Staff</th><th class="pr-4 text-right">Active</th><th class="pr-4 text-right">Inactive</th><th class="text-right">With documents</th></tr></thead>
        <tbody>
        @forelse($rows as $row)
            <tr class="border-b align-top">
                <td class="py-3 pr-4 font-medium">{{ $row->name }}</td>
                <td class="pr-4">{{ $row->code ?: '—' }}</td>
                <td class="pr-4 text-right font-semibold">{{ number_format((int) $row->staff_count) }}</td>
                <td class="pr-4 text-right">{{ number_format((int) $row->active_staff_count) }}</td>
                <td class="pr-4 text-right">{{ number_format((int) $row->inactive_staff_count) }}</td>
                <td class="text-right">{{ number_format((int) $row->documented_staff_count) }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="py-6 text-slate-500">No staff departments match these filters.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@include('hr_reports._pagination', ['subject' => 'departments'])
