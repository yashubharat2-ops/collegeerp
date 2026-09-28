<p class="panel-subtitle">Every staff member of the active college with their department, designation, employment type and status. Inactive staff stay listed — they are part of the record — and can be excluded with the status filter. The tiles count the whole filtered set, not just this page.</p>
<div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
    <div class="rounded-xl bg-indigo-50 p-4"><p class="text-sm text-indigo-700">Staff</p><p class="text-2xl font-bold text-indigo-900">{{ number_format($totals['employees']) }}</p></div>
    <div class="rounded-xl bg-emerald-50 p-4"><p class="text-sm text-emerald-700">Active</p><p class="text-2xl font-bold text-emerald-900">{{ number_format($totals['active']) }}</p></div>
    <div class="rounded-xl bg-amber-50 p-4"><p class="text-sm text-amber-700">Inactive</p><p class="text-2xl font-bold text-amber-900">{{ number_format($totals['inactive']) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Departments covered</p><p class="text-2xl font-bold text-slate-900">{{ number_format($totals['departments']) }}</p></div>
</div>
<div class="mt-5 overflow-x-auto">
    <table class="w-full text-left text-sm">
        <thead><tr class="border-b text-slate-500"><th class="py-3 pr-4">Employee</th><th class="pr-4">Department</th><th class="pr-4">Designation</th><th class="pr-4">Employment type</th><th class="pr-4">Status</th><th class="pr-4">Joined</th><th>Email</th></tr></thead>
        <tbody>
        @forelse($rows as $row)
            <tr class="border-b align-top">
                <td class="py-3 pr-4 font-medium">{{ $row->employee_code }}<span class="block font-normal">{{ $row->full_name }}</span></td>
                <td class="pr-4">{{ $row->department?->name ?? '—' }}</td>
                <td class="pr-4">{{ $row->designationMaster?->name ?? $row->designation ?? '—' }}</td>
                <td class="pr-4">{{ $row->employment_type ? ucfirst(str_replace('_', ' ', $row->employment_type)) : '—' }}</td>
                <td class="pr-4">{{ ucfirst($row->status) }}</td>
                <td class="pr-4 whitespace-nowrap">{{ $row->joining_date?->format('d M Y') ?? '—' }}</td>
                <td>{{ $row->email ?: '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="7" class="py-6 text-slate-500">No staff match these filters.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@include('hr_reports._pagination', ['subject' => 'staff members'])
