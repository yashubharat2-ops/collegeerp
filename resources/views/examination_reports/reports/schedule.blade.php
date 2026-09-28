<p class="panel-subtitle">Scheduled papers with subject, class / section and faculty. Ordered by exam date, then start time.</p>
<div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
    <div class="rounded-xl bg-indigo-50 p-4"><p class="text-sm text-indigo-700">Schedule entries</p><p class="text-2xl font-bold text-indigo-900">{{ number_format($rows->total()) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Distinct subjects</p><p class="text-2xl font-bold text-slate-900">{{ number_format($subjectsCount) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Invigilating faculty</p><p class="text-2xl font-bold text-slate-900">{{ number_format($facultyCount) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">On this page</p><p class="text-2xl font-bold text-slate-900">{{ $rows->count() }}</p></div>
</div>
<div class="mt-5 overflow-x-auto">
    <table class="w-full text-left text-sm">
        <thead><tr class="border-b text-slate-500"><th class="py-3 pr-4">Date</th><th class="pr-4">Time</th><th class="pr-4">Examination</th><th class="pr-4">Subject</th><th class="pr-4">Class / section</th><th class="pr-4">Faculty</th><th class="pr-4">Room</th><th class="pr-4">Marks</th><th class="text-right">Status</th></tr></thead>
        <tbody>
        @forelse($rows as $row)
            <tr class="border-b align-top">
                <td class="py-3 pr-4 font-medium whitespace-nowrap">{{ $row->exam_date?->format('d M Y') ?? '—' }}</td>
                <td class="pr-4 whitespace-nowrap">{{ $row->start_time ?? '—' }} – {{ $row->end_time ?? '—' }}</td>
                <td class="pr-4">{{ $row->examination?->name ?? '—' }}</td>
                <td class="pr-4">{{ $row->subject?->name ?? '—' }}<span class="block text-xs text-slate-500">{{ $row->subject?->code }}</span></td>
                <td class="pr-4">{{ $row->program?->name ?? '—' }} · {{ $row->section?->name ?? '—' }}</td>
                <td class="pr-4">{{ $row->faculty?->full_name ?? '—' }}</td>
                <td class="pr-4">{{ $row->room ?: '—' }}</td>
                <td class="pr-4">{{ rtrim(rtrim(number_format((float) $row->max_marks, 2), '0'), '.') }} / {{ rtrim(rtrim(number_format((float) $row->passing_marks, 2), '0'), '.') }}</td>
                <td class="text-right">{{ ucfirst($row->status) }}</td>
            </tr>
        @empty
            <tr><td colspan="9" class="py-6 text-slate-500">No schedule entries match these filters.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@include('examination_reports._pagination', ['subject' => 'schedule entries'])
