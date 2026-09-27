<p class="panel-subtitle">Every live enrollment row is shown separately, including completed and withdrawn enrollments. A student's later enrollment never replaces an earlier one.</p>
<div class="mt-5 overflow-x-auto">
    <table class="w-full text-left text-sm">
        <thead><tr class="border-b text-slate-500"><th class="py-3 pr-4">Enrollment / date</th><th class="pr-4">Student</th><th class="pr-4">Year · program · department</th><th class="pr-4">Class / section</th><th>Status</th></tr></thead>
        <tbody>
        @forelse($rows as $enrollment)
            <tr class="border-b align-top">
                <td class="py-3 pr-4 font-medium">{{ $enrollment->enrollment_number }}<span class="block text-xs font-normal text-slate-500">{{ $enrollment->enrollment_date?->format('d M Y') ?? '—' }}</span></td>
                <td class="pr-4"><a class="font-semibold text-indigo-700 hover:underline" href="{{ route('student-reports.profile', $enrollment->student) }}">{{ $enrollment->student->student_number }}</a><span class="block">{{ $enrollment->student->fullName() }}</span><span class="block text-xs text-slate-500">{{ ucfirst($enrollment->student->status) }}</span></td>
                <td class="pr-4">{{ $enrollment->academicYear?->name ?? '—' }} · {{ $enrollment->program?->name ?? 'No program' }} · {{ $enrollment->program?->department?->name ?? 'No department' }}</td>
                <td class="pr-4">{{ $enrollment->section?->name ?? '—' }}</td>
                <td>{{ ucfirst($enrollment->status) }}</td>
            </tr>
        @empty
            <tr><td colspan="5" class="py-6 text-slate-500">No enrollments match these filters.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@include('student_reports._pagination', ['subject' => 'enrollments'])
