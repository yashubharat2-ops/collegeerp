{{--
    Bed form (create + edit).

    The parent room is chosen only on create. XSS safety: every value is
    echoed through Blade's {{ }} escaping.
--}}
@if(isset($bed))
    <div class="sm:col-span-2 rounded-2xl border border-slate-200 bg-slate-50 p-4">
        <p class="text-sm font-semibold text-slate-900">Room {{ $bed->room?->room_number ?? '—' }}</p>
        <p class="mt-1 text-xs text-slate-600">{{ $bed->building?->name ?? '—' }} ({{ $bed->hostel?->name ?? '—' }}). A bed cannot be moved to another room.</p>
    </div>
@else
    <div class="sm:col-span-2">
        <label class="label" for="room_id">Room</label>
        <select class="input" id="room_id" name="room_id" required>
            <option value="">Select a room</option>
            @foreach($rooms as $room)
                <option value="{{ $room->id }}" @selected((int) old('room_id', $selectedRoomId ?? 0) === $room->id)>
                    {{ $room->room_number }} — {{ $room->building?->name ?? '—' }} ({{ $room->hostel?->name ?? '—' }})@if($room->status !== 'active') · inactive room @endif
                </option>
            @endforeach
        </select>
        <p class="mt-1 text-xs text-slate-500">The bed belongs to a room of the active college; its hostel and building are derived from the room.</p>
        @if($rooms->isEmpty())
            <p class="mt-1 text-xs text-amber-700">No rooms exist yet for this college — add a hostel, a building and a room first.</p>
        @endif
        <p class="mt-1 text-xs text-rose-600">@error('room_id'){{ $message }}@enderror</p>
    </div>
@endif
<div>
    <label class="label" for="bed_number">Bed number / label</label>
    <input class="input" id="bed_number" name="bed_number" type="text" value="{{ old('bed_number', $bed->bed_number ?? '') }}" required maxlength="50" placeholder="e.g. 1, A, L-B">
    <p class="mt-1 text-xs text-slate-500">Unique within this room, including archived records.</p>
    <p class="mt-1 text-xs text-rose-600">@error('bed_number'){{ $message }}@enderror</p>
</div>
<div>
    <label class="label" for="status">Status</label>
    <select class="input" id="status" name="status" required>
        @foreach($statuses as $status)
            <option value="{{ $status }}" @selected(old('status', $bed->status ?? 'available') === $status)>{{ ucfirst($status) }}</option>
        @endforeach
    </select>
    <p class="mt-1 text-xs text-slate-500">An operational flag for Phase 1; the upcoming hostel allocation screen becomes the source of truth for occupancy.</p>
    <p class="mt-1 text-xs text-rose-600">@error('status'){{ $message }}@enderror</p>
</div>
<div class="sm:col-span-2">
    <label class="label" for="description">Description</label>
    <textarea class="input" id="description" name="description" rows="2" maxlength="2000" placeholder="Bunk level, near window…">{{ old('description', $bed->description ?? '') }}</textarea>
    <p class="mt-1 text-xs text-rose-600">@error('description'){{ $message }}@enderror</p>
</div>
