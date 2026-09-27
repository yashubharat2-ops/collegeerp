<p class="panel-subtitle">New means the enrollment is in the student's first academic year (earliest non-deleted enrollment date, then ID). Old / continuing means a different academic year. The first year is determined before applying report filters. Counts are distinct students within each type.</p>
<div class="mt-5 grid gap-3 sm:grid-cols-2">
    <div class="rounded-xl bg-indigo-50 p-4"><p class="text-sm text-indigo-700">New students</p><p class="text-2xl font-bold text-indigo-900">{{ number_format($counts['new']) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Old / continuing students</p><p class="text-2xl font-bold text-slate-900">{{ number_format($counts['old']) }}</p></div>
</div>
<div class="mt-5 overflow-x-auto">
    <table class="w-full text-left text-sm">
        <thead><tr class="border-b text-slate-500"><th class="py-3 pr-4">Student</th><th class="pr-4">Enrollment</th><th class="pr-4">Academic year · program · section</th><th>Type</th></tr></thead>
        <tbody>
        @forelse($rows as $enrollment)
            <tr class="border-b align-top">
                <td class="py-3 pr-4"><a class="font-semibold text-indigo-700 hover:underline" href="{{ route('student-reports.profile', $enrollment->student) }}">{{ $enrollment->student->student_number }}</a><span class="block">{{ $enrollment->student->fullName() }}</span></td>
                <td class="pr-4">{{ $enrollment->enrollment_number }}<span class="block text-xs text-slate-500">{{ $enrollment->enrollment_date?->format('d M Y') ?? '—' }}</span></td>
                <td class="pr-4">{{ $enrollment->academicYear?->name ?? '—' }} · {{ $enrollment->program?->name ?? 'No program' }} · {{ $enrollment->section?->name ?? 'No section' }}</td>
                <td class="font-semibold">{{ (int) $enrollment->first_academic_year_id === (int) $enrollment->academic_year_id ? 'New' : 'Old / Continuing' }}</td>
            </tr>
        @empty
            <tr><td colspan="4" class="py-6 text-slate-500">No new / old student enrollments match these filters.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@include('student_reports._pagination', ['subject' => 'enrollments'])
