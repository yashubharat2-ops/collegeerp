<p class="panel-subtitle">Every fine the Library has assessed, with the member, the copy behind it and the amounts stored by the existing fine calculation. No fine is computed here: days, rate and assessed amount are the row the fine service wrote.</p>
<div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
    <div class="rounded-xl bg-indigo-50 p-4"><p class="text-sm text-indigo-700">Fines</p><p class="text-2xl font-bold text-indigo-900">{{ number_format($totals['fines']) }}</p></div>
    <div class="rounded-xl bg-emerald-50 p-4"><p class="text-sm text-emerald-700">Paid amount</p><p class="text-2xl font-bold text-emerald-900">{{ number_format($totals['paid_amount'], 2) }}</p></div>
    <div class="rounded-xl bg-amber-50 p-4"><p class="text-sm text-amber-700">Outstanding</p><p class="text-2xl font-bold text-amber-900">{{ number_format($totals['outstanding'], 2) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Assessed amount</p><p class="text-2xl font-bold text-slate-900">{{ number_format($totals['assessed_amount'], 2) }}</p></div>
</div>
<div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Pending</p><p class="text-2xl font-bold text-slate-900">{{ number_format($totals['pending']) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Assessed</p><p class="text-2xl font-bold text-slate-900">{{ number_format($totals['assessed']) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Paid</p><p class="text-2xl font-bold text-slate-900">{{ number_format($totals['paid']) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Waived</p><p class="text-2xl font-bold text-slate-900">{{ number_format($totals['waived']) }}</p></div>
</div>
@if($by_status->isNotEmpty())
    <div class="mt-5 overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead><tr class="border-b text-slate-500"><th class="py-3 pr-4">Fine status</th><th class="pr-4 text-right">Fines</th><th class="pr-4 text-right">Assessed</th><th class="text-right">Paid</th></tr></thead>
            <tbody>
            @foreach($by_status as $group)
                <tr class="border-b">
                    <td class="py-3 pr-4 font-medium">{{ ucfirst($group->status) }}</td>
                    <td class="pr-4 text-right">{{ number_format((int) $group->total) }}</td>
                    <td class="pr-4 text-right">{{ number_format((float) $group->assessed, 2) }}</td>
                    <td class="text-right">{{ number_format((float) $group->paid, 2) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endif
<div class="mt-5 overflow-x-auto">
    <table class="w-full text-left text-sm">
        <thead><tr class="border-b text-slate-500"><th class="py-3 pr-4">Member</th><th class="pr-4">Book / Title</th><th class="pr-4">Type</th><th class="pr-4">Period</th><th class="pr-4 text-right">Days</th><th class="pr-4 text-right">Rate / day</th><th class="pr-4 text-right">Assessed</th><th class="pr-4 text-right">Paid</th><th class="pr-4 text-right">Outstanding</th><th class="text-right">Status</th></tr></thead>
        <tbody>
        @forelse($rows as $row)
            <tr class="border-b align-top">
                <td class="py-3 pr-4 font-medium">{{ $row->member?->studentName() ?? '—' }}<span class="block text-xs font-normal text-slate-500">{{ $row->member?->member_code ?? '' }}</span></td>
                <td class="pr-4">{{ $row->transaction?->bookCopy?->book?->title ?? '—' }}<span class="block text-xs text-slate-500">{{ $row->transaction?->bookCopy?->accession_number ?? '—' }}</span></td>
                <td class="pr-4">{{ ucfirst(str_replace('_', ' / ', $row->type)) }}</td>
                <td class="pr-4 whitespace-nowrap">{{ $row->period_start?->format('d M Y') ?? '—' }} → {{ $row->period_end?->format('d M Y') ?? '—' }}</td>
                <td class="pr-4 text-right">{{ number_format((int) $row->days_overdue) }}</td>
                <td class="pr-4 text-right">{{ number_format((float) $row->rate_per_day, 2) }}</td>
                <td class="pr-4 text-right">{{ number_format((float) $row->assessed_amount, 2) }}</td>
                <td class="pr-4 text-right">{{ number_format((float) $row->paid_amount, 2) }}</td>
                <td class="pr-4 text-right font-medium">{{ number_format((float) $row->outstanding(), 2) }}</td>
                <td class="text-right">{{ ucfirst($row->status) }}@if($row->payment_reference)<span class="block text-xs text-slate-500">{{ $row->payment_reference }}</span>@endif</td>
            </tr>
        @empty
            <tr><td colspan="10" class="py-6 text-slate-500">No fines match these filters.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@include('library_reports._pagination', ['subject' => 'fines'])
