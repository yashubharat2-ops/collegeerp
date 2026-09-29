<p class="panel-subtitle">Existing allocation history for live students and enrollments. Counts and rows follow the selected allocation, academic, student and location filters.</p>

<h4 class="mt-5 text-sm font-semibold text-slate-800">Active Allocation Summary</h4>
<div class="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
    <div class="stat-card"><p class="stat-label">Active allocations</p><p class="stat-value">{{ $allocationReport['counts']['active'] }}</p></div>
    <div class="stat-card"><p class="stat-label">Vacated allocations</p><p class="stat-value">{{ $allocationReport['counts']['vacated'] }}</p></div>
    <div class="stat-card"><p class="stat-label">Cancelled allocations</p><p class="stat-value">{{ $allocationReport['counts']['cancelled'] }}</p></div>
    <div class="stat-card"><p class="stat-label">Total allocations</p><p class="stat-value">{{ $allocationReport['counts']['total'] }}</p></div>
</div>

<div class="mt-6 overflow-x-auto">
    <table class="w-full min-w-[1240px] text-left text-sm">
        <thead class="border-b bg-slate-50 text-slate-500"><tr>
            <th class="px-3 py-3">Student / enrollment</th><th class="px-3 py-3">Class / section</th><th class="px-3 py-3">Academic year</th><th class="px-3 py-3">Hostel</th><th class="px-3 py-3">Building / block</th><th class="px-3 py-3">Room / bed</th><th class="px-3 py-3">Allocation date</th><th class="px-3 py-3">Vacated date</th><th class="px-3 py-3">Current status</th>
        </tr></thead>
        <tbody class="divide-y">
            @forelse($allocationReport['rows'] as $allocation)
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
                </tr>
            @empty
                <tr><td class="px-3 py-8 text-center text-slate-500" colspan="9">No hostel allocations match these filters.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<div class="no-print mt-4 flex flex-wrap items-center justify-between gap-3 text-xs text-slate-500">
    <span>Showing {{ $allocationReport['rows']->firstItem() ?? 0 }}–{{ $allocationReport['rows']->lastItem() ?? 0 }} of {{ $allocationReport['rows']->total() }} allocations.</span>
    {{ $allocationReport['rows']->links() }}
</div>
