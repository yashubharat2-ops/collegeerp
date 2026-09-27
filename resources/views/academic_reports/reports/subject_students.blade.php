<p class="panel-subtitle">Distinct students per subject, term and section from existing subject enrollments, split by enrollment status. Totals count unique students across all matching groups, so they need not equal the sum of rows.</p>
<div class="mt-5 grid gap-3 sm:grid-cols-3">
    <div class="rounded-xl bg-indigo-50 p-4"><p class="text-sm text-indigo-700">Subjects</p><p class="text-2xl font-bold text-indigo-900">{{ number_format($subjectsCount) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Unique students</p><p class="text-2xl font-bold text-slate-900">{{ number_format($studentsCount) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Subject enrollment records</p><p class="text-2xl font-bold text-slate-900">{{ number_format($enrollmentsCount) }}</p></div>
</div>
<div class="mt-5 overflow-x-auto">
    <table class="w-full text-left text-sm">
        <thead><tr class="border-b text-slate-500"><th class="py-3 pr-4">Subject</th><th class="pr-4">Term · year</th><th class="pr-4">Class / section · program</th><th class="pr-4 text-right">Students</th><th class="pr-4 text-right">Active</th><th class="pr-4 text-right">Completed</th><th class="pr-4 text-right">Dropped</th><th class="no-print"></th></tr></thead>
        <tbody>
        @forelse($rows as $group)
            <tr class="border-b align-top">
                <td class="py-3 pr-4 font-medium">{{ $group->subject?->name ?? '—' }}<span class="block text-xs font-normal text-slate-500">{{ $group->subject?->code }}@if($group->subject?->department) · {{ $group->subject->department->name }}@endif</span></td>
                <td class="pr-4">{{ $group->academicTerm?->name ?? '—' }} · {{ $group->academicTerm?->academicYear?->name ?? '—' }}</td>
                <td class="pr-4">{{ $group->section?->name ?? '—' }} · {{ $group->section?->program?->name ?? 'No program' }}</td>
                <td class="pr-4 text-right font-semibold">{{ number_format((int) $group->students_count) }}</td>
                <td class="pr-4 text-right">{{ number_format((int) $group->active_count) }}</td>
                <td class="pr-4 text-right">{{ number_format((int) $group->completed_count) }}</td>
                <td class="pr-4 text-right">{{ number_format((int) $group->dropped_count) }}</td>
                <td class="no-print text-right"><a class="text-xs font-semibold text-indigo-700 hover:underline" href="{{ route('academic-reports.index', ['report' => 'subject_enrollments', 'subject_id' => $group->subject_id, 'academic_term_id' => $group->academic_term_id, 'section_id' => $group->section_id]) }}">View students</a></td>
            </tr>
        @empty
            <tr><td colspan="8" class="py-6 text-slate-500">No subject enrollments match these filters.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@include('academic_reports._pagination', ['subject' => 'subject groups'])
