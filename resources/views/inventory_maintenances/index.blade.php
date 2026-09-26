@extends('layouts.app')

@section('title', 'Asset Maintenance')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Asset Maintenance</h2>
            <p class="panel-subtitle">Maintenance events for individual assets — preventive service, repair, inspection, calibration. Every record stays linked to its existing asset; external work can reference a vendor. Records are live work orders: edit them as the work progresses. There is no delete.</p>
        </div>
        @if(auth()->user()?->hasPermission('inventory_maintenance.create'))
            <a class="button" href="{{ route('inventory-maintenances.create') }}">+ Record maintenance</a>
        @endif
    </div>

    <form class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-5" method="GET" action="{{ route('inventory-maintenances.index') }}">
        <div class="lg:col-span-2">
            <label class="label" for="item_id">Asset</label>
            <select class="input" id="item_id" name="item_id">
                <option value="">All assets</option>
                @foreach($items as $item)
                    <option value="{{ $item->id }}" @selected((string) $filters['item_id'] === (string) $item->id)>{{ $item->name }} ({{ $item->code }})</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="maintenance_type">Type</label>
            <select class="input" id="maintenance_type" name="maintenance_type">
                <option value="">All types</option>
                @foreach($types as $type)
                    <option value="{{ $type }}" @selected($filters['maintenance_type'] === $type)>{{ ucfirst(str_replace('_', ' ', $type)) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="status">Status</label>
            <select class="input" id="status" name="status">
                <option value="">All statuses</option>
                @foreach($statuses as $status)
                    <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ ucfirst(str_replace('_', ' ', $status)) }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex items-end">
            <button class="button w-full sm:w-auto" type="submit">Filter</button>
        </div>
    </form>

    <div class="mt-6 overflow-x-auto">
        <table class="w-full min-w-[64rem] text-left text-sm">
            <thead>
                <tr class="border-b text-slate-500">
                    <th class="py-2">Title</th>
                    <th>Asset</th>
                    <th>Type</th>
                    <th>Status</th>
                    <th>Scheduled</th>
                    <th>Completed</th>
                    <th class="text-right">Cost</th>
                    <th>Vendor / Performed by</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($maintenances as $maintenance)
                    <tr class="border-b">
                        <td class="font-medium">
                            {{ $maintenance->title }}
                            @if($maintenance->description)
                                <span class="block max-w-[20rem] truncate text-xs text-slate-500">{{ $maintenance->description }}</span>
                            @endif
                        </td>
                        <td>
                            {{ $maintenance->item?->name ?? '—' }}
                            <span class="block font-mono text-xs text-slate-500">{{ $maintenance->item?->code }}{{ $maintenance->item?->serial_number ? ' / '.$maintenance->item->serial_number : '' }}</span>
                        </td>
                        <td class="text-xs">{{ ucfirst(str_replace('_', ' ', $maintenance->maintenance_type)) }}</td>
                        <td>
                            <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $maintenance->isCompleted() ? 'bg-emerald-100 text-emerald-700' : ($maintenance->status === 'in_progress' ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-600') }}">
                                {{ ucfirst(str_replace('_', ' ', $maintenance->status)) }}
                            </span>
                        </td>
                        <td>{{ $maintenance->scheduled_on?->format('d M Y') ?? '—' }}</td>
                        <td>{{ $maintenance->completed_on?->format('d M Y') ?? '—' }}</td>
                        <td class="text-right font-mono text-xs">{{ $maintenance->cost !== null ? number_format((float) $maintenance->cost, 2) : '—' }}</td>
                        <td class="text-xs text-slate-600">
                            {{ $maintenance->vendor?->name ?? '—' }}
                            @if($maintenance->performed_by)
                                <span class="block text-slate-500">{{ $maintenance->performed_by }}</span>
                            @endif
                        </td>
                        <td>
                            @if(auth()->user()?->hasPermission('inventory_maintenance.update'))
                                <a class="text-xs text-indigo-700" href="{{ route('inventory-maintenances.edit', $maintenance) }}">Edit</a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td class="py-4 text-slate-500" colspan="9">No maintenance recorded yet for this college.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-6">{{ $maintenances->links() }}</div>
</div>
@endsection
