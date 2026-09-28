<p class="panel-subtitle">Every live student-subject enrollment row, including dropped and completed enrollments, with the student enrollment it belongs to.</p>
<div class="mt-5 grid gap-3 sm:grid-cols-3">
    <div class="rounded-xl bg-indigo-50 p-4"><p class="text-sm text-indigo-700">Subject enrollments</p><p class="text-2xl font-bold text-indigo-900">{{ number_format($rows->total()) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Unique students</p><p class="text-2xl font-bold text-slate-900">{{ number_format($studentsCount) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Subjects</p><p class="text-2xl font-bold text-slate-900">{{ number_format($subjectsCount) }}</p></div>
</div>
<div class="mt-5 overflow-x-auto">
    <table class="w-full text-left text-sm">
        <thead><tr class="border-b text-slate-500"><th class="py-3 pr-4">Student</th><th class="pr-4">Enrollment</th><th class="pr-4">Subject</th><th class="pr-4">Year · term</th><th class="pr-4">Program · department</th><th class="pr-4">Class / section</th><th class="pr-4">Enrolled on</th><th>Status</th></tr></thead>
        <tbody>
        @forelse($rows as $row)
            <tr class="border-b align-top">
                <td class="py-3 pr-4 font-medium">{{ $row->student?->student_number ?? '—' }}<span class="block font-normal">{{ $row->student?->fullName() ?? 'Unknown student' }}</span></td>
                <td class="pr-4">{{ $row->studentEnrollment?->enrollment_number ?? '—' }}</td>
                <td class="pr-4">{{ $row->subject?->name ?? '—' }}<span class="block text-xs text-slate-500">{{ $row->subject?->code }}</span></td>
                <td class="pr-4">{{ $row->academicYear?->name ?? '—' }} · {{ $row->academicTerm?->name ?? '—' }}</td>
                <td class="pr-4">{{ $row->program?->name ?? 'No program' }} · {{ $row->program?->department?->name ?? 'No department' }}</td>
                <td class="pr-4">{{ $row->section?->name ?? '—' }}</td>
                <td class="pr-4">{{ $row->enrollment_date?->format('d M Y') ?? '—' }}</td>
                <td>{{ ucfirst($row->status) }}</td>
            </tr>
        @empty
            <tr><td colspan="8" class="py-6 text-slate-500">No subject enrollments match these filters.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@include('academic_reports._pagination', ['subject' => 'subject enrollments'])
