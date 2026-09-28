<p class="panel-subtitle">Staff headcount per designation of the active college, counted live from the existing employee records. Staff held by no designation are reported separately in the tiles.</p>
<div class="mt-5 grid gap-3 sm:grid-cols-3">
    <div class="rounded-xl bg-indigo-50 p-4"><p class="text-sm text-indigo-700">Designations</p><p class="text-2xl font-bold text-indigo-900">{{ number_format($totals['designations']) }}</p></div>
    <div class="rounded-xl bg-emerald-50 p-4"><p class="text-sm text-emerald-700">Staff in these designations</p><p class="text-2xl font-bold text-emerald-900">{{ number_format($totals['staff']) }}</p></div>
    <div class="rounded-xl bg-amber-50 p-4"><p class="text-sm text-amber-700">Staff without a designation</p><p class="text-2xl font-bold text-amber-900">{{ number_format($totals['unassigned']) }}</p></div>
</div>
<div class="mt-5 overflow-x-auto">
    <table class="w-full text-left text-sm">
        <thead><tr class="border-b text-slate-500"><th class="py-3 pr-4">Designation</th><th class="pr-4">Status</th><th class="pr-4 text-right">Staff</th><th class="pr-4 text-right">Active</th><th class="text-right">Inactive</th></tr></thead>
        <tbody>
        @forelse($rows as $row)
            <tr class="border-b align-top">
                <td class="py-3 pr-4 font-medium">{{ $row->name }}<span class="block font-normal text-slate-500">{{ $row->code }}</span></td>
                <td class="pr-4">{{ ucfirst($row->status) }}</td>
                <td class="pr-4 text-right font-medium">{{ number_format((int) $row->employees_count) }}</td>
                <td class="pr-4 text-right">{{ number_format((int) $row->active_employees_count) }}</td>
                <td class="text-right">{{ number_format((int) $row->employees_count - (int) $row->active_employees_count) }}</td>
            </tr>
        @empty
            <tr><td colspan="5" class="py-6 text-slate-500">No designations match these filters.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@include('hr_reports._pagination', ['subject' => 'designations'])
