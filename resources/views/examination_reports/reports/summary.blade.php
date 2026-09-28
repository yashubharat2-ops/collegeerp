<p class="panel-subtitle">One row per examination with live counts of schedules, marks, attendance and (published) results. Examinations are ordered by start date, newest first.</p>
<div class="mt-5 overflow-x-auto">
    <table class="w-full text-left text-sm">
        <thead><tr class="border-b text-slate-500"><th class="py-3 pr-4">Examination</th><th class="pr-4">Type</th><th class="pr-4">Term</th><th class="pr-4">Dates</th><th class="pr-4">Status</th><th class="pr-4 text-right">Schedules</th><th class="pr-4 text-right">Subjects</th><th class="pr-4 text-right">Attendance</th><th class="pr-4 text-right">Marks</th><th class="pr-4 text-right">Results</th><th class="pr-4 text-right">Published</th><th class="text-right">Pass (published)</th></tr></thead>
        <tbody>
        @forelse($rows as $row)
            <tr class="border-b align-top">
                <td class="py-3 pr-4 font-medium">{{ $row->name }}<span class="block text-xs text-slate-500">{{ $row->code }}</span></td>
                <td class="pr-4">{{ ucfirst($row->exam_type) }}</td>
                <td class="pr-4">{{ $row->academicTerm?->name ?? '—' }}</td>
                <td class="pr-4 whitespace-nowrap">{{ $row->start_date?->format('d M Y') ?? '—' }} – {{ $row->end_date?->format('d M Y') ?? '—' }}</td>
                <td class="pr-4">{{ ucfirst($row->status) }}</td>
                <td class="pr-4 text-right">{{ number_format($row->schedules_count) }}</td>
                <td class="pr-4 text-right">{{ number_format($row->subjects_count) }}</td>
                <td class="pr-4 text-right">{{ number_format($row->attendance_count) }}</td>
                <td class="pr-4 text-right">{{ number_format($row->marks_count) }}</td>
                <td class="pr-4 text-right">{{ number_format($row->results_count) }}</td>
                <td class="pr-4 text-right">{{ number_format($row->published_count) }}</td>
                <td class="text-right">{{ number_format($row->pass_count) }}</td>
            </tr>
        @empty
            <tr><td colspan="12" class="py-6 text-slate-500">No examinations match these filters.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@include('examination_reports._pagination', ['subject' => 'examinations'])
