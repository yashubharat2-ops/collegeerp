<p class="panel-subtitle">Attendance rows of exam sessions, newest exam date first. Counts and the attendance rate reflect the active filters.</p>
<div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
    <div class="rounded-xl bg-indigo-50 p-4"><p class="text-sm text-indigo-700">Records</p><p class="text-2xl font-bold text-indigo-900">{{ number_format($rows->total()) }}</p></div>
    @foreach($counts as $status => $total)
        <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">{{ ucfirst($status) }}</p><p class="text-2xl font-bold text-slate-900">{{ number_format($total) }}</p></div>
    @endforeach
    <div class="rounded-xl bg-emerald-50 p-4"><p class="text-sm text-emerald-700">Attendance rate</p><p class="text-2xl font-bold text-emerald-900">{{ $attendanceRate === null ? '—' : $attendanceRate.'%' }}</p></div>
</div>
<div class="mt-5 overflow-x-auto">
    <table class="w-full text-left text-sm">
        <thead><tr class="border-b text-slate-500"><th class="py-3 pr-4">Exam date</th><th class="pr-4">Student</th><th class="pr-4">Examination · subject</th><th class="pr-4">Class / section</th><th class="pr-4">Status</th><th class="text-right">Remarks</th></tr></thead>
        <tbody>
        @forelse($rows as $row)
            <tr class="border-b align-top">
                <td class="py-3 pr-4 font-medium whitespace-nowrap">{{ $row->examSchedule?->exam_date?->format('d M Y') ?? '—' }}</td>
                <td class="pr-4">{{ $row->studentEnrollment?->student?->student_number ?? '—' }}<span class="block">{{ $row->studentEnrollment?->student?->fullName() ?? 'Unknown student' }}</span></td>
                <td class="pr-4">{{ $row->examSchedule?->examination?->name ?? '—' }}<span class="block text-xs text-slate-500">{{ $row->examSchedule?->subject?->name ?? '—' }}</span></td>
                <td class="pr-4">{{ $row->studentEnrollment?->program?->name ?? '—' }} · {{ $row->studentEnrollment?->section?->name ?? '—' }}</td>
                <td class="pr-4">{{ ucfirst($row->attendance_status) }}</td>
                <td class="text-right">{{ $row->remarks ?: '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="py-6 text-slate-500">No attendance records match these filters.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@include('examination_reports._pagination', ['subject' => 'attendance records'])
