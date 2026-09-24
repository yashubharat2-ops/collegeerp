@php
    $isEdit = isset($allocation) && $allocation->exists;
@endphp

<div class="grid gap-4 sm:grid-cols-2">
    @if(!$isEdit)
        <div class="sm:col-span-2">
            <label class="label" for="student_enrollment_id">Student Enrollment *</label>
            <select class="input" id="student_enrollment_id" name="student_enrollment_id" required>
                <option value="">Select enrollment</option>
                @foreach($enrollments as $enrollment)
                    <option value="{{ $enrollment->id }}" @selected((string) old('student_enrollment_id', $allocation->student_enrollment_id ?? '') === (string) $enrollment->id)>
                        {{ $enrollment->student?->first_name }} {{ $enrollment->student?->last_name }} — {{ $enrollment->enrollment_number }} ({{ $enrollment->academicYear?->name ?? '—' }})
                    </option>
                @endforeach
            </select>
            @error('student_enrollment_id')<p class="text-xs text-rose-600 mt-1">{{ $message }}</p>@enderror
        </div>

        <div>
            <label class="label" for="academic_year_id">Academic Year *</label>
            <select class="input" id="academic_year_id" name="academic_year_id" required>
                <option value="">Select academic year</option>
                @foreach($academicYears as $year)
                    <option value="{{ $year->id }}" @selected((string) old('academic_year_id', $allocation->academic_year_id ?? $selected['academic_year_id'] ?? '') === (string) $year->id)>{{ $year->name }}</option>
                @endforeach
            </select>
            @error('academic_year_id')<p class="text-xs text-rose-600 mt-1">{{ $message }}</p>@enderror
        </div>

        <div>
            <label class="label" for="hostel_id">Hostel *</label>
            <select class="input" id="hostel_id" name="hostel_id" required>
                <option value="">Select hostel</option>
                @foreach($hostels as $hostel)
                    <option value="{{ $hostel->id }}" @selected((string) old('hostel_id', $allocation->hostel_id ?? $selected['hostel_id'] ?? '') === (string) $hostel->id)>{{ $hostel->name }} ({{ $hostel->code }})</option>
                @endforeach
            </select>
            @error('hostel_id')<p class="text-xs text-rose-600 mt-1">{{ $message }}</p>@enderror
        </div>

        <div>
            <label class="label" for="hostel_building_id">Building / Block *</label>
            <select class="input" id="hostel_building_id" name="hostel_building_id" required>
                <option value="">Select building</option>
                @foreach($buildings as $building)
                    <option value="{{ $building->id }}" @selected((string) old('hostel_building_id', $allocation->hostel_building_id ?? '') === (string) $building->id)>{{ $building->name }} ({{ $building->hostel?->name ?? '—' }})</option>
                @endforeach
            </select>
            @error('hostel_building_id')<p class="text-xs text-rose-600 mt-1">{{ $message }}</p>@enderror
        </div>

        <div>
            <label class="label" for="hostel_room_id">Room *</label>
            <select class="input" id="hostel_room_id" name="hostel_room_id" required>
                <option value="">Select room</option>
                @foreach($rooms as $room)
                    <option value="{{ $room->id }}" @selected((string) old('hostel_room_id', $allocation->hostel_room_id ?? '') === (string) $room->id)>{{ $room->room_number }} — {{ $room->building?->name ?? '—' }} ({{ $room->hostel?->name ?? '—' }})</option>
                @endforeach
            </select>
            @error('hostel_room_id')<p class="text-xs text-rose-600 mt-1">{{ $message }}</p>@enderror
        </div>

        <div>
            <label class="label" for="hostel_bed_id">Bed *</label>
            <select class="input" id="hostel_bed_id" name="hostel_bed_id" required>
                <option value="">Select bed</option>
                @foreach($beds as $bed)
                    <option value="{{ $bed->id }}" @selected((string) old('hostel_bed_id', $allocation->hostel_bed_id ?? '') === (string) $bed->id)>{{ $bed->bed_number }} — {{ $bed->room?->room_number ?? '—' }} ({{ $bed->building?->name ?? '—' }}) — {{ ucfirst($bed->status) }}</option>
                @endforeach
            </select>
            @error('hostel_bed_id')<p class="text-xs text-rose-600 mt-1">{{ $message }}</p>@enderror
        </div>
    @else
        <div class="sm:col-span-2 rounded-lg bg-slate-50 p-4 text-sm">
            <p class="font-semibold">Allocation immutable fields (vacate and reallocate to change)</p>
            <p>Student: {{ $allocation->studentEnrollment?->student?->first_name }} {{ $allocation->studentEnrollment?->student?->last_name }} — {{ $allocation->studentEnrollment?->enrollment_number }}</p>
            <p>Academic Year: {{ $allocation->academicYear?->name }}</p>
            <p>Hostel: {{ $allocation->hostel?->name }} / {{ $allocation->building?->name }} / Room {{ $allocation->room?->room_number }} / Bed {{ $allocation->bed?->bed_number }}</p>
        </div>
    @endif

    <div>
        <label class="label" for="allocation_date">Allocation Date *</label>
        <input class="input" id="allocation_date" name="allocation_date" type="date" required value="{{ old('allocation_date', $allocation->allocation_date?->format('Y-m-d') ?? now()->format('Y-m-d')) }}">
        @error('allocation_date')<p class="text-xs text-rose-600 mt-1">{{ $message }}</p>@enderror
    </div>

    <div>
        <label class="label" for="status">Status</label>
        <select class="input" id="status" name="status">
            @foreach($statuses as $statusOption)
                <option value="{{ $statusOption }}" @selected(old('status', $allocation->status ?? 'active') === $statusOption)>{{ ucfirst($statusOption) }}</option>
            @endforeach
        </select>
        @error('status')<p class="text-xs text-rose-600 mt-1">{{ $message }}</p>@enderror
    </div>

    <div>
        <label class="label" for="vacated_date">Vacated Date</label>
        <input class="input" id="vacated_date" name="vacated_date" type="date" value="{{ old('vacated_date', $allocation->vacated_date?->format('Y-m-d') ?? '') }}">
        <p class="text-xs text-slate-500 mt-1">Required only when status is vacated; must be >= allocation date. Active allocations must have no vacated date.</p>
        @error('vacated_date')<p class="text-xs text-rose-600 mt-1">{{ $message }}</p>@enderror
    </div>

    <div class="sm:col-span-2">
        <label class="label" for="remarks">Remarks</label>
        <textarea class="input" id="remarks" name="remarks" rows="3" maxlength="2000">{{ old('remarks', $allocation->remarks ?? '') }}</textarea>
        @error('remarks')<p class="text-xs text-rose-600 mt-1">{{ $message }}</p>@enderror
    </div>
</div>
