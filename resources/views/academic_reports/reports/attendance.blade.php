<p class="panel-subtitle">The subject attendance register, newest date first. Program and department are taken from the attendance row's class / section.</p>
<div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
    <div class="rounded-xl bg-indigo-50 p-4"><p class="text-sm text-indigo-700">Records</p><p class="text-2xl font-bold text-indigo-900">{{ number_format(array_sum($counts)) }}</p></div>
    @foreach($counts as $status => $total)
        <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">{{ ucfirst($status) }}</p><p class="text-2xl font-bold text-slate-900">{{ number_format($total) }}</p></div>
    @endforeach
</div>
<div class="mt-5 overflow-x-auto">
    <table class="w-full text-left text-sm">
        <thead><tr class="border-b text-slate-500"><th class="py-3 pr-4">Date</th><th class="pr-4">Student</th><th class="pr-4">Subject</th><th class="pr-4">Class / section · term</th><th class="pr-4">Marked by</th><th class="pr-4">Status</th><th>Remarks</th></tr></thead>
        <tbody>
        @forelse($rows as $row)
            <tr class="border-b align-top">
                <td class="py-3 pr-4 font-medium">{{ $row->attendance_date?->format('d M Y') ?? '—' }}</td>
                <td class="pr-4">{{ $row->student?->student_number ?? '—' }}<span class="block">{{ $row->student?->fullName() ?? 'Unknown student' }}</span></td>
                <td class="pr-4">{{ $row->subject?->name ?? '—' }}<span class="block text-xs text-slate-500">{{ $row->subject?->code }}</span></td>
                <td class="pr-4">{{ $row->section?->name ?? '—' }} · {{ $row->academicTerm?->name ?? '—' }}</td>
                <td class="pr-4">{{ $row->faculty?->full_name ?? '—' }}</td>
                <td class="pr-4">{{ ucfirst($row->status) }}</td>
                <td>{{ $row->remarks ?: '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="7" class="py-6 text-slate-500">No attendance records match these filters.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@include('academic_reports._pagination', ['subject' => 'attendance records'])
