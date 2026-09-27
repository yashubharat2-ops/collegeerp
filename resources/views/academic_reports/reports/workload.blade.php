<p class="panel-subtitle">Weekly teaching load per faculty, academic year and term from <strong>active</strong> timetable entries, with the number of active subject assignments for comparison (an assignment without a term counts towards every term of its year).</p>
<div class="mt-5 grid gap-3 sm:grid-cols-3">
    <div class="rounded-xl bg-indigo-50 p-4"><p class="text-sm text-indigo-700">Faculty with load</p><p class="text-2xl font-bold text-indigo-900">{{ number_format($facultyCount) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Periods / week</p><p class="text-2xl font-bold text-slate-900">{{ number_format($periodsCount) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Hours / week</p><p class="text-2xl font-bold text-slate-900">{{ number_format($hoursTotal, 2) }}</p></div>
</div>
<div class="mt-5 overflow-x-auto">
    <table class="w-full text-left text-sm">
        <thead><tr class="border-b text-slate-500"><th class="py-3 pr-4">Faculty</th><th class="pr-4">Year · term</th><th class="pr-4 text-right">Periods / week</th><th class="pr-4 text-right">Hours / week</th><th class="pr-4 text-right">Subjects</th><th class="pr-4 text-right">Sections</th><th class="text-right">Active assignments</th></tr></thead>
        <tbody>
        @forelse($rows as $row)
            <tr class="border-b align-top">
                <td class="py-3 pr-4 font-medium">{{ $row->faculty?->full_name ?? 'Unknown faculty' }}<span class="block text-xs font-normal text-slate-500">{{ $row->faculty?->employee_code }} · {{ $row->faculty?->department?->name ?? 'No department' }}</span></td>
                <td class="pr-4">{{ $row->academicYear?->name ?? '—' }} · {{ $row->academicTerm?->name ?? '—' }}</td>
                <td class="pr-4 text-right font-semibold">{{ number_format((int) $row->weekly_periods) }}</td>
                <td class="pr-4 text-right">{{ number_format($row->weekly_hours, 2) }}</td>
                <td class="pr-4 text-right">{{ number_format((int) $row->subjects_count) }}</td>
                <td class="pr-4 text-right">{{ number_format((int) $row->sections_count) }}</td>
                <td class="text-right">{{ number_format($row->assignments_count) }}</td>
            </tr>
        @empty
            <tr><td colspan="7" class="py-6 text-slate-500">No active timetable load matches these filters.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@include('academic_reports._pagination', ['subject' => 'workload rows'])
