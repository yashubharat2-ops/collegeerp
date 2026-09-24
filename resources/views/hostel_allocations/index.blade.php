@extends('layouts.app')

@section('title', 'Hostel Allocations')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Hostel Allocations</h2>
            <p class="panel-subtitle">Allocate existing student enrollments to existing hostel beds. Bed occupancy is derived from active allocations; vacating releases the bed. Historical allocations are preserved.</p>
        </div>
        @can('create', App\Models\HostelAllocation::class)
            <a class="button" href="{{ route('hostel-allocations.create') }}">+ Allocate bed</a>
        @endcan
    </div>

    <form class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4" method="GET" action="{{ route('hostel-allocations.index') }}">
        <div>
            <label class="label" for="search">Search</label>
            <input class="input" id="search" name="search" type="text" value="{{ $filters['search'] ?? '' }}" placeholder="Student / enrollment">
        </div>
        <div>
            <label class="label" for="academic_year_id">Academic Year</label>
            <select class="input" id="academic_year_id" name="academic_year_id">
                <option value="">All years</option>
                @foreach($academicYears as $year)
                    <option value="{{ $year->id }}" @selected((string) $filters['academic_year_id'] === (string) $year->id)>{{ $year->name }}</option>
                @endforeach
            </select>
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
            <label class="label" for="hostel_building_id">Building</label>
            <select class="input" id="hostel_building_id" name="hostel_building_id">
                <option value="">All buildings</option>
                @foreach($buildings as $building)
                    <option value="{{ $building->id }}" @selected((string) $filters['hostel_building_id'] === (string) $building->id)>{{ $building->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="hostel_room_id">Room</label>
            <select class="input" id="hostel_room_id" name="hostel_room_id">
                <option value="">All rooms</option>
                @foreach($rooms as $room)
                    <option value="{{ $room->id }}" @selected((string) $filters['hostel_room_id'] === (string) $room->id)>{{ $room->room_number }} — {{ $room->hostel?->name ?? '—' }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="status">Status</label>
            <select class="input" id="status" name="status">
                <option value="">All statuses</option>
                @foreach($statuses as $statusOption)
                    <option value="{{ $statusOption }}" @selected($filters['status'] === $statusOption)>{{ ucfirst($statusOption) }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex items-end gap-2">
            <button class="button" type="submit">Filter</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('hostel-allocations.index') }}">Clear</a>
        </div>
    </form>

    <div class="mt-6 overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead>
                <tr class="border-b text-slate-500">
                    <th class="py-2">Student</th>
                    <th>Enrollment</th>
                    <th>Academic Year</th>
                    <th>Hostel</th>
                    <th>Building</th>
                    <th>Room</th>
                    <th>Bed</th>
                    <th>Allocation Date</th>
                    <th>Status</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($allocations as $allocation)
                    <tr class="border-b">
                        <td class="py-2 font-medium">
                            {{ $allocation->studentEnrollment?->student?->first_name }} {{ $allocation->studentEnrollment?->student?->last_name }}
                            <span class="block text-xs text-slate-500">{{ $allocation->studentEnrollment?->student?->student_number ?? '—' }}</span>
                        </td>
                        <td>{{ $allocation->studentEnrollment?->enrollment_number ?? '—' }}</td>
                        <td>{{ $allocation->academicYear?->name ?? '—' }}</td>
                        <td>{{ $allocation->hostel?->name ?? '—' }}</td>
                        <td>{{ $allocation->building?->name ?? '—' }}</td>
                        <td>{{ $allocation->room?->room_number ?? '—' }}</td>
                        <td>{{ $allocation->bed?->bed_number ?? '—' }}</td>
                        <td>{{ $allocation->allocation_date?->format('d M Y') }}</td>
                        <td>
                            @php
                                $tone = match ($allocation->status) {
                                    'active' => 'bg-emerald-100 text-emerald-700',
                                    'vacated' => 'bg-slate-100 text-slate-600',
                                    'cancelled' => 'bg-rose-100 text-rose-700',
                                    default => 'bg-slate-100 text-slate-600',
                                };
                            @endphp
                            <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $tone }}">{{ ucfirst($allocation->status) }}</span>
                        </td>
                        <td class="py-2">
                            <div class="flex justify-end gap-2 flex-wrap">
                                <a class="button !bg-slate-200 !text-slate-700" href="{{ route('hostel-allocations.show', $allocation) }}">View</a>
                                @can('update', $allocation)
                                    <a class="button !bg-slate-200 !text-slate-700" href="{{ route('hostel-allocations.edit', $allocation) }}">Edit</a>
                                    @if($allocation->status === 'active')
                                        <form method="POST" action="{{ route('hostel-allocations.vacate', $allocation) }}" onsubmit="return confirm('Vacate this allocation? Bed will be released.');">
                                            @csrf
                                            <input type="hidden" name="vacated_date" value="{{ now()->format('Y-m-d') }}">
                                            <button class="button !bg-amber-100 !text-amber-700" type="submit">Vacate</button>
                                        </form>
                                        <form method="POST" action="{{ route('hostel-allocations.cancel', $allocation) }}" onsubmit="return confirm('Cancel this allocation? Bed will be released.');">
                                            @csrf
                                            <button class="button !bg-rose-100 !text-rose-700" type="submit">Cancel</button>
                                        </form>
                                    @endif
                                @endcan
                                @can('delete', $allocation)
                                    <form method="POST" action="{{ route('hostel-allocations.destroy', $allocation) }}" onsubmit="return confirm('Delete this allocation? Historical allocations should normally be preserved (vacate/cancel instead).');">
                                        @csrf
                                        @method('DELETE')
                                        <button class="button !bg-rose-100 !text-rose-700" type="submit">Delete</button>
                                    </form>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td class="py-4 text-slate-500" colspan="10">No hostel allocations found for this college.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-6">{{ $allocations->links() }}</div>
</div>
@endsection
