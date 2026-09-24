@extends('layouts.app')

@section('title', 'Rooms')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Rooms</h2>
            <p class="panel-subtitle">Rooms inside the active college's hostel buildings / blocks. Each room belongs to exactly one building; its capacity is the maximum number of beds it may hold.</p>
        </div>
        @can('create', App\Models\HostelRoom::class)
            <a class="button" href="{{ route('hostel-rooms.create', array_filter(['building_id' => $filters['building_id']])) }}">+ Add room</a>
        @endcan
    </div>

    <form class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-5" method="GET" action="{{ route('hostel-rooms.index') }}">
        <div>
            <label class="label" for="search">Search</label>
            <input class="input" id="search" name="search" type="text" value="{{ $search }}" placeholder="Room number, type or building">
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
            <label class="label" for="building_id">Building / Block</label>
            <select class="input" id="building_id" name="building_id">
                <option value="">All buildings</option>
                @foreach($buildings as $building)
                    <option value="{{ $building->id }}" @selected((string) $filters['building_id'] === (string) $building->id)>{{ $building->name }} ({{ $building->hostel?->name ?? '—' }})</option>
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
                    <th class="py-2">Room</th>
                    <th>Building / Block</th>
                    <th>Hostel</th>
                    <th>Floor</th>
                    <th>Type</th>
                    <th>Beds / Capacity</th>
                    <th>Status</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($rooms as $room)
                    <tr class="border-b">
                        <td class="py-2 font-medium">{{ $room->room_number }}</td>
                        <td>{{ $room->building?->name ?? '—' }}</td>
                        <td>{{ $room->building?->hostel?->name ?? $room->hostel?->name ?? '—' }}</td>
                        <td>{{ $room->floor !== null ? $room->floor : '—' }}</td>
                        <td>{{ $room->room_type ?? '—' }}</td>
                        <td>
                            <a class="text-indigo-600 hover:underline" href="{{ route('hostel-beds.index', ['room_id' => $room->id]) }}">{{ $room->beds_count }}</a> / {{ $room->capacity }}
                        </td>
                        <td>
                            <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $room->status === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">{{ ucfirst($room->status) }}</span>
                        </td>
                        <td class="py-2">
                            <div class="flex justify-end gap-2">
                                @can('update', $room)
                                    <a class="button !bg-slate-200 !text-slate-700" href="{{ route('hostel-rooms.edit', $room) }}">Edit</a>
                                @endcan
                                @can('delete', $room)
                                    <form method="POST" action="{{ route('hostel-rooms.destroy', $room) }}" onsubmit="return confirm('Delete room &quot;{{ $room->room_number }}&quot;? Rooms with beds cannot be deleted.');">
                                        @csrf
                                        @method('DELETE')
                                        <button class="button !bg-rose-100 !text-rose-700" type="submit">Delete</button>
                                    </form>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td class="py-4 text-slate-500" colspan="8">No rooms configured yet for this college.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-6">{{ $rooms->links() }}</div>
</div>
@endsection
