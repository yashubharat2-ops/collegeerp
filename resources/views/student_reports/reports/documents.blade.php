<p class="panel-subtitle">Document verification counts per student, including students with no documents. A date range limits counts to documents uploaded during that period; “No documents” means none in that period. No private files are exposed by this report.</p>
<div class="mt-5 overflow-x-auto">
    <table class="w-full text-left text-sm">
        <thead><tr class="border-b text-slate-500"><th class="py-3 pr-4">Student</th><th class="pr-4">Student status</th><th class="text-right">Documents</th><th class="text-right">Verified</th><th class="text-right">Pending</th><th class="text-right">Rejected</th></tr></thead>
        <tbody>
        @forelse($rows as $student)
            <tr class="border-b"><td class="py-3 pr-4"><a class="font-semibold text-indigo-700 hover:underline" href="{{ route('student-reports.profile', $student) }}">{{ $student->student_number }}</a><span class="block">{{ $student->fullName() }}</span></td><td class="pr-4">{{ ucfirst($student->status) }}</td><td class="text-right font-semibold">{{ $student->documents_total }}</td><td class="text-right">{{ $student->documents_verified }}</td><td class="text-right">{{ $student->documents_pending }}</td><td class="text-right">{{ $student->documents_rejected }}</td></tr>
        @empty
            <tr><td colspan="6" class="py-6 text-slate-500">No students or documents match these filters.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@include('student_reports._pagination', ['subject' => 'students'])
