@extends('layouts.app')

@section('title', 'Buildings / Blocks')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Buildings / Blocks</h2>
            <p class="panel-subtitle">The buildings / blocks inside the active college's hostels. Each building belongs to exactly one hostel; its rooms and beds hang below it in the hierarchy.</p>
        </div>
        @can('create', App\Models\HostelBuilding::class)
            <a class="button" href="{{ route('hostel-buildings.create', array_filter(['hostel_id' => $filters['hostel_id']])) }}">+ Add building</a>
        @endcan
    </div>

    <form class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4" method="GET" action="{{ route('hostel-buildings.index') }}">
        <div>
            <label class="label" for="search">Search</label>
            <input class="input" id="search" name="search" type="text" value="{{ $search }}" placeholder="Name or code">
        </div>
        <div>
            <label class="label" for="hostel_id">Hostel</label>
            <select class="input" id="hostel_id" name="hostel_id">
                <option value="">All hostels</option>
                @foreach($hostels as $hostel)
                    <option value="{{ $hostel->id }}" @selected((string) $filters['hostel_id'] === (string) $hostel->id)>{{ $hostel->name }} ({{ $hostel->code }})</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="status">Status</label>
            <select class="input" id="status" name="status">
                <option value="">All statuses</option>
                @foreach($statuses as $statusOption)
                    <option value="{{ $statusOption }}" @selected($status === $statusOption)>{{ ucfirst($statusOption) }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex items-end">
            <button class="button" type="submit">Filter</button>
        </div>
    </form>

    <div class="mt-6 overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead>
                <tr class="border-b text-slate-500">
                    <th class="py-2">Building / Block</th>
                    <th>Code</th>
                    <th>Hostel</th>
                    <th>Floors</th>
                    <th>Rooms</th>
                    <th>Beds</th>
                    <th>Status</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($buildings as $building)
                    <tr class="border-b">
                        <td class="py-2 font-medium">{{ $building->name }}</td>
                        <td><span class="font-mono text-xs">{{ $building->code }}</span></td>
                        <td>
                            @if($building->hostel && auth()->user()?->can('viewAny', App\Models\Hostel::class))
                                <a class="text-indigo-600 hover:underline" href="{{ route('hostels.index', ['search' => $building->hostel->name]) }}">{{ $building->hostel->name }}</a>
                            @else
                                {{ $building->hostel?->name ?? '—' }}
                            @endif
                        </td>
                        <td>{{ $building->floors ?? '—' }}</td>
                        <td>
                            @if($building->rooms_count > 0 && auth()->user()?->can('viewAny', App\Models\HostelRoom::class))
                                <a class="text-indigo-600 hover:underline" href="{{ route('hostel-rooms.index', ['building_id' => $building->id]) }}">{{ $building->rooms_count }}</a>
                            @else
                                {{ $building->rooms_count }}
                            @endif
                        </td>
                        <td>
                            @if($building->beds_count > 0 && auth()->user()?->can('viewAny', App\Models\HostelBed::class))
                                <a class="text-indigo-600 hover:underline" href="{{ route('hostel-beds.index', ['building_id' => $building->id]) }}">{{ $building->beds_count }}</a>
                            @else
                                {{ $building->beds_count }}
                            @endif
                        </td>
                        <td>
                            <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $building->status === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">{{ ucfirst($building->status) }}</span>
                        </td>
                        <td class="py-2">
                            <div class="flex justify-end gap-2">
                                @can('update', $building)
                                    <a class="button !bg-slate-200 !text-slate-700" href="{{ route('hostel-buildings.edit', $building) }}">Edit</a>
                                @endcan
                                @can('delete', $building)
                                    <form method="POST" action="{{ route('hostel-buildings.destroy', $building) }}" onsubmit="return confirm('Delete the building &quot;{{ $building->name }}&quot;? Buildings with rooms or beds cannot be deleted.');">
                                        @csrf
                                        @method('DELETE')
                                        <button class="button !bg-rose-100 !text-rose-700" type="submit">Delete</button>
                                    </form>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td class="py-4 text-slate-500" colspan="8">No buildings / blocks configured yet for this college.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-6">{{ $buildings->links() }}</div>
</div>
@endsection
