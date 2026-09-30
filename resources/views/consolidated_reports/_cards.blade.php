@foreach($groups as $group)
    <section class="mt-6 first:mt-0" data-report-group="{{ $group['key'] }}">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h4 class="text-sm font-semibold text-slate-800">{{ $group['title'] }}</h4>
            <span class="text-xs text-slate-500">Source: {{ $group['source'] }}</span>
        </div>
        <div class="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach($group['metrics'] as $metric)
                @php
                    $display = match (true) {
                        $metric['value'] === null => '—',
                        $metric['money'] => number_format((float) $metric['value'], 2),
                        $metric['suffix'] === '%' => number_format((float) $metric['value'], 1).'%',
                        default => number_format((float) $metric['value']),
                    };
                @endphp
                <div class="stat-card">
                    <p class="stat-label">{{ $metric['label'] }}</p>
                    <p class="stat-value">{{ $display }}</p>
                </div>
            @endforeach
        </div>
    </section>
@endforeach
