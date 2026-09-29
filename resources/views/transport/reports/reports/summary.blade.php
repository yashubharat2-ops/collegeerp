@php
    $vehicles = $summary['vehicles'];
    $drivers = $summary['drivers'];
    $routes = $summary['routes'];
    $stops = $summary['stops'];
    $assignments = $summary['assignments'];
    $fees = $summary['fees'];
@endphp
<p class="panel-subtitle">The live transport position of the active college, aggregated from the same records the operational screens show: vehicles, drivers, routes / stops, student transport assignments and the shared Finance collections behind transport fees. No report table and no cached figure — every total is read from the operational records.</p>
<div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
    <div class="rounded-xl bg-indigo-50 p-4"><p class="text-sm text-indigo-700">Total vehicles</p><p class="text-2xl font-bold text-indigo-900">{{ number_format($vehicles['total']) }}</p></div>
    <div class="rounded-xl bg-emerald-50 p-4"><p class="text-sm text-emerald-700">Active vehicles</p><p class="text-2xl font-bold text-emerald-900">{{ number_format($vehicles['active']) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Inactive vehicles</p><p class="text-2xl font-bold text-slate-900">{{ number_format($vehicles['inactive']) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Total drivers</p><p class="text-2xl font-bold text-slate-900">{{ number_format($drivers['total']) }}</p></div>
</div>
<div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
    <div class="rounded-xl bg-indigo-50 p-4"><p class="text-sm text-indigo-700">Total routes</p><p class="text-2xl font-bold text-indigo-900">{{ number_format($routes['total']) }}</p></div>
    <div class="rounded-xl bg-indigo-50 p-4"><p class="text-sm text-indigo-700">Total stops</p><p class="text-2xl font-bold text-indigo-900">{{ number_format($stops['total']) }}</p></div>
    <div class="rounded-xl bg-indigo-50 p-4"><p class="text-sm text-indigo-700">Student transport assignments</p><p class="text-2xl font-bold text-indigo-900">{{ number_format($assignments['total']) }}</p></div>
    <div class="rounded-xl bg-emerald-50 p-4"><p class="text-sm text-emerald-700">Active assignments</p><p class="text-2xl font-bold text-emerald-900">{{ number_format($assignments['active']) }}</p></div>
</div>

<div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
    <div class="rounded-xl bg-slate-50 p-4">
        <p class="text-sm font-semibold text-slate-700">Vehicles ({{ number_format($vehicles['total']) }})</p>
        <dl class="mt-2 space-y-1 text-sm text-slate-700">
            <div class="flex justify-between gap-2"><dt>Active</dt><dd class="font-medium">{{ number_format($vehicles['active']) }}</dd></div>
            <div class="flex justify-between gap-2"><dt>Inactive</dt><dd class="font-medium">{{ number_format($vehicles['inactive']) }}</dd></div>
            <div class="flex justify-between gap-2"><dt>Maintenance</dt><dd class="font-medium">{{ number_format($vehicles['maintenance']) }}</dd></div>
            <div class="flex justify-between gap-2"><dt>Retired</dt><dd class="font-medium">{{ number_format($vehicles['retired']) }}</dd></div>
        </dl>
    </div>
    <div class="rounded-xl bg-slate-50 p-4">
        <p class="text-sm font-semibold text-slate-700">Drivers ({{ number_format($drivers['total']) }})</p>
        <dl class="mt-2 space-y-1 text-sm text-slate-700">
            <div class="flex justify-between gap-2"><dt>Active</dt><dd class="font-medium">{{ number_format($drivers['active']) }}</dd></div>
            <div class="flex justify-between gap-2"><dt>Inactive</dt><dd class="font-medium">{{ number_format($drivers['inactive']) }}</dd></div>
        </dl>
    </div>
    <div class="rounded-xl bg-slate-50 p-4">
        <p class="text-sm font-semibold text-slate-700">Routes ({{ number_format($routes['total']) }})</p>
        <dl class="mt-2 space-y-1 text-sm text-slate-700">
            <div class="flex justify-between gap-2"><dt>Active routes</dt><dd class="font-medium">{{ number_format($routes['active']) }}</dd></div>
            <div class="flex justify-between gap-2"><dt>Inactive routes</dt><dd class="font-medium">{{ number_format($routes['inactive']) }}</dd></div>
            <div class="flex justify-between gap-2"><dt>Stops</dt><dd class="font-medium">{{ number_format($stops['total']) }}</dd></div>
            <div class="flex justify-between gap-2"><dt>Active stops</dt><dd class="font-medium">{{ number_format($stops['active']) }}</dd></div>
            <div class="flex justify-between gap-2"><dt>Inactive stops</dt><dd class="font-medium">{{ number_format($stops['inactive']) }}</dd></div>
        </dl>
    </div>
    <div class="rounded-xl bg-slate-50 p-4">
        <p class="text-sm font-semibold text-slate-700">Assignments ({{ number_format($assignments['total']) }})</p>
        <dl class="mt-2 space-y-1 text-sm text-slate-700">
            <div class="flex justify-between gap-2"><dt>Active</dt><dd class="font-medium">{{ number_format($assignments['active']) }}</dd></div>
            <div class="flex justify-between gap-2"><dt>Completed</dt><dd class="font-medium">{{ number_format($assignments['completed']) }}</dd></div>
            <div class="flex justify-between gap-2"><dt>Cancelled</dt><dd class="font-medium">{{ number_format($assignments['cancelled']) }}</dd></div>
        </dl>
    </div>
    <div class="rounded-xl bg-slate-50 p-4">
        <p class="text-sm font-semibold text-slate-700">Transport fees ({{ number_format($fees['assignments']) }} assignments)</p>
        <dl class="mt-2 space-y-1 text-sm text-slate-700">
            <div class="flex justify-between gap-2"><dt>Assigned</dt><dd class="font-medium">{{ number_format($fees['assigned'], 2) }}</dd></div>
            <div class="flex justify-between gap-2"><dt>Collected</dt><dd class="font-medium">{{ number_format($fees['net_collected'], 2) }}</dd></div>
            <div class="flex justify-between gap-2"><dt>Due</dt><dd class="font-semibold">{{ number_format($fees['outstanding'], 2) }}</dd></div>
            <div class="flex justify-between gap-2"><dt>Active / completed / cancelled</dt><dd class="font-medium">{{ $fees['active'] }} / {{ $fees['completed'] }} / {{ $fees['cancelled'] }}</dd></div>
            <div class="flex justify-between gap-2"><dt>Assignments with a balance</dt><dd class="font-medium">{{ number_format($fees['outstanding_assignments']) }}</dd></div>
        </dl>
    </div>
</div>
<p class="mt-5 text-xs text-slate-500">The Transport Summary has no filters: it always describes the whole active college. Open a specific report from the switcher above for filtered detail.</p>
