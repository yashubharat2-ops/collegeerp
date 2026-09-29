<p class="panel-subtitle">Every route of the active college with its existing stops in their stored sequence and times. The route → stop relationship shown here is exactly the one the Routes / Stops screens maintain, with live student assignment counts per route and stop.</p>
<div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
    <div class="rounded-xl bg-indigo-50 p-4"><p class="text-sm text-indigo-700">Routes</p><p class="text-2xl font-bold text-indigo-900">{{ number_format($totals['routes']) }}</p></div>
    <div class="rounded-xl bg-emerald-50 p-4"><p class="text-sm text-emerald-700">Active routes</p><p class="text-2xl font-bold text-emerald-900">{{ number_format($totals['routes_active']) }}</p></div>
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">Stops</p><p class="text-2xl font-bold text-slate-900">{{ number_format($totals['stops']) }}</p></div>
    <div class="rounded-xl bg-emerald-50 p-4"><p class="text-sm text-emerald-700">Active stops</p><p class="text-2xl font-bold text-emerald-900">{{ number_format($totals['stops_active']) }}</p></div>
</div>

@forelse($rows as $route)
    @php($routeCount = $routeCounts[$route->id] ?? ['active' => 0, 'total' => 0])
    <div class="mt-5 rounded-xl border border-slate-200">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b bg-slate-50 px-4 py-3">
            <div>
                <p class="font-semibold text-slate-800">{{ $route->name }} <span class="text-xs font-normal text-slate-500">({{ $route->code }})</span></p>
                <p class="text-xs text-slate-500">{{ $route->description ?: 'No description.' }}</p>
            </div>
            <div class="flex flex-wrap gap-2 text-xs">
                <span class="rounded-lg bg-slate-100 px-2 py-1 font-semibold text-slate-700">Status: {{ ucfirst($route->status) }}</span>
                <span class="rounded-lg bg-indigo-50 px-2 py-1 font-semibold text-indigo-700">{{ number_format($route->stops_count) }} stops ({{ number_format($route->active_stops_count) }} active)</span>
                <span class="rounded-lg bg-emerald-50 px-2 py-1 font-semibold text-emerald-700">{{ number_format($routeCount['active']) }} active students</span>
                <span class="rounded-lg bg-slate-100 px-2 py-1 font-semibold text-slate-700">{{ number_format($routeCount['total']) }} assignments</span>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead><tr class="border-b text-slate-500">
                    <th class="py-2 pl-4 pr-4">#</th><th class="pr-4">Stop</th><th class="pr-4">Code</th>
                    <th class="pr-4">Pickup</th><th class="pr-4">Drop</th><th class="pr-4">Landmark</th>
                    <th class="pr-4">Status</th><th class="pr-4 text-right">Active students</th><th class="text-right">Assignments</th>
                </tr></thead>
                <tbody>
                @forelse($route->stops as $stop)
                    @php($stopCount = $stopCounts[$stop->id] ?? ['active' => 0, 'total' => 0])
                    <tr class="border-b last:border-0 align-top">
                        <td class="py-2 pl-4 pr-4">{{ $stop->sequence }}</td>
                        <td class="pr-4 font-medium">{{ $stop->name }}</td>
                        <td class="pr-4">{{ $stop->code }}</td>
                        <td class="pr-4">{{ $stop->pickup_time ? \Illuminate\Support\Str::of($stop->pickup_time)->beforeLast(':') : '—' }}</td>
                        <td class="pr-4">{{ $stop->drop_time ? \Illuminate\Support\Str::of($stop->drop_time)->beforeLast(':') : '—' }}</td>
                        <td class="pr-4">{{ $stop->landmark ?: '—' }}</td>
                        <td class="pr-4">{{ ucfirst($stop->status) }}</td>
                        <td class="pr-4 text-right">{{ number_format($stopCount['active']) }}</td>
                        <td class="text-right">{{ number_format($stopCount['total']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="py-4 pl-4 text-slate-500">This route has no stops.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@empty
    <p class="mt-5 rounded-xl border border-slate-200 px-4 py-6 text-slate-500">No routes match these filters.</p>
@endforelse
@include('transport.reports._pagination', ['subject' => 'routes'])
