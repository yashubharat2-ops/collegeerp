<p class="panel-subtitle">Every recorded staff attendance entry of the active college, newest date first, with the day counts of the whole filtered range. The attendance rate counts present and late days over all non-holiday entries in the set.</p>
<div class="mt-5 grid gap-3 sm:grid-cols-3 lg:grid-cols-4">
    <div class="rounded-xl bg-indigo-50 p-4"><p class="text-sm text-indigo-700">Entries</p><p class="text-2xl font-bold text-indigo-900">{{ number_format($totals['records']) }}</p></div>
    <div class="rounded-xl bg-emerald-50 p-4"><p class="text-sm text-emerald-700">Present</p><p class="text-2xl font-bold text-emerald-900">{{ number_format($totals['present']) }}</p></div>
    <div class="rounded-xl bg-amber-50 p-4"><p class="text-sm text-amber-700">Late</p><p class="text-2xl font-bold text-amber-900">{{ number_format($totals['late']) }}</p></div>
    <div class="rounded-xl bg-rose-50 p-4"><p class="text-sm text-rose-700">Absent</p><p class="text-2xl font-bold text-rose-900">{{ number_format($totals['absent']) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">On leave</p><p class="text-2xl font-bold text-slate-900">{{ number_format($totals['leave']) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Holiday</p><p class="text-2xl font-bold text-slate-900">{{ number_format($totals['holiday']) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Attendance rate</p><p class="text-2xl font-bold text-slate-900">{{ number_format($totals['rate'], 1) }}%</p></div>
</div>
<div class="mt-5 overflow-x-auto">
    <table class="w-full text-left text-sm">
        <thead><tr class="border-b text-slate-500"><th class="py-3 pr-4">Staff / Employee</th><th class="pr-4">Attendance date</th><th class="pr-4">Status</th><th>Remarks</th></tr></thead>
        <tbody>
        @forelse($rows as $row)
            <tr class="border-b align-top">
                <td class="py-3 pr-4 font-medium">{{ $row->employee?->full_name ?? 'Unknown employee' }}<span class="block text-xs font-normal text-slate-500">{{ $row->employee?->employee_code }} · {{ $row->employee?->department?->name ?? 'No department' }}</span></td>
                <td class="pr-4 whitespace-nowrap">{{ $row->attendance_date?->format('d M Y') ?? '—' }}</td>
                <td class="pr-4">{{ ucfirst($row->status) }}</td>
                <td>{{ $row->remarks ?: '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="4" class="py-6 text-slate-500">No staff attendance records match these filters.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@include('hr_reports._pagination', ['subject' => 'attendance records'])
