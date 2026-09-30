<p class="panel-subtitle">
    The management view of the whole college: the headline figures of every module, the ratios they form between them,
    and the open / pending / overdue figures the modules already publish. Only existing data is used — no field is
    invented, nothing is estimated, and no figure is stored anywhere.
</p>

@include('consolidated_reports._cards', ['groups' => $summary['groups']])

<h4 class="mt-8 text-sm font-semibold text-slate-800">Management indicators</h4>
<p class="mt-1 text-xs text-slate-500">Each indicator is arithmetic over two figures read live above; the formula is shown with it.</p>
<div class="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
    @foreach($summary['indicators'] as $indicator)
        <div class="stat-card">
            <p class="stat-label">{{ $indicator['label'] }}</p>
            <p class="stat-value">{{ $indicator['value'] === null ? '—' : number_format((float) $indicator['value'], ($indicator['suffix'] === '%' ? 1 : 2)).$indicator['suffix'] }}</p>
            <p class="stat-hint">{{ $indicator['formula'] }}</p>
        </div>
    @endforeach
</div>

<h4 class="mt-8 text-sm font-semibold text-slate-800">Figures that need attention</h4>
<p class="mt-1 text-xs text-slate-500">Open, pending and overdue counts and amounts, exactly as the owning module reports them.</p>
<div class="mt-3 overflow-x-auto">
    <table class="w-full min-w-[720px] text-left text-sm">
        <thead class="border-b bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
            <tr>
                <th class="px-3 py-3">Module</th>
                <th class="px-3 py-3">Item</th>
                <th class="px-3 py-3 text-right">Value</th>
                <th class="px-3 py-3">Status</th>
            </tr>
        </thead>
        <tbody class="divide-y">
            @foreach($summary['attention'] as $row)
                <tr>
                    <td class="px-3 py-3 font-medium text-slate-700">{{ $row['module'] }}</td>
                    <td class="px-3 py-3">{{ $row['label'] }}</td>
                    <td class="px-3 py-3 text-right font-mono">
                        {{ $row['money'] ? number_format((float) $row['value'], 2) : number_format((float) $row['value']) }}
                    </td>
                    <td class="px-3 py-3">
                        <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $row['attention'] ? 'bg-amber-100 text-amber-800' : 'bg-emerald-100 text-emerald-800' }}">
                            {{ $row['attention'] ? 'Attention' : 'Clear' }}
                        </span>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>

<h4 class="mt-8 text-sm font-semibold text-slate-800">Consolidated module summary</h4>
<p class="mt-1 text-xs text-slate-500">The same figures as the cards above, in one table for printing and comparison.</p>
<div class="mt-3 overflow-x-auto">
    <table class="w-full min-w-[640px] text-left text-sm">
        <thead class="border-b bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
            <tr>
                <th class="px-3 py-3">Module</th>
                <th class="px-3 py-3">Metric</th>
                <th class="px-3 py-3 text-right">Value</th>
            </tr>
        </thead>
        <tbody class="divide-y">
            @foreach($summary['groups'] as $group)
                @foreach($group['metrics'] as $metric)
                    @php
                        $display = match (true) {
                            $metric['value'] === null => '—',
                            $metric['money'] => number_format((float) $metric['value'], 2),
                            $metric['suffix'] === '%' => number_format((float) $metric['value'], 1).'%',
                            default => number_format((float) $metric['value']),
                        };
                    @endphp
                    <tr>
                        <td class="px-3 py-3 font-medium text-slate-700">{{ $group['title'] }}<span class="block text-xs font-normal text-slate-500">{{ $group['source'] }}</span></td>
                        <td class="px-3 py-3">{{ $metric['label'] }}</td>
                        <td class="px-3 py-3 text-right font-mono">{{ $display }}</td>
                    </tr>
                @endforeach
            @endforeach
        </tbody>
    </table>
</div>
