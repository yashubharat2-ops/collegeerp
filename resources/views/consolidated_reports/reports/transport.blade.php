@php
    $vehicles = $summary['vehicles'];
    $drivers = $summary['drivers'];
    $routes = $summary['routes'];
    $stops = $summary['stops'];
    $assignments = $summary['assignments'];
    $fees = $summary['fees'];
@endphp

<p class="panel-subtitle">
    The transport position of the active college, taken from the existing Transport Summary: fleet, drivers, routes and
    stops, student assignments and the transport fee balances that come from the shared Finance ledger.
</p>

<div class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
    <div class="stat-card"><p class="stat-label">Vehicles</p><p class="stat-value">{{ number_format($vehicles['total']) }}</p><p class="stat-hint">{{ number_format($vehicles['active']) }} active · {{ number_format($vehicles['maintenance']) }} in maintenance</p></div>
    <div class="stat-card"><p class="stat-label">Drivers</p><p class="stat-value">{{ number_format($drivers['total']) }}</p><p class="stat-hint">{{ number_format($drivers['active']) }} active</p></div>
    <div class="stat-card"><p class="stat-label">Routes</p><p class="stat-value">{{ number_format($routes['total']) }}</p><p class="stat-hint">{{ number_format($routes['active']) }} active</p></div>
    <div class="stat-card"><p class="stat-label">Stops</p><p class="stat-value">{{ number_format($stops['total']) }}</p><p class="stat-hint">{{ number_format($stops['active']) }} active</p></div>
    <div class="stat-card"><p class="stat-label">Active assignments</p><p class="stat-value">{{ number_format($assignments['active']) }}</p><p class="stat-hint">{{ number_format($assignments['total']) }} recorded</p></div>
</div>

<div class="mt-6 grid gap-5 lg:grid-cols-2">
    <div class="overflow-x-auto">
        <h4 class="text-sm font-semibold text-slate-800">Student transport assignments</h4>
        <table class="mt-3 w-full text-left text-sm">
            <thead class="border-b bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
                <tr><th class="px-3 py-3">Status</th><th class="px-3 py-3 text-right">Assignments</th></tr>
            </thead>
            <tbody class="divide-y">
                <tr><td class="px-3 py-3">Active</td><td class="px-3 py-3 text-right font-mono">{{ number_format($assignments['active']) }}</td></tr>
                <tr><td class="px-3 py-3">Completed</td><td class="px-3 py-3 text-right font-mono">{{ number_format($assignments['completed']) }}</td></tr>
                <tr><td class="px-3 py-3">Cancelled</td><td class="px-3 py-3 text-right font-mono">{{ number_format($assignments['cancelled']) }}</td></tr>
            </tbody>
        </table>
    </div>
    <div class="overflow-x-auto">
        <h4 class="text-sm font-semibold text-slate-800">Transport fees</h4>
        <table class="mt-3 w-full text-left text-sm">
            <thead class="border-b bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
                <tr><th class="px-3 py-3">Measure</th><th class="px-3 py-3 text-right">Value</th></tr>
            </thead>
            <tbody class="divide-y">
                <tr><td class="px-3 py-3">Fee charges</td><td class="px-3 py-3 text-right font-mono">{{ number_format($fees['assignments']) }}</td></tr>
                <tr><td class="px-3 py-3">Assigned</td><td class="px-3 py-3 text-right font-mono">{{ number_format((float) $fees['assigned'], 2) }}</td></tr>
                <tr><td class="px-3 py-3">Net collected</td><td class="px-3 py-3 text-right font-mono">{{ number_format((float) $fees['net_collected'], 2) }}</td></tr>
                <tr><td class="px-3 py-3">Outstanding</td><td class="px-3 py-3 text-right font-mono">{{ number_format((float) $fees['outstanding'], 2) }}</td></tr>
                <tr><td class="px-3 py-3">Charges with a balance</td><td class="px-3 py-3 text-right font-mono">{{ number_format($fees['outstanding_assignments']) }}</td></tr>
            </tbody>
        </table>
    </div>
</div>
