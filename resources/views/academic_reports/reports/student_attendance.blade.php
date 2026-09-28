<p class="panel-subtitle">Attendance per student{{ $perSubject ? ', subject and term' : ' across all matching subjects' }}. Attendance % = (present + late) ÷ recorded sessions; leave is shown separately and not counted as attended. Use “Attendance below (%)” to list shortages.</p>
<div class="mt-5 grid gap-3 sm:grid-cols-3">
    <div class="rounded-xl bg-indigo-50 p-4"><p class="text-sm text-indigo-700">Unique students</p><p class="text-2xl font-bold text-indigo-900">{{ number_format($studentsCount) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Recorded sessions</p><p class="text-2xl font-bold text-slate-900">{{ number_format($sessionsCount) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Overall attendance</p><p class="text-2xl font-bold text-slate-900">{{ $overallPercentage === null ? '—' : number_format($overallPercentage, 2).'%' }}</p></div>
</div>
<div class="mt-5 overflow-x-auto">
    <table class="w-full text-left text-sm">
        <thead><tr class="border-b text-slate-500"><th class="py-3 pr-4">Student</th>@if($perSubject)<th class="pr-4">Subject</th><th class="pr-4">Term</th>@endif<th class="pr-4 text-right">Sessions</th><th class="pr-4 text-right">Present</th><th class="pr-4 text-right">Late</th><th class="pr-4 text-right">Absent</th><th class="pr-4 text-right">Leave</th><th class="text-right">Attendance %</th></tr></thead>
        <tbody>
        @forelse($rows as $row)
            @php $percentage = (int) $row->sessions > 0 ? round(((int) $row->attended_count) * 100 / (int) $row->sessions, 2) : 0; @endphp
            <tr class="border-b align-top">
                <td class="py-3 pr-4 font-medium">{{ $row->student?->student_number ?? '—' }}<span class="block font-normal">{{ $row->student?->fullName() ?? 'Unknown student' }}</span></td>
                @if($perSubject)
                    <td class="pr-4">{{ $row->subject?->name ?? '—' }}<span class="block text-xs text-slate-500">{{ $row->subject?->code }}</span></td>
                    <td class="pr-4">{{ $row->academicTerm?->name ?? '—' }}</td>
                @endif
                <td class="pr-4 text-right">{{ number_format((int) $row->sessions) }}</td>
                <td class="pr-4 text-right">{{ number_format((int) $row->present_count) }}</td>
                <td class="pr-4 text-right">{{ number_format((int) $row->late_count) }}</td>
                <td class="pr-4 text-right">{{ number_format((int) $row->absent_count) }}</td>
                <td class="pr-4 text-right">{{ number_format((int) $row->leave_count) }}</td>
                <td class="text-right font-semibold">{{ number_format($percentage, 2) }}%</td>
            </tr>
        @empty
            <tr><td colspan="{{ $perSubject ? 9 : 7 }}" class="py-6 text-slate-500">No attendance records match these filters.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@include('academic_reports._pagination', ['subject' => 'summary rows'])
