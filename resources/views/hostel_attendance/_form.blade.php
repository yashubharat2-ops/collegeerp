@csrf
<div class="grid gap-4 sm:grid-cols-2">
    @if($attendance->exists)
        <div class="sm:col-span-2 rounded-xl bg-slate-50 p-4 text-sm">
            <p class="font-medium text-slate-800">{{ $attendance->studentEnrollment?->student?->first_name }} {{ $attendance->studentEnrollment?->student?->last_name }}</p>
            <p class="mt-1 text-slate-500">{{ $attendance->studentEnrollment?->enrollment_number }} · {{ $attendance->allocation?->hostel?->name ?? '—' }} · Room {{ $attendance->allocation?->room?->room_number ?? '—' }} · Bed {{ $attendance->allocation?->bed?->bed_number ?? '—' }}</p>
        </div>
    @else
        <div class="sm:col-span-2">
            <label class="label" for="hostel_allocation_id">Current resident *</label>
            <select class="input" id="hostel_allocation_id" name="hostel_allocation_id" required>
                <option value="">Select a current hostel resident</option>
                @foreach($allocations as $allocation)
                    <option value="{{ $allocation->id }}" @selected((string) old('hostel_allocation_id') === (string) $allocation->id)>
                        {{ $allocation->studentEnrollment?->student?->first_name }} {{ $allocation->studentEnrollment?->student?->last_name }}
                        — {{ $allocation->studentEnrollment?->enrollment_number }}
                        — {{ $allocation->hostel?->name }} / {{ $allocation->room?->room_number }} / {{ $allocation->bed?->bed_number }}
                    </option>
                @endforeach
            </select>
            @error('hostel_allocation_id')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
            @error('student_enrollment_id')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
        </div>
    @endif

    <div>
        <label class="label" for="attendance_date">Date *</label>
        <input class="input" id="attendance_date" name="attendance_date" type="date" required value="{{ old('attendance_date', $attendance->attendance_date?->format('Y-m-d') ?? now()->toDateString()) }}">
        @error('attendance_date')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
    </div>
    <div>
        <label class="label" for="attendance_status">Status *</label>
        <select class="input" id="attendance_status" name="attendance_status" required>
            @foreach($statuses as $status)
                <option value="{{ $status }}" @selected(old('attendance_status', $attendance->attendance_status ?? 'present') === $status)>{{ ucfirst($status) }}</option>
            @endforeach
        </select>
        @error('attendance_status')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
    </div>
    <div class="sm:col-span-2">
        <label class="label" for="remarks">Remarks</label>
        <textarea class="input" id="remarks" name="remarks" rows="3" maxlength="2000">{{ old('remarks', $attendance->remarks) }}</textarea>
        @error('remarks')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
    </div>
</div>
