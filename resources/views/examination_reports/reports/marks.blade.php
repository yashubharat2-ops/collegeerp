<p class="panel-subtitle">Marks entered against exam sessions, newest exam date first. Averages are simple reads over the stored entries — nothing is recalculated.</p>
<div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
    <div class="rounded-xl bg-indigo-50 p-4"><p class="text-sm text-indigo-700">Mark entries</p><p class="text-2xl font-bold text-indigo-900">{{ number_format($rows->total()) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Scored entries</p><p class="text-2xl font-bold text-slate-900">{{ number_format($scoredCount) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Average obtained</p><p class="text-2xl font-bold text-slate-900">{{ $averageMarks === null ? '—' : $averageMarks }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">On this page</p><p class="text-2xl font-bold text-slate-900">{{ $rows->count() }}</p></div>
</div>
<div class="mt-5 overflow-x-auto">
    <table class="w-full text-left text-sm">
        <thead><tr class="border-b text-slate-500"><th class="py-3 pr-4">Exam date</th><th class="pr-4">Student</th><th class="pr-4">Examination · subject</th><th class="pr-4">Class / section</th><th class="pr-4 text-right">Marks</th><th class="pr-4">Status</th><th class="text-right">Entered</th></tr></thead>
        <tbody>
        @forelse($rows as $row)
            <tr class="border-b align-top">
                <td class="py-3 pr-4 font-medium whitespace-nowrap">{{ $row->examSchedule?->exam_date?->format('d M Y') ?? '—' }}</td>
                <td class="pr-4">{{ $row->studentEnrollment?->student?->student_number ?? '—' }}<span class="block">{{ $row->studentEnrollment?->student?->fullName() ?? 'Unknown student' }}</span></td>
                <td class="pr-4">{{ $row->examSchedule?->examination?->name ?? '—' }}<span class="block text-xs text-slate-500">{{ $row->examSchedule?->subject?->name ?? '—' }}</span></td>
                <td class="pr-4">{{ $row->studentEnrollment?->program?->name ?? '—' }} · {{ $row->studentEnrollment?->section?->name ?? '—' }}</td>
                <td class="pr-4 text-right">{{ $row->obtained_marks ?? '—' }} / {{ $row->max_marks }}</td>
                <td class="pr-4">{{ ucfirst($row->status) }}</td>
                <td class="text-right">{{ $row->entered_at?->format('d M Y') ?? '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="7" class="py-6 text-slate-500">No mark entries match these filters.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@include('examination_reports._pagination', ['subject' => 'mark entries'])
