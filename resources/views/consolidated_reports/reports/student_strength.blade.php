<p class="panel-subtitle">
    Distinct enrolled students per academic year / program / section, taken from the existing Student Strength
    derivation. Students and enrollments are counted separately and the totals are never the sum of the grouped rows.
</p>

<div class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
    <div class="stat-card">
        <p class="stat-label">Students</p>
        <p class="stat-value">{{ number_format($studentsCount) }}</p>
        <p class="stat-hint">Distinct students matching the selected filters</p>
    </div>
    <div class="stat-card">
        <p class="stat-label">Enrollments</p>
        <p class="stat-value">{{ number_format($enrollmentsCount) }}</p>
        <p class="stat-hint">Enrollment rows matching the selected filters</p>
    </div>
</div>

<h4 class="mt-8 text-sm font-semibold text-slate-800">Strength by academic year / program / section</h4>
<div class="mt-3 overflow-x-auto">
    <table class="w-full min-w-[720px] text-left text-sm">
        <thead class="border-b bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
            <tr>
                <th class="px-3 py-3">Academic year</th>
                <th class="px-3 py-3">Program</th>
                <th class="px-3 py-3">Section / batch</th>
                <th class="px-3 py-3 text-right">Students</th>
            </tr>
        </thead>
        <tbody class="divide-y">
            @forelse($rows as $row)
                <tr>
                    <td class="px-3 py-3">{{ $row->academicYear?->name ?? '—' }}</td>
                    <td class="px-3 py-3">{{ $row->program?->name ?? '—' }}<span class="block text-xs text-slate-500">{{ $row->program?->department?->name }}</span></td>
                    <td class="px-3 py-3">{{ $row->section?->name ?? 'Not sectioned' }}</td>
                    <td class="px-3 py-3 text-right font-mono">{{ number_format((int) $row->students_count) }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="px-3 py-8 text-center text-slate-500">No enrollments match these filters.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

@include('consolidated_reports._pagination', ['rows' => $rows, 'subject' => 'year / program / section groups'])
