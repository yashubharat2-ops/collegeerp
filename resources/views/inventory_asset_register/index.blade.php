@extends('layouts.app')

@section('title', 'Asset Register')

@section('content')
<div class="panel">
    <h2 class="panel-title">Asset Register</h2>
    <p class="panel-subtitle">Individual assets in Items / Assets, with live custody, returns and maintenance from the existing records. Inactive assets remain visible for reference; archived assets are excluded.</p>

    <form class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-5" method="GET" action="{{ route('inventory-asset-register.index') }}">
        <div class="lg:col-span-2">
            <label class="label" for="search">Asset / code / serial</label>
            <input class="input" id="search" name="search" value="{{ $filters['search'] }}">
        </div>
        <div>
            <label class="label" for="category_id">Category</label>
            <select class="input" id="category_id" name="category_id">
                <option value="">All categories</option>
                @foreach($categories as $category)
                    <option value="{{ $category->id }}" @selected((string) $filters['category_id'] === (string) $category->id)>{{ $category->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="status">Status</label>
            <select class="input" id="status" name="status">
                <option value="">All statuses</option>
                @foreach($statuses as $status)
                    <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ ucfirst($status) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="custody">Custody</label>
            <select class="input" id="custody" name="custody">
                <option value="">All</option>
                <option value="assigned" @selected($filters['custody'] === 'assigned')>Assigned</option>
                <option value="unassigned" @selected($filters['custody'] === 'unassigned')>Unassigned</option>
            </select>
        </div>
        <div class="flex items-end"><button class="button w-full sm:w-auto" type="submit">Filter</button></div>
    </form>

    <div class="mt-6 overflow-x-auto">
        <table class="w-full min-w-[64rem] text-left text-sm">
            <thead><tr class="border-b text-slate-500"><th class="py-2">Asset / serial</th><th>Category</th><th>Status</th><th>Custodian</th><th>Last return</th><th>Last service completed</th><th class="text-right">Open maintenance</th></tr></thead>
            <tbody>
                @forelse($assets as $asset)
                    <tr class="border-b">
                        <td class="py-2 font-medium">
                            {{ $asset->name }}
                            <span class="block font-mono text-xs text-slate-500">{{ $asset->code }}{{ $asset->serial_number ? ' / '.$asset->serial_number : '' }}</span>
                            @can('viewAny', App\Models\InventoryAssignment::class)
                                <a class="text-xs text-indigo-700" href="{{ route('inventory-assignments.index', ['item_id' => $asset->id]) }}">Custody history</a>
                            @endcan
                            @can('viewAny', App\Models\InventoryMaintenance::class)
                                <a class="ml-2 text-xs text-indigo-700" href="{{ route('inventory-maintenances.index', ['item_id' => $asset->id]) }}">Maintenance</a>
                            @endcan
                        </td>
                        <td>{{ $asset->category?->name ?? '—' }}</td>
                        <td>{{ ucfirst($asset->status) }}</td>
                        <td>
                            @if($asset->activeAssignment)
                                <span class="font-medium">{{ $asset->activeAssignment->assigneeName() }}</span>
                                <span class="block text-xs text-slate-500">{{ ucfirst($asset->activeAssignment->assigned_to_type === 'faculty' ? 'staff' : 'student') }} · since {{ $asset->activeAssignment->assigned_on->format('d M Y') }}</span>
                            @else
                                <span class="text-slate-500">Unassigned</span>
                            @endif
                        </td>
                        <td>{{ $asset->last_returned_on ? \Illuminate\Support\Carbon::parse($asset->last_returned_on)->format('d M Y') : '—' }}</td>
                        <td>{{ $asset->last_service_on ? \Illuminate\Support\Carbon::parse($asset->last_service_on)->format('d M Y') : '—' }}</td>
                        <td class="text-right">{{ $asset->open_maintenance_count }}</td>
                    </tr>
                @empty
                    <tr><td class="py-4 text-slate-500" colspan="7">No assets match these filters.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-6">{{ $assets->links() }}</div>
</div>
@endsection
