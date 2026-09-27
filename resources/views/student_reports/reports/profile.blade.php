<p class="panel-subtitle">Choose a student to open a read-only profile assembled from the existing admission, academic, document, promotion and transfer records.</p>
<div class="mt-5 overflow-x-auto">
    <table class="w-full text-left text-sm">
        <thead><tr class="border-b text-slate-500"><th class="py-3 pr-4">Student number</th><th class="pr-4">Name</th><th class="pr-4">Admission date</th><th>Status</th></tr></thead>
        <tbody>
        @forelse($rows as $student)
            <tr class="border-b"><td class="py-3 pr-4"><a class="font-semibold text-indigo-700 hover:underline" href="{{ route('student-reports.profile', $student) }}">{{ $student->student_number }}</a></td><td class="pr-4">{{ $student->fullName() }}</td><td class="pr-4">{{ $student->admission_date?->format('d M Y') ?? '—' }}</td><td>{{ ucfirst($student->status) }}</td></tr>
        @empty
            <tr><td colspan="4" class="py-6 text-slate-500">No students match these filters.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@include('student_reports._pagination', ['subject' => 'students'])
