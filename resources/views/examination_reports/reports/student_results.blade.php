<p class="panel-subtitle">Published student results — newest publication first. Unpublished results are never shown here (they belong to the Results module).</p>
@php
    $statusColors = [
        'pass' => 'bg-emerald-50 text-emerald-700',
        'fail' => 'bg-rose-50 text-rose-700',
        'absent' => 'bg-amber-50 text-amber-700',
        'withheld' => 'bg-orange-50 text-orange-700',
        'incomplete' => 'bg-slate-100 text-slate-600',
    ];
@endphp
<div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
    <div class="rounded-xl bg-indigo-50 p-4"><p class="text-sm text-indigo-700">Results</p><p class="text-2xl font-bold text-indigo-900">{{ number_format($rows->total()) }}</p></div>
    @foreach($counts as $status => $total)
        <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">{{ ucfirst($status) }}</p><p class="text-2xl font-bold text-slate-900">{{ number_format($total) }}</p></div>
    @endforeach
    <div class="rounded-xl bg-emerald-50 p-4"><p class="text-sm text-emerald-700">Pass rate</p><p class="text-2xl font-bold text-emerald-900">{{ $passRate === null ? '—' : $passRate.'%' }}</p></div>
</div>
<div class="mt-5 overflow-x-auto">
    <table class="w-full text-left text-sm">
        <thead><tr class="border-b text-slate-500"><th class="py-3 pr-4">Student</th><th class="pr-4">Program · section</th><th class="pr-4">Examination</th><th class="pr-4">Term</th><th class="pr-4 text-right">Marks</th><th class="pr-4 text-right">%</th><th class="pr-4">Grade</th><th class="pr-4">Status</th><th class="text-right">Published</th></tr></thead>
        <tbody>
        @forelse($rows as $row)
            <tr class="border-b align-top">
                <td class="py-3 pr-4 font-medium">{{ $row->studentEnrollment?->student?->student_number ?? '—' }}<span class="block">{{ $row->studentEnrollment?->student?->fullName() ?? 'Unknown student' }}</span></td>
                <td class="pr-4">{{ $row->studentEnrollment?->program?->name ?? '—' }} · {{ $row->studentEnrollment?->section?->name ?? '—' }}</td>
                <td class="pr-4">{{ $row->examination?->name ?? '—' }}</td>
                <td class="pr-4">{{ $row->academicTerm?->name ?? $row->academicYear?->name ?? '—' }}</td>
                <td class="pr-4 text-right">{{ $row->total_obtained_marks }} / {{ $row->total_max_marks }}</td>
                <td class="pr-4 text-right">{{ $row->percentage }}%</td>
                <td class="pr-4">{{ $row->overall_grade ?? '—' }}</td>
                <td class="pr-4"><span class="rounded-md px-2 py-1 text-xs font-semibold {{ $statusColors[$row->result_status] ?? 'bg-slate-100 text-slate-600' }}">{{ ucfirst($row->result_status) }}</span></td>
                <td class="text-right whitespace-nowrap">{{ $row->published_at?->format('d M Y H:i') ?? '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="9" class="py-6 text-slate-500">No published results match these filters.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@include('examination_reports._pagination', ['subject' => 'results'])
