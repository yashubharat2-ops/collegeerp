<p class="panel-subtitle">Promotion decisions and their source/target academic context. The academic filters refer to the target; the date range refers to the request creation date. Source enrollments are retained after approval.</p>
<div class="mt-5 overflow-x-auto">
    <table class="w-full text-left text-sm">
        <thead><tr class="border-b text-slate-500"><th class="py-3 pr-4">Student</th><th class="pr-4">From</th><th class="pr-4">To</th><th class="pr-4">Requested / effective</th><th>Status / new enrollment</th></tr></thead>
        <tbody>
        @forelse($rows as $promotion)
            <tr class="border-b align-top">
                <td class="py-3 pr-4"><a class="font-semibold text-indigo-700 hover:underline" href="{{ route('student-reports.profile', $promotion->student) }}">{{ $promotion->student->student_number }}</a><span class="block">{{ $promotion->student->fullName() }}</span></td>
                <td class="pr-4">{{ $promotion->sourceAcademicYear?->name ?? '—' }} · {{ $promotion->sourceProgram?->name ?? 'No program' }} · {{ $promotion->sourceSection?->name ?? 'No section' }}</td>
                <td class="pr-4">{{ $promotion->targetAcademicYear?->name ?? '—' }} · {{ $promotion->targetProgram?->name ?? 'No program' }} · {{ $promotion->targetSection?->name ?? 'No section' }}</td>
                <td class="pr-4">{{ $promotion->created_at?->format('d M Y') ?? '—' }}<span class="block text-xs text-slate-500">Effective: {{ $promotion->effective_date?->format('d M Y') ?? '—' }}</span></td>
                <td>{{ ucfirst($promotion->status) }}<span class="block text-xs text-slate-500">{{ $promotion->targetEnrollment?->enrollment_number ?? '—' }}</span></td>
            </tr>
        @empty
            <tr><td colspan="5" class="py-6 text-slate-500">No promotions match these filters.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@include('student_reports._pagination', ['subject' => 'promotions'])
