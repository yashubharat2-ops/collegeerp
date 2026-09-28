<p class="panel-subtitle">Published results ranked across the WHOLE filtered set by percentage, then total marks, then result id — so ranks are stable and never renumber between pages. Ranking is computed in memory from stored values; nothing is written back.</p>
<div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
    <div class="rounded-xl bg-indigo-50 p-4"><p class="text-sm text-indigo-700">Candidates</p><p class="text-2xl font-bold text-indigo-900">{{ number_format($candidatesCount) }}</p></div>
    <div class="rounded-xl bg-emerald-50 p-4"><p class="text-sm text-emerald-700">Passed</p><p class="text-2xl font-bold text-emerald-900">{{ number_format($passCount) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Top percentage</p><p class="text-2xl font-bold text-slate-900">{{ $topPercentage === null ? '—' : $topPercentage.'%' }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">On this page</p><p class="text-2xl font-bold text-slate-900">{{ $rows->count() }}</p></div>
</div>
<div class="mt-5 overflow-x-auto">
    <table class="w-full text-left text-sm">
        <thead><tr class="border-b text-slate-500"><th class="py-3 pr-4 text-right">Rank</th><th class="pr-4">Student</th><th class="pr-4">Program · section</th><th class="pr-4">Examination</th><th class="pr-4 text-right">Marks</th><th class="pr-4 text-right">%</th><th class="pr-4">Grade</th><th class="text-right">Status</th></tr></thead>
        <tbody>
        @forelse($rows as $row)
            <tr class="border-b align-top">
                <td class="py-3 pr-4 text-right font-bold text-indigo-700">{{ $row->rank }}</td>
                <td class="pr-4 font-medium">{{ $row->studentEnrollment?->student?->student_number ?? '—' }}<span class="block">{{ $row->studentEnrollment?->student?->fullName() ?? 'Unknown student' }}</span></td>
                <td class="pr-4">{{ $row->studentEnrollment?->program?->name ?? '—' }} · {{ $row->studentEnrollment?->section?->name ?? '—' }}</td>
                <td class="pr-4">{{ $row->examination?->name ?? '—' }}</td>
                <td class="pr-4 text-right">{{ $row->total_obtained_marks }} / {{ $row->total_max_marks }}</td>
                <td class="pr-4 text-right font-semibold">{{ $row->percentage }}%</td>
                <td class="pr-4">{{ $row->overall_grade ?? '—' }}</td>
                <td class="text-right">{{ ucfirst($row->result_status) }}</td>
            </tr>
        @empty
            <tr><td colspan="8" class="py-6 text-slate-500">No published results match these filters.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@include('examination_reports._pagination', ['subject' => 'results'])
