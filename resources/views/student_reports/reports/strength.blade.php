<p class="panel-subtitle">Distinct students by academic year, program and section. Defaults to active students with active enrollments; totals count unique students across all matching groups, so they need not equal the sum of group rows.</p>
<div class="mt-5 grid gap-3 sm:grid-cols-2">
    <div class="rounded-xl bg-indigo-50 p-4"><p class="text-sm text-indigo-700">Unique students</p><p class="text-2xl font-bold text-indigo-900">{{ number_format($studentsCount) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Enrollment records</p><p class="text-2xl font-bold text-slate-900">{{ number_format($enrollmentsCount) }}</p></div>
</div>
<div class="mt-5 overflow-x-auto">
    <table class="w-full text-left text-sm">
        <thead><tr class="border-b text-slate-500"><th class="py-3 pr-4">Academic year</th><th class="pr-4">Program / course</th><th class="pr-4">Department</th><th class="pr-4">Class / section</th><th class="text-right">Students</th></tr></thead>
        <tbody>
        @forelse($rows as $group)
            <tr class="border-b"><td class="py-3 pr-4">{{ $group->academicYear?->name ?? '—' }}</td><td class="pr-4">{{ $group->program?->name ?? 'No program' }}</td><td class="pr-4">{{ $group->program?->department?->name ?? 'No department' }}</td><td class="pr-4">{{ $group->section?->name ?? 'No section' }}</td><td class="text-right font-semibold">{{ number_format($group->students_count) }}</td></tr>
        @empty
            <tr><td colspan="5" class="py-6 text-slate-500">No enrollment groups match these filters.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@include('student_reports._pagination', ['subject' => 'groups'])
