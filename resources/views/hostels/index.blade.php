@extends('layouts.app')

@section('title', 'Hostels')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Hostels</h2>
            <p class="panel-subtitle">The hostel masters of the active college — the top of the Hostel Management hierarchy (hostel → building / block → room → bed). Student allocation, fees and attendance are separate screens planned for later phases.</p>
        </div>
        @can('create', App\Models\Hostel::class)
            <a class="button" href="{{ route('hostels.create') }}">+ Add hostel</a>
        @endcan
    </div>

    <form class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-5" method="GET" action="{{ route('hostels.index') }}">
        <div>
            <label class="label" for="search">Search</label>
            <input class="input" id="search" name="search" type="text" value="{{ $search }}" placeholder="Name or code">
        </div>
        <div>
            <label class="label" for="hostel_type">Type</label>
            <select class="input" id="hostel_type" name="hostel_type">
                <option value="">All types</option>
                @foreach($types as $typeOption)
                    <option value="{{ $typeOption }}" @selected($filters['hostel_type'] === $typeOption)>{{ ucfirst($typeOption) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="gender">Gender</label>
            <select class="input" id="gender" name="gender">
                <option value="">All genders</option>
                @foreach($genders as $genderOption)
                    <option value="{{ $genderOption }}" @selected($filters['gender'] === $genderOption)>{{ ucfirst($genderOption) }}</option>
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
                    <th class="py-2">Name</th>
                    <th>Code</th>
                    <th>Type</th>
                    <th>Gender</th>
                    <th>Buildings</th>
                    <th>Rooms</th>
                    <th>Beds</th>
                    <th>Status</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($hostels as $hostel)
                    <tr class="border-b">
                        <td class="py-2 font-medium">{{ $hostel->name }}</td>
                        <td><span class="font-mono text-xs">{{ $hostel->code }}</span></td>
                        <td>{{ ucfirst($hostel->hostel_type) }}</td>
                        <td>{{ ucfirst($hostel->gender) }}</td>
                        <td>
                            @if($hostel->buildings_count > 0 && auth()->user()?->can('viewAny', App\Models\HostelBuilding::class))
                                <a class="text-indigo-600 hover:underline" href="{{ route('hostel-buildings.index', ['hostel_id' => $hostel->id]) }}">{{ $hostel->buildings_count }}</a>
                            @else
                                {{ $hostel->buildings_count }}
                            @endif
                        </td>
                        <td>
                            @if($hostel->rooms_count > 0 && auth()->user()?->can('viewAny', App\Models\HostelRoom::class))
                                <a class="text-indigo-600 hover:underline" href="{{ route('hostel-rooms.index', ['hostel_id' => $hostel->id]) }}">{{ $hostel->rooms_count }}</a>
                            @else
                                {{ $hostel->rooms_count }}
                            @endif
                        </td>
                        <td>
                            @if($hostel->beds_count > 0 && auth()->user()?->can('viewAny', App\Models\HostelBed::class))
                                <a class="text-indigo-600 hover:underline" href="{{ route('hostel-beds.index', ['hostel_id' => $hostel->id]) }}">{{ $hostel->beds_count }}</a>
                            @else
                                {{ $hostel->beds_count }}
                            @endif
                        </td>
                        <td>
                            <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $hostel->status === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">{{ ucfirst($hostel->status) }}</span>
                        </td>
                        <td class="py-2">
                            <div class="flex justify-end gap-2">
                                @can('update', $hostel)
                                    <a class="button !bg-slate-200 !text-slate-700" href="{{ route('hostels.edit', $hostel) }}">Edit</a>
                                @endcan
                                @can('delete', $hostel)
                                    <form method="POST" action="{{ route('hostels.destroy', $hostel) }}" onsubmit="return confirm('Delete the hostel &quot;{{ $hostel->name }}&quot;? Hostels with buildings, rooms or beds cannot be deleted.');">
                                        @csrf
                                        @method('DELETE')
                                        <button class="button !bg-rose-100 !text-rose-700" type="submit">Delete</button>
                                    </form>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td class="py-4 text-slate-500" colspan="9">No hostels configured yet for this college.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-6">{{ $hostels->links() }}</div>
</div>
@endsection
