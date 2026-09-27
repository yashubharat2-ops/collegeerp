<p class="panel-subtitle">Each class / section with its capacity and live counts: active student enrollments, students with active subject enrollments, subjects and faculty from active assignments, and active weekly timetable periods. A term filter narrows the subject, faculty and timetable columns.</p>
<div class="mt-5 grid gap-3 sm:grid-cols-3">
    <div class="rounded-xl bg-indigo-50 p-4"><p class="text-sm text-indigo-700">Sections</p><p class="text-2xl font-bold text-indigo-900">{{ number_format($sectionsCount) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Unique active students</p><p class="text-2xl font-bold text-slate-900">{{ number_format($studentsCount) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Total capacity</p><p class="text-2xl font-bold text-slate-900">{{ number_format($capacityTotal) }}</p></div>
</div>
<div class="mt-5 overflow-x-auto">
    <table class="w-full text-left text-sm">
        <thead><tr class="border-b text-slate-500"><th class="py-3 pr-4">Class / section</th><th class="pr-4">Year</th><th class="pr-4">Program · department</th><th class="pr-4 text-right">Capacity</th><th class="pr-4 text-right">Active students</th><th class="pr-4 text-right">Occupancy</th><th class="pr-4 text-right">Subject-enrolled</th><th class="pr-4 text-right">Subjects</th><th class="pr-4 text-right">Faculty</th><th class="text-right">Periods / week</th></tr></thead>
        <tbody>
        @forelse($rows as $section)
            @php $occupancy = $section->capacity ? round(((int) $section->active_students) * 100 / $section->capacity, 1) : null; @endphp
            <tr class="border-b align-top">
                <td class="py-3 pr-4 font-medium">{{ $section->name }}<span class="block text-xs font-normal text-slate-500">{{ $section->code }} · {{ ucfirst($section->status) }}@if($section->campus) · {{ $section->campus->name }}@endif</span></td>
                <td class="pr-4">{{ $section->academicYear?->name ?? '—' }}</td>
                <td class="pr-4">{{ $section->program?->name ?? 'No program' }} · {{ $section->program?->department?->name ?? 'No department' }}</td>
                <td class="pr-4 text-right">{{ $section->capacity ?? 'Not set' }}</td>
                <td class="pr-4 text-right font-semibold">{{ number_format((int) $section->active_students) }}</td>
                <td class="pr-4 text-right">{{ $occupancy === null ? '—' : $occupancy.'%' }}</td>
                <td class="pr-4 text-right">{{ number_format((int) $section->subject_students) }}</td>
                <td class="pr-4 text-right">{{ number_format((int) $section->subjects_assigned) }}</td>
                <td class="pr-4 text-right">{{ number_format((int) $section->faculty_assigned) }}</td>
                <td class="text-right">{{ number_format((int) $section->weekly_periods) }}</td>
            </tr>
        @empty
            <tr><td colspan="10" class="py-6 text-slate-500">No sections match these filters.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@include('academic_reports._pagination', ['subject' => 'sections'])
