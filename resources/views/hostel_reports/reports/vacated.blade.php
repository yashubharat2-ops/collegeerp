<p class="panel-subtitle">Vacated students are shown from existing Hostel Allocation history. The note/reason column uses the allocation’s existing remarks field; no vacation workflow or reason is created by this report.</p>

<div class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
    <div class="stat-card"><p class="stat-label">Vacated allocation records</p><p class="stat-value">{{ $vacatedReport['count'] }}</p></div>
    <div class="stat-card"><p class="stat-label">Current page</p><p class="stat-value">{{ $vacatedReport['rows']->count() }}</p><p class="stat-hint">of {{ $vacatedReport['rows']->total() }} matching records</p></div>
    <div class="stat-card"><p class="stat-label">Date range</p><p class="stat-value text-base">{{ $filters['from'] ?: 'Any' }} – {{ $filters['to'] ?: 'Any' }}</p></div>
</div>

<div class="mt-6 overflow-x-auto">
    <table class="w-full min-w-[1320px] text-left text-sm">
        <thead class="border-b bg-slate-50 text-slate-500"><tr>
            <th class="px-3 py-3">Student / enrollment</th><th class="px-3 py-3">Class / section</th><th class="px-3 py-3">Academic year</th><th class="px-3 py-3">Hostel</th><th class="px-3 py-3">Building / block</th><th class="px-3 py-3">Room / bed</th><th class="px-3 py-3">Allocation date</th><th class="px-3 py-3">Vacated date</th><th class="px-3 py-3">Status</th><th class="px-3 py-3">Remarks / reason if recorded</th>
        </tr></thead>
        <tbody class="divide-y">
            @forelse($vacatedReport['rows'] as $allocation)
                @php($student = $allocation->studentEnrollment?->student)
                <tr>
                    <td class="px-3 py-3 font-medium">{{ $student?->student_number ?? '—' }}<span class="block">{{ $student?->fullName() ?? 'Unknown student' }}</span><span class="block text-xs text-slate-500">{{ $allocation->studentEnrollment?->enrollment_number ?? '—' }}</span></td>
                    <td class="px-3 py-3">{{ $allocation->studentEnrollment?->program?->name ?? '—' }} / {{ $allocation->studentEnrollment?->section?->name ?? '—' }}</td>
                    <td class="px-3 py-3">{{ $allocation->academicYear?->name ?? $allocation->studentEnrollment?->academicYear?->name ?? '—' }}</td>
                    <td class="px-3 py-3">{{ $allocation->hostel?->name ?? '—' }}</td>
                    <td class="px-3 py-3">{{ $allocation->building?->name ?? '—' }}</td>
                    <td class="px-3 py-3">{{ $allocation->room?->room_number ?? '—' }} / {{ $allocation->bed?->bed_number ?? '—' }}</td>
                    <td class="px-3 py-3 whitespace-nowrap">{{ $allocation->allocation_date?->format('d M Y') ?? '—' }}</td>
                    <td class="px-3 py-3 whitespace-nowrap">{{ $allocation->vacated_date?->format('d M Y') ?? '—' }}</td>
                    <td class="px-3 py-3">{{ ucfirst($allocation->status) }}</td>
                    <td class="px-3 py-3">{{ $allocation->remarks ?: '—' }}</td>
                </tr>
            @empty
                <tr><td class="px-3 py-8 text-center text-slate-500" colspan="10">No vacated students match these filters.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<div class="no-print mt-4 flex flex-wrap items-center justify-between gap-3 text-xs text-slate-500">
    <span>Showing {{ $vacatedReport['rows']->firstItem() ?? 0 }}–{{ $vacatedReport['rows']->lastItem() ?? 0 }} of {{ $vacatedReport['rows']->total() }} vacated allocation records.</span>
    {{ $vacatedReport['rows']->links() }}
</div>
