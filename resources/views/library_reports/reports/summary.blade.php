@php
    $books = $summary['books'];
    $copies = $summary['copies'];
    $members = $summary['members'];
    $circulation = $summary['circulation'];
    $fines = $summary['fines'];
    $lostDamaged = $summary['lost_damaged'];
    $master = $summary['master'];
@endphp
<p class="panel-subtitle">The live Library position of the active college, aggregated from the same records the operational screens show: the catalogue, its physical copies, memberships, circulation, renewals, fines and copies marked lost or damaged. No report table and no cached figure — every total is read from the operational records.</p>
<div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
    <div class="rounded-xl bg-indigo-50 p-4"><p class="text-sm text-indigo-700">Total books</p><p class="text-2xl font-bold text-indigo-900">{{ number_format($books['total']) }}</p></div>
    <div class="rounded-xl bg-indigo-50 p-4"><p class="text-sm text-indigo-700">Total copies</p><p class="text-2xl font-bold text-indigo-900">{{ number_format($copies['total']) }}</p></div>
    <div class="rounded-xl bg-emerald-50 p-4"><p class="text-sm text-emerald-700">Available copies</p><p class="text-2xl font-bold text-emerald-900">{{ number_format($copies['available']) }}</p></div>
    <div class="rounded-xl bg-amber-50 p-4"><p class="text-sm text-amber-700">Issued copies</p><p class="text-2xl font-bold text-amber-900">{{ number_format($copies['issued']) }}</p></div>
</div>
<div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Library members</p><p class="text-2xl font-bold text-slate-900">{{ number_format($members['total']) }}</p></div>
    <div class="rounded-xl bg-rose-50 p-4"><p class="text-sm text-rose-700">Current overdue copies</p><p class="text-2xl font-bold text-rose-900">{{ number_format($circulation['overdue']) }}</p></div>
    <div class="rounded-xl bg-rose-50 p-4"><p class="text-sm text-rose-700">Lost / damaged copies</p><p class="text-2xl font-bold text-rose-900">{{ number_format($lostDamaged['copies']) }}</p></div>
    <div class="rounded-xl bg-amber-50 p-4"><p class="text-sm text-amber-700">Fine outstanding</p><p class="text-2xl font-bold text-amber-900">{{ number_format($fines['outstanding'], 2) }}</p></div>
</div>

<div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
    <div class="rounded-xl bg-slate-50 p-4">
        <p class="text-sm font-semibold text-slate-700">Catalogue</p>
        <dl class="mt-2 space-y-1 text-sm text-slate-700">
            <div class="flex justify-between gap-2"><dt>Categories</dt><dd class="font-medium">{{ number_format($master['categories']) }}</dd></div>
            <div class="flex justify-between gap-2"><dt>Authors</dt><dd class="font-medium">{{ number_format($master['authors']) }}</dd></div>
            <div class="flex justify-between gap-2"><dt>Publishers</dt><dd class="font-medium">{{ number_format($master['publishers']) }}</dd></div>
            <div class="flex justify-between gap-2"><dt>Active titles</dt><dd class="font-medium">{{ number_format($books['active']) }}</dd></div>
            <div class="flex justify-between gap-2"><dt>Inactive titles</dt><dd class="font-medium">{{ number_format($books['inactive']) }}</dd></div>
        </dl>
    </div>
    <div class="rounded-xl bg-slate-50 p-4">
        <p class="text-sm font-semibold text-slate-700">Copies ({{ number_format($copies['total']) }})</p>
        <dl class="mt-2 space-y-1 text-sm text-slate-700">
            <div class="flex justify-between gap-2"><dt>Available</dt><dd class="font-medium">{{ number_format($copies['available']) }}</dd></div>
            <div class="flex justify-between gap-2"><dt>Issued</dt><dd class="font-medium">{{ number_format($copies['issued']) }}</dd></div>
            <div class="flex justify-between gap-2"><dt>Lost</dt><dd class="font-medium">{{ number_format($copies['lost']) }}</dd></div>
            <div class="flex justify-between gap-2"><dt>Damaged</dt><dd class="font-medium">{{ number_format($copies['damaged']) }}</dd></div>
            <div class="flex justify-between gap-2"><dt>Withdrawn</dt><dd class="font-medium">{{ number_format($copies['withdrawn']) }}</dd></div>
        </dl>
    </div>
    <div class="rounded-xl bg-slate-50 p-4">
        <p class="text-sm font-semibold text-slate-700">Members ({{ number_format($members['total']) }})</p>
        <dl class="mt-2 space-y-1 text-sm text-slate-700">
            <div class="flex justify-between gap-2"><dt>Active</dt><dd class="font-medium">{{ number_format($members['active']) }}</dd></div>
            <div class="flex justify-between gap-2"><dt>Inactive</dt><dd class="font-medium">{{ number_format($members['inactive']) }}</dd></div>
            <div class="flex justify-between gap-2"><dt>Suspended</dt><dd class="font-medium">{{ number_format($members['suspended']) }}</dd></div>
            <div class="flex justify-between gap-2"><dt>Expired status</dt><dd class="font-medium">{{ number_format($members['expired']) }}</dd></div>
            <div class="flex justify-between gap-2"><dt>Past expiry date</dt><dd class="font-medium">{{ number_format($members['past_expiry']) }}</dd></div>
        </dl>
    </div>
    <div class="rounded-xl bg-slate-50 p-4">
        <p class="text-sm font-semibold text-slate-700">Circulation ({{ number_format($circulation['issues']) }} records)</p>
        <dl class="mt-2 space-y-1 text-sm text-slate-700">
            <div class="flex justify-between gap-2"><dt>Issued (open)</dt><dd class="font-medium">{{ number_format($circulation['issued']) }}</dd></div>
            <div class="flex justify-between gap-2"><dt>Returned</dt><dd class="font-medium">{{ number_format($circulation['returned']) }}</dd></div>
            <div class="flex justify-between gap-2"><dt>Lost</dt><dd class="font-medium">{{ number_format($circulation['lost']) }}</dd></div>
            <div class="flex justify-between gap-2"><dt>Current overdue</dt><dd class="font-semibold">{{ number_format($circulation['overdue']) }}</dd></div>
            <div class="flex justify-between gap-2"><dt>Renewals</dt><dd class="font-medium">{{ number_format($circulation['renewals']) }}</dd></div>
        </dl>
    </div>
    <div class="rounded-xl bg-slate-50 p-4">
        <p class="text-sm font-semibold text-slate-700">Fines ({{ number_format($fines['fines']) }})</p>
        <dl class="mt-2 space-y-1 text-sm text-slate-700">
            <div class="flex justify-between gap-2"><dt>Assessed amount</dt><dd class="font-medium">{{ number_format($fines['assessed_amount'], 2) }}</dd></div>
            <div class="flex justify-between gap-2"><dt>Paid amount</dt><dd class="font-medium">{{ number_format($fines['paid_amount'], 2) }}</dd></div>
            <div class="flex justify-between gap-2"><dt>Outstanding</dt><dd class="font-semibold">{{ number_format($fines['outstanding'], 2) }}</dd></div>
            <div class="flex justify-between gap-2"><dt>Pending / assessed / paid / waived</dt><dd class="font-medium">{{ $fines['pending'] }} / {{ $fines['assessed'] }} / {{ $fines['paid'] }} / {{ $fines['waived'] }}</dd></div>
        </dl>
    </div>
    <div class="rounded-xl bg-slate-50 p-4">
        <p class="text-sm font-semibold text-slate-700">Lost / damaged ({{ number_format($lostDamaged['copies']) }} copies)</p>
        <dl class="mt-2 space-y-1 text-sm text-slate-700">
            <div class="flex justify-between gap-2"><dt>Lost</dt><dd class="font-medium">{{ number_format($lostDamaged['lost']) }}</dd></div>
            <div class="flex justify-between gap-2"><dt>Damaged</dt><dd class="font-medium">{{ number_format($lostDamaged['damaged']) }}</dd></div>
            <div class="flex justify-between gap-2"><dt>Titles affected</dt><dd class="font-medium">{{ number_format($lostDamaged['titles']) }}</dd></div>
        </dl>
    </div>
</div>
<p class="mt-5 text-xs text-slate-500">The Library Summary has no filters: it always describes the whole active college. Open a specific report from the switcher above for filtered detail.</p>
