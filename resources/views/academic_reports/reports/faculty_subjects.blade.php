<p class="panel-subtitle">Faculty subject assignments ordered by faculty. Scheduled periods count active timetable entries and enrolled students count active subject enrollments in the assignment's context; an assignment without a term or section covers every term or section of its year.</p>
<div class="mt-5 grid gap-3 sm:grid-cols-3">
    <div class="rounded-xl bg-indigo-50 p-4"><p class="text-sm text-indigo-700">Assignments</p><p class="text-2xl font-bold text-indigo-900">{{ number_format($rows->total()) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Faculty</p><p class="text-2xl font-bold text-slate-900">{{ number_format($facultyCount) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Subjects</p><p class="text-2xl font-bold text-slate-900">{{ number_format($subjectsCount) }}</p></div>
</div>
<div class="mt-5 overflow-x-auto">
    <table class="w-full text-left text-sm">
        <thead><tr class="border-b text-slate-500"><th class="py-3 pr-4">Faculty</th><th class="pr-4">Subject</th><th class="pr-4">Year · term</th><th class="pr-4">Program · section</th><th class="pr-4 text-right">Periods / week</th><th class="pr-4 text-right">Enrolled students</th><th>Status</th></tr></thead>
        <tbody>
        @forelse($rows as $row)
            <tr class="border-b align-top">
                <td class="py-3 pr-4 font-medium">{{ $row->faculty?->full_name ?? 'Unknown faculty' }}<span class="block text-xs font-normal text-slate-500">{{ $row->faculty?->employee_code }} · {{ $row->faculty?->department?->name ?? 'No department' }}</span></td>
                <td class="pr-4">{{ $row->subject?->name ?? '—' }}<span class="block text-xs text-slate-500">{{ $row->subject?->code }}</span></td>
                <td class="pr-4">{{ $row->academicYear?->name ?? '—' }} · {{ $row->academicTerm?->name ?? 'All terms' }}</td>
                <td class="pr-4">{{ $row->program?->name ?? 'All programs' }} · {{ $row->section?->name ?? 'All sections' }}</td>
                <td class="pr-4 text-right">{{ number_format((int) $row->weekly_periods) }}</td>
                <td class="pr-4 text-right">{{ number_format((int) $row->enrolled_students) }}</td>
                <td>{{ ucfirst($row->status) }}</td>
            </tr>
        @empty
            <tr><td colspan="7" class="py-6 text-slate-500">No faculty subject assignments match these filters.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@include('academic_reports._pagination', ['subject' => 'assignments'])
