@extends('layouts.app')

@section('title', 'Beds')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Beds</h2>
            <p class="panel-subtitle">Beds inside the active college's hostel rooms — the leaf of the hierarchy. The status is a Phase 1 operational flag; hostel allocation (a later phase) becomes the source of truth for occupancy. Beds beyond a room's capacity cannot be created.</p>
        </div>
        @can('create', App\Models\HostelBed::class)
            <a class="button" href="{{ route('hostel-beds.create', array_filter(['room_id' => $filters['room_id']])) }}">+ Add bed</a>
        @endcan
    </div>

    <form class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-6" method="GET" action="{{ route('hostel-beds.index') }}">
        <div>
            <label class="label" for="search">Search</label>
            <input class="input" id="search" name="search" type="text" value="{{ $search }}" placeholder="Bed / room / building">
        </div>
        <div>
            <label class="label" for="hostel_id">Hostel</label>
            <select class="input" id="hostel_id" name="hostel_id">
                <option value="">All hostels</option>
                @foreach($hostels as $hostel)
                    <option value="{{ $hostel->id }}" @selected((string) $filters['hostel_id'] === (string) $hostel->id)>{{ $hostel->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="building_id">Building</label>
            <select class="input" id="building_id" name="building_id">
                <option value="">All buildings</option>
                @foreach($buildings as $building)
                    <option value="{{ $building->id }}" @selected((string) $filters['building_id'] === (string) $building->id)>{{ $building->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="room_id">Room</label>
            <select class="input" id="room_id" name="room_id">
                <option value="">All rooms</option>
                @foreach($rooms as $room)
                    <option value="{{ $room->id }}" @selected((string) $filters['room_id'] === (string) $room->id)>{{ $room->room_number }} — {{ $room->hostel?->name ?? '—' }}</option>
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
                    <th class="py-2">Bed</th>
                    <th>Room</th>
                    <th>Building / Block</th>
                    <th>Hostel</th>
                    <th>Description</th>
                    <th>Status</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($beds as $bed)
                    <tr class="border-b">
                        <td class="py-2 font-medium">{{ $bed->bed_number }}</td>
                        <td>{{ $bed->room?->room_number ?? '—' }}</td>
                        <td>{{ $bed->building?->name ?? '—' }}</td>
                        <td>{{ $bed->hostel?->name ?? '—' }}</td>
                        <td class="max-w-md truncate text-slate-600">{{ $bed->description ?? '—' }}</td>
                        <td>
                            @php
                                $tone = match ($bed->status) {
                                    'available' => 'bg-emerald-100 text-emerald-700',
                                    'occupied' => 'bg-indigo-100 text-indigo-700',
                                    default => 'bg-slate-100 text-slate-600',
                                };
                            @endphp
                            <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $tone }}">{{ ucfirst($bed->status) }}</span>
                        </td>
                        <td class="py-2">
                            <div class="flex justify-end gap-2">
                                @can('update', $bed)
                                    <a class="button !bg-slate-200 !text-slate-700" href="{{ route('hostel-beds.edit', $bed) }}">Edit</a>
                                @endcan
                                @can('delete', $bed)
                                    <form method="POST" action="{{ route('hostel-beds.destroy', $bed) }}" onsubmit="return confirm('Delete bed &quot;{{ $bed->bed_number }}&quot;? Occupied beds cannot be deleted.');">
                                        @csrf
                                        @method('DELETE')
                                        <button class="button !bg-rose-100 !text-rose-700" type="submit">Delete</button>
                                    </form>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td class="py-4 text-slate-500" colspan="7">No beds recorded yet for this college.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-6">{{ $beds->links() }}</div>
</div>
@endsection
