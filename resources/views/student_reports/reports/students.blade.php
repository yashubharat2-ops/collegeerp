<p class="panel-subtitle">One row per student. Without enrollment filters, the current active enrollment is shown; with filters, the displayed enrollment matches the selected year, program and section.</p>
@php($matchingEnrollment = $filters['academic_year_id'] || $filters['program_id'] || $filters['department_id'] || $filters['section_id'] || ($filters['enrollment_status'] && $filters['enrollment_status'] !== 'all'))
<div class="mt-5 overflow-x-auto">
    <table class="w-full text-left text-sm">
        <thead><tr class="border-b text-slate-500"><th class="py-3 pr-4">Student</th><th class="pr-4">Admission date</th><th class="pr-4">Student status</th><th class="pr-4">{{ $matchingEnrollment ? 'Matching enrollment' : 'Current enrollment' }}</th></tr></thead>
        <tbody>
        @forelse($rows as $student)
            @php($enrollment = $matchingEnrollment ? $student->enrollments->first() : $student->currentEnrollment())
            <tr class="border-b align-top">
                <td class="py-3 pr-4">
                    <a class="font-semibold text-indigo-700 hover:underline" href="{{ route('student-reports.profile', $student) }}">{{ $student->student_number }}</a>
                    <span class="block">{{ $student->fullName() }}</span>
                </td>
                <td class="pr-4">{{ $student->admission_date?->format('d M Y') ?? '—' }}</td>
                <td class="pr-4">{{ ucfirst($student->status) }}</td>
                <td class="pr-4">
                    @if($enrollment)
                        <span class="font-medium">{{ $enrollment->enrollment_number }}</span>
                        <span class="block text-xs text-slate-600">{{ $enrollment->academicYear?->name ?? '—' }} · {{ $enrollment->program?->name ?? 'No program' }}@if($enrollment->program?->department) · {{ $enrollment->program->department->name }}@endif · {{ $enrollment->section?->name ?? 'No section' }}</span>
                        <span class="block text-xs text-slate-500">{{ ucfirst($enrollment->status) }}</span>
                    @else
                        —
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="4" class="py-6 text-slate-500">No students match these filters.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@include('student_reports._pagination', ['subject' => 'students'])
