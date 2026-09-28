<p class="panel-subtitle">Recorded staff attendance for the active college, one row per employee and status. The tiles count the whole filtered set; the attendance rate treats recorded holidays as non-working days.</p>
<div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
    <div class="rounded-xl bg-indigo-50 p-4"><p class="text-sm text-indigo-700">Recorded days</p><p class="text-2xl font-bold text-indigo-900">{{ number_format($totals['days']) }}</p></div>
    <div class="rounded-xl bg-emerald-50 p-4"><p class="text-sm text-emerald-700">Employees covered</p><p class="text-2xl font-bold text-emerald-900">{{ number_format($totals['employees']) }}</p></div>
    <div class="rounded-xl bg-emerald-50 p-4"><p class="text-sm text-emerald-700">Present</p><p class="text-2xl font-bold text-emerald-900">{{ number_format($totals['present']) }}</p></div>
    <div class="rounded-xl bg-amber-50 p-4"><p class="text-sm text-amber-700">Late</p><p class="text-2xl font-bold text-amber-900">{{ number_format($totals['late']) }}</p></div>
    <div class="rounded-xl bg-rose-50 p-4"><p class="text-sm text-rose-700">Absent</p><p class="text-2xl font-bold text-rose-900">{{ number_format($totals['absent']) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">On leave</p><p class="text-2xl font-bold text-slate-900">{{ number_format($totals['leave']) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Holidays</p><p class="text-2xl font-bold text-slate-900">{{ number_format($totals['holiday']) }}</p></div>
    <div class="rounded-xl bg-indigo-50 p-4"><p class="text-sm text-indigo-700">Attendance rate</p><p class="text-2xl font-bold text-indigo-900">{{ $totals['attendance_rate'] === null ? '—' : $totals['attendance_rate'].'%' }}</p></div>
</div>
<div class="mt-5 overflow-x-auto">
    <table class="w-full text-left text-sm">
        <thead><tr class="border-b text-slate-500"><th class="py-3 pr-4">Employee</th><th class="pr-4">Department</th><th class="pr-4">Status</th><th class="pr-4 text-right">Days</th><th>Last recorded</th></tr></thead>
        <tbody>
        @forelse($rows as $row)
            <tr class="border-b align-top">
                <td class="py-3 pr-4 font-medium">{{ $row->employee?->employee_code ?? '—' }}<span class="block font-normal">{{ $row->employee?->full_name ?? 'Unknown employee' }}</span></td>
                <td class="pr-4">{{ $row->employee?->department?->name ?? '—' }}</td>
                <td class="pr-4">{{ ucfirst($row->status) }}</td>
                <td class="pr-4 text-right font-medium">{{ number_format((int) $row->total) }}</td>
                <td>{{ $row->last_date ? \Illuminate\Support\Carbon::parse($row->last_date)->format('d M Y') : '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="5" class="py-6 text-slate-500">No attendance records match these filters.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@include('hr_reports._pagination', ['subject' => 'attendance rows'])
