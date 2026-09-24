{{--
    Room form (create + edit).

    The parent building is chosen only on create. XSS safety: every value is
    echoed through Blade's {{ }} escaping.
--}}
@if(isset($room))
    <div class="sm:col-span-2 rounded-2xl border border-slate-200 bg-slate-50 p-4">
        <p class="text-sm font-semibold text-slate-900">{{ $room->building?->name ?? 'Building / Block' }}</p>
        <p class="mt-1 text-xs text-slate-600">{{ $room->building?->hostel?->name ?? $room->hostel?->name ?? '' }}. A room cannot be moved to another building.</p>
    </div>
@else
    <div class="sm:col-span-2">
        <label class="label" for="building_id">Building / Block</label>
        <select class="input" id="building_id" name="building_id" required>
            <option value="">Select a building / block</option>
            @foreach($buildings as $building)
                <option value="{{ $building->id }}" @selected((int) old('building_id', $selectedBuildingId ?? 0) === $building->id)>
                    {{ $building->name }} ({{ $building->hostel?->name ?? '—' }})@if($building->status !== 'active') · inactive @endif
                </option>
            @endforeach
        </select>
        <p class="mt-1 text-xs text-slate-500">The room belongs to a building / block of the active college.</p>
        @if($buildings->isEmpty())
            <p class="mt-1 text-xs text-amber-700">No buildings exist yet for this college — add a hostel and a building first.</p>
        @endif
        <p class="mt-1 text-xs text-rose-600">@error('building_id'){{ $message }}@enderror</p>
    </div>
@endif
<div>
    <label class="label" for="room_number">Room number</label>
    <input class="input" id="room_number" name="room_number" type="text" value="{{ old('room_number', $room->room_number ?? '') }}" required maxlength="50" placeholder="e.g. 101, A-2, G-03">
    <p class="mt-1 text-xs text-slate-500">Unique within this building, including archived records.</p>
    <p class="mt-1 text-xs text-rose-600">@error('room_number'){{ $message }}@enderror</p>
</div>
<div>
    <label class="label" for="capacity">Capacity</label>
    <input class="input" id="capacity" name="capacity" type="number" min="1" max="1000" step="1" required value="{{ old('capacity', $room->capacity ?? '') }}" placeholder="e.g. 2">
    <p class="mt-1 text-xs text-slate-500">Maximum number of beds the room may hold.</p>
    <p class="mt-1 text-xs text-rose-600">@error('capacity'){{ $message }}@enderror</p>
</div>
<div>
    <label class="label" for="floor">Floor</label>
    <input class="input" id="floor" name="floor" type="number" min="0" max="200" step="1" value="{{ old('floor', $room->floor ?? '') }}" placeholder="Optional">
    <p class="mt-1 text-xs text-slate-500">Optional. Must be within the building's declared floors.</p>
    <p class="mt-1 text-xs text-rose-600">@error('floor'){{ $message }}@enderror</p>
</div>
<div>
    <label class="label" for="room_type">Room type</label>
    <input class="input" id="room_type" name="room_type" type="text" value="{{ old('room_type', $room->room_type ?? '') }}" maxlength="100" placeholder="e.g. Single, Double, Dormitory">
    <p class="mt-1 text-xs text-slate-500">Optional free-text classification.</p>
    <p class="mt-1 text-xs text-rose-600">@error('room_type'){{ $message }}@enderror</p>
</div>
<div>
    <label class="label" for="status">Status</label>
    <select class="input" id="status" name="status" required>
        @foreach($statuses as $status)
            <option value="{{ $status }}" @selected(old('status', $room->status ?? 'active') === $status)>{{ ucfirst($status) }}</option>
        @endforeach
    </select>
    <p class="mt-1 text-xs text-slate-500">Inactive rooms stay on record with their beds.</p>
    <p class="mt-1 text-xs text-rose-600">@error('status'){{ $message }}@enderror</p>
</div>
<div class="sm:col-span-2">
    <label class="label" for="description">Description</label>
    <textarea class="input" id="description" name="description" rows="2" maxlength="2000" placeholder="Fittings, attached bath, fan count…">{{ old('description', $room->description ?? '') }}</textarea>
    <p class="mt-1 text-xs text-rose-600">@error('description'){{ $message }}@enderror</p>
</div>
