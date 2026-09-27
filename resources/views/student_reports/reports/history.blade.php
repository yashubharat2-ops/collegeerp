<p class="panel-subtitle">Select a student to view their derived, chronological lifecycle timeline (admission, enrollments, academic records, promotions, transfers, documents and audit events). The student filters below only narrow this picker; event date/category filters are on the timeline page.</p>
<div class="mt-5 overflow-x-auto">
    <table class="w-full text-left text-sm">
        <thead><tr class="border-b text-slate-500"><th class="py-3 pr-4">Student</th><th class="pr-4">Admission date</th><th class="pr-4">Status</th><th>Timeline</th></tr></thead>
        <tbody>
        @forelse($rows as $student)
            <tr class="border-b"><td class="py-3 pr-4">{{ $student->student_number }} · {{ $student->fullName() }}</td><td class="pr-4">{{ $student->admission_date?->format('d M Y') ?? '—' }}</td><td class="pr-4">{{ ucfirst($student->status) }}</td><td><a class="font-semibold text-indigo-700 hover:underline" href="{{ route('student-reports.history', $student) }}">View history</a></td></tr>
        @empty
            <tr><td colspan="4" class="py-6 text-slate-500">No students match these filters.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@include('student_reports._pagination', ['subject' => 'students'])
