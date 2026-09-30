@php
    $attendanceCounts = $attendance['counts'];
    $attendanceTotal = array_sum($attendanceCounts);
@endphp

<p class="panel-subtitle">
    Section / class strength and attendance, from the existing Academic Reports derivations: live active-student,
    subject-enrollment, faculty-assignment and timetable counts per section, plus the recorded attendance register.
</p>

<div class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
    <div class="stat-card"><p class="stat-label">Sections / batches</p><p class="stat-value">{{ number_format($sections['sectionsCount']) }}</p></div>
    <div class="stat-card"><p class="stat-label">Active students in sections</p><p class="stat-value">{{ number_format($sections['studentsCount']) }}</p></div>
    <div class="stat-card"><p class="stat-label">Declared section capacity</p><p class="stat-value">{{ number_format($sections['capacityTotal']) }}</p></div>
    <div class="stat-card"><p class="stat-label">Attendance entries</p><p class="stat-value">{{ number_format($attendanceTotal) }}</p></div>
</div>

<h4 class="mt-8 text-sm font-semibold text-slate-800">Attendance register by status</h4>
<div class="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
    <div class="stat-card"><p class="stat-label">Present</p><p class="stat-value">{{ number_format($attendanceCounts['present'] ?? 0) }}</p></div>
    <div class="stat-card"><p class="stat-label">Absent</p><p class="stat-value">{{ number_format($attendanceCounts['absent'] ?? 0) }}</p></div>
    <div class="stat-card"><p class="stat-label">Late</p><p class="stat-value">{{ number_format($attendanceCounts['late'] ?? 0) }}</p></div>
    <div class="stat-card"><p class="stat-label">Leave</p><p class="stat-value">{{ number_format($attendanceCounts['leave'] ?? 0) }}</p></div>
</div>

<h4 class="mt-8 text-sm font-semibold text-slate-800">Section / class strength</h4>
<div class="mt-3 overflow-x-auto">
    <table class="w-full min-w-[960px] text-left text-sm">
        <thead class="border-b bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
            <tr>
                <th class="px-3 py-3">Academic year</th>
                <th class="px-3 py-3">Program</th>
                <th class="px-3 py-3">Section</th>
                <th class="px-3 py-3 text-right">Active students</th>
                <th class="px-3 py-3 text-right">Subject students</th>
                <th class="px-3 py-3 text-right">Subjects assigned</th>
                <th class="px-3 py-3 text-right">Faculty assigned</th>
                <th class="px-3 py-3 text-right">Weekly periods</th>
                <th class="px-3 py-3 text-right">Capacity</th>
            </tr>
        </thead>
        <tbody class="divide-y">
            @forelse($sections['rows'] as $section)
                <tr>
                    <td class="px-3 py-3">{{ $section->academicYear?->name ?? '—' }}</td>
                    <td class="px-3 py-3">{{ $section->program?->name ?? '—' }}</td>
                    <td class="px-3 py-3 font-medium">{{ $section->name }}<span class="block font-mono text-xs text-slate-500">{{ $section->code }}</span></td>
                    <td class="px-3 py-3 text-right font-mono">{{ number_format((int) $section->active_students) }}</td>
                    <td class="px-3 py-3 text-right font-mono">{{ number_format((int) $section->subject_students) }}</td>
                    <td class="px-3 py-3 text-right font-mono">{{ number_format((int) $section->subjects_assigned) }}</td>
                    <td class="px-3 py-3 text-right font-mono">{{ number_format((int) $section->faculty_assigned) }}</td>
                    <td class="px-3 py-3 text-right font-mono">{{ number_format((int) $section->weekly_periods) }}</td>
                    <td class="px-3 py-3 text-right font-mono">{{ $section->capacity === null ? '—' : number_format((int) $section->capacity) }}</td>
                </tr>
            @empty
                <tr><td colspan="9" class="px-3 py-8 text-center text-slate-500">No sections match these filters.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

@include('consolidated_reports._pagination', ['rows' => $sections['rows'], 'subject' => 'sections'])
