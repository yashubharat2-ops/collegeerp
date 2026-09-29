<p class="panel-subtitle">Every student transport assignment of the active college, reusing the Student + Enrollment + Transport Assignment relationships as they are stored: the student and enrollment (program / class and section), academic year, route and stop, period and status. Read-only: assignments are maintained on the Student Transport Assignment screen.</p>
<div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
    <div class="rounded-xl bg-indigo-50 p-4"><p class="text-sm text-indigo-700">Assignments</p><p class="text-2xl font-bold text-indigo-900">{{ number_format($totals['total']) }}</p></div>
    <div class="rounded-xl bg-emerald-50 p-4"><p class="text-sm text-emerald-700">Active</p><p class="text-2xl font-bold text-emerald-900">{{ number_format($totals['active']) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Completed</p><p class="text-2xl font-bold text-slate-900">{{ number_format($totals['completed']) }}</p></div>
    <div class="rounded-xl bg-rose-50 p-4"><p class="text-sm text-rose-700">Cancelled</p><p class="text-2xl font-bold text-rose-900">{{ number_format($totals['cancelled']) }}</p></div>
</div>
<div class="mt-5 overflow-x-auto">
    <table class="w-full text-left text-sm">
        <thead><tr class="border-b text-slate-500">
            <th class="py-3 pr-4">Student</th><th class="pr-4">Enrollment</th><th class="pr-4">Program / class</th>
            <th class="pr-4">Section</th><th class="pr-4">Academic year</th><th class="pr-4">Route</th>
            <th class="pr-4">Stop</th><th class="pr-4">Vehicle</th><th class="pr-4">From</th><th class="pr-4">To</th>
            <th class="text-right">Status</th>
        </tr></thead>
        <tbody>
        @forelse($rows as $assignment)
            <tr class="border-b align-top">
                <td class="py-3 pr-4 font-medium">{{ $assignment->studentEnrollment?->student?->fullName() ?? '—' }}<span class="block text-xs font-normal text-slate-500">{{ $assignment->studentEnrollment?->student?->student_number }}</span></td>
                <td class="pr-4">{{ $assignment->studentEnrollment?->enrollment_number ?? '—' }}</td>
                <td class="pr-4">{{ $assignment->studentEnrollment?->program?->name ?? '—' }}</td>
                <td class="pr-4">{{ $assignment->studentEnrollment?->section?->name ?? '—' }}</td>
                <td class="pr-4">{{ $assignment->academicYear?->name ?? '—' }}</td>
                <td class="pr-4">{{ $assignment->transportRoute?->name ?? '—' }}<span class="block text-xs font-normal text-slate-500">{{ $assignment->transportRoute?->code }}</span></td>
                <td class="pr-4">{{ $assignment->transportStop?->name ?? '—' }}<span class="block text-xs font-normal text-slate-500">{{ $assignment->transportStop?->sequence ? 'Stop #'.$assignment->transportStop->sequence : '' }}</span></td>
                <td class="pr-4">—</td>
                <td class="pr-4">{{ $assignment->start_date?->format('d M Y') ?? '—' }}</td>
                <td class="pr-4">{{ $assignment->end_date?->format('d M Y') ?? '—' }}</td>
                <td class="text-right">{{ ucfirst($assignment->status) }}</td>
            </tr>
        @empty
            <tr><td colspan="11" class="py-6 text-slate-500">No transport assignments match these filters.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<p class="mt-3 text-xs text-slate-500">The existing Transport schema records no vehicle on a student transport assignment (assignments reference a route and stop), so the Vehicle column stays “—” and the report never invents a pairing.</p>
@include('transport.reports._pagination', ['subject' => 'assignments'])
