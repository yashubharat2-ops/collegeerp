<p class="panel-subtitle">Timetable entries in weekly order (day, start time, section). Defaults to active entries; choose “All statuses” to include inactive ones.</p>
<div class="mt-5 grid gap-3 sm:grid-cols-2">
    <div class="rounded-xl bg-indigo-50 p-4"><p class="text-sm text-indigo-700">Timetable entries</p><p class="text-2xl font-bold text-indigo-900">{{ number_format($entriesCount) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Scheduled hours / week</p><p class="text-2xl font-bold text-slate-900">{{ number_format($weeklyHours, 2) }}</p></div>
</div>
<div class="mt-5 overflow-x-auto">
    <table class="w-full text-left text-sm">
        <thead><tr class="border-b text-slate-500"><th class="py-3 pr-4">Day</th><th class="pr-4">Time · period</th><th class="pr-4">Class / section</th><th class="pr-4">Subject</th><th class="pr-4">Faculty</th><th class="pr-4">Year · term · program</th><th class="pr-4">Room</th><th>Status</th></tr></thead>
        <tbody>
        @forelse($rows as $entry)
            <tr class="border-b align-top">
                <td class="py-3 pr-4 font-medium">{{ $days[$entry->day_of_week] ?? '—' }}</td>
                <td class="pr-4">{{ substr((string) $entry->start_time, 0, 5) }}–{{ substr((string) $entry->end_time, 0, 5) }}<span class="block text-xs text-slate-500">{{ $entry->period ? 'Period '.$entry->period.' · ' : '' }}{{ \App\Domain\Academic\Services\AcademicReportService::minutes($entry->start_time, $entry->end_time) }} min</span></td>
                <td class="pr-4">{{ $entry->section?->name ?? '—' }}</td>
                <td class="pr-4">{{ $entry->subject?->name ?? '—' }}<span class="block text-xs text-slate-500">{{ $entry->subject?->code }}</span></td>
                <td class="pr-4">{{ $entry->faculty?->full_name ?? '—' }}<span class="block text-xs text-slate-500">{{ $entry->faculty?->employee_code }}</span></td>
                <td class="pr-4">{{ $entry->academicYear?->name ?? '—' }} · {{ $entry->academicTerm?->name ?? '—' }} · {{ $entry->program?->name ?? 'No program' }}</td>
                <td class="pr-4">{{ $entry->room ?: '—' }}@if($entry->campus)<span class="block text-xs text-slate-500">{{ $entry->campus->name }}</span>@endif</td>
                <td>{{ ucfirst($entry->status) }}</td>
            </tr>
        @empty
            <tr><td colspan="8" class="py-6 text-slate-500">No timetable entries match these filters.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@include('academic_reports._pagination', ['subject' => 'timetable entries'])
