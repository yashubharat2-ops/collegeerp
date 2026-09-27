<p class="panel-subtitle">Official Admission records, including admissions not yet converted to a student. Student status filtering applies to converted records only.</p>
<div class="mt-5 overflow-x-auto">
    <table class="w-full text-left text-sm">
        <thead><tr class="border-b text-slate-500"><th class="py-3 pr-4">Admission / application</th><th class="pr-4">Applicant / student</th><th class="pr-4">Year · program · department</th><th class="pr-4">Admission date</th><th>Status</th></tr></thead>
        <tbody>
        @forelse($rows as $admission)
            <tr class="border-b align-top">
                <td class="py-3 pr-4 font-medium">{{ $admission->admission_number }}<span class="block text-xs font-normal text-slate-500">{{ $admission->application?->application_number ?? '—' }}</span></td>
                <td class="pr-4">
                    {{ trim(implode(' ', array_filter([$admission->applicant?->first_name, $admission->applicant?->middle_name, $admission->applicant?->last_name]))) ?: '—' }}
                    @if($admission->application?->student)
                        <a href="{{ route('student-reports.profile', $admission->application->student) }}" class="block text-xs font-semibold text-indigo-700 hover:underline">{{ $admission->application->student->student_number }}</a>
                    @else
                        <span class="block text-xs text-slate-500">Not converted</span>
                    @endif
                </td>
                <td class="pr-4">{{ $admission->academicYear?->name ?? '—' }} · {{ $admission->program?->name ?? 'No program' }} · {{ $admission->program?->department?->name ?? 'No department' }}</td>
                <td class="pr-4">{{ $admission->admission_date?->format('d M Y') ?? '—' }}</td>
                <td>{{ ucfirst($admission->status) }}</td>
            </tr>
        @empty
            <tr><td colspan="5" class="py-6 text-slate-500">No admissions match these filters.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@include('student_reports._pagination', ['subject' => 'admissions'])
