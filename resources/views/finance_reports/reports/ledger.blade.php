<p class="panel-subtitle">One row per student, summing their filtered fee assignments: assigned amount, applicable concessions, collected and refunded money, net collected and outstanding balance — derived live from the same ledger as the Due / Outstanding screen. Rows are ordered by student name and paginated 20 per page.</p>
<div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
    <div class="rounded-xl bg-indigo-50 p-4"><p class="text-sm text-indigo-700">Students</p><p class="text-2xl font-bold text-indigo-900">{{ number_format($rows->total()) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Assigned</p><p class="text-2xl font-bold text-slate-900">{{ number_format($totals['assigned'], 2) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Concessions</p><p class="text-2xl font-bold text-slate-900">{{ number_format($totals['concession'], 2) }}</p></div>
    <div class="rounded-xl bg-emerald-50 p-4"><p class="text-sm text-emerald-700">Net collected</p><p class="text-2xl font-bold text-emerald-900">{{ number_format($totals['net_collected'], 2) }}</p></div>
    <div class="rounded-xl bg-amber-50 p-4"><p class="text-sm text-amber-700">Outstanding</p><p class="text-2xl font-bold text-amber-900">{{ number_format($totals['outstanding'], 2) }}</p></div>
</div>
<div class="mt-5 overflow-x-auto">
    <table class="w-full text-left text-sm">
        <thead><tr class="border-b text-slate-500"><th class="py-3 pr-4">Student</th><th class="pr-4">Program / year</th><th class="pr-4">Section</th><th class="pr-4 text-right">Assignments</th><th class="pr-4 text-right">Assigned</th><th class="pr-4 text-right">Concessions</th><th class="pr-4 text-right">Paid</th><th class="pr-4 text-right">Refunded</th><th class="pr-4 text-right">Net collected</th><th class="pr-4 text-right">Outstanding</th><th>Status</th></tr></thead>
        <tbody>
        @forelse($rows as $row)
            <tr class="border-b align-top">
                <td class="py-3 pr-4 font-medium">{{ $row['student']->student_number }}<span class="block">{{ $row['student']->fullName() }}</span></td>
                <td class="pr-4">{{ $row['enrollment']?->program?->name ?? '—' }} · {{ $row['enrollment']?->academicYear?->name ?? '—' }}</td>
                <td class="pr-4">{{ $row['enrollment']?->section?->name ?? '—' }}</td>
                <td class="pr-4 text-right">{{ number_format($row['assignments']) }}</td>
                <td class="pr-4 text-right">{{ number_format($row['assigned'], 2) }}</td>
                <td class="pr-4 text-right">{{ number_format($row['concession'], 2) }}</td>
                <td class="pr-4 text-right">{{ number_format($row['paid'], 2) }}</td>
                <td class="pr-4 text-right">{{ number_format($row['refunded'], 2) }}</td>
                <td class="pr-4 text-right">{{ number_format($row['net_collected'], 2) }}</td>
                <td class="pr-4 text-right font-medium">{{ number_format($row['outstanding'], 2) }}</td>
                <td>{{ ucfirst($row['status']) }}</td>
            </tr>
        @empty
            <tr><td colspan="11" class="py-6 text-slate-500">No students match these filters.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@include('finance_reports._pagination', ['subject' => 'students'])
