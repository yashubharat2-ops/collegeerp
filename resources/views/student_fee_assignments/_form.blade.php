{{--
    Student fee assignment form (create + edit).

    On create, the enrollment and fee structure are chosen and the assigned
    amount is computed server-side. On edit only the assignment's bookkeeping is
    editable: the plan and its amount are frozen.

    XSS safety: every value is echoed through Blade's {{ }} escaping.
--}}
@if(isset($assignment))
    <div class="sm:col-span-2 rounded-2xl border border-slate-200 bg-slate-50 p-4">
        <p class="text-sm font-semibold text-slate-900">{{ $assignment->studentEnrollment?->student?->fullName() ?? '—' }} · {{ $assignment->studentEnrollment?->enrollment_number ?? '—' }}</p>
        <p class="mt-1 text-xs text-slate-600">
            {{ $assignment->studentEnrollment?->academicYear?->name ?? '—' }} ·
            {{ $assignment->studentEnrollment?->program?->name ?? '—' }} ·
            {{ $assignment->feeStructure?->name ?? '—' }}
        </p>
        <p class="mt-2 text-sm text-slate-700">Assigned amount: <span class="font-semibold">{{ number_format((float) $assignment->assigned_amount, 2) }}</span> (frozen at assignment time)</p>
        <p class="mt-1 text-xs text-slate-500">
            Concession {{ number_format((float) ($ledger['concession'] ?? 0), 2) }} ·
            Collected {{ number_format((float) ($ledger['net_collected'] ?? 0), 2) }} ·
            Outstanding {{ number_format((float) ($ledger['outstanding'] ?? 0), 2) }}
        </p>
    </div>
@else
    <div>
        <label class="label" for="student_enrollment_id">Student Enrollment</label>
        <select class="input" id="student_enrollment_id" name="student_enrollment_id" required>
            <option value="">Select enrollment</option>
            @foreach($enrollments as $enrollment)
                <option value="{{ $enrollment->id }}"
                    data-year="{{ $enrollment->academic_year_id }}"
                    data-program="{{ $enrollment->program_id }}"
                    @selected((int) old('student_enrollment_id', $selectedEnrollmentId ?? 0) === $enrollment->id)>
                    {{ $enrollment->student?->fullName() ?? $enrollment->student?->student_number ?? 'Enrollment' }} · {{ $enrollment->enrollment_number }} ({{ ucfirst($enrollment->status) }})
                </option>
            @endforeach
        </select>
        <p class="mt-1 text-xs text-slate-500">Only active or completed enrollments of this college can be charged.</p>
        <p class="mt-1 text-xs text-rose-600">@error('student_enrollment_id'){{ $message }}@enderror</p>
    </div>

    <div>
        <label class="label" for="fee_structure_id">Fee Structure</label>
        <select class="input" id="fee_structure_id" name="fee_structure_id" required>
            <option value="">Select fee structure</option>
            @foreach($feeStructures as $structure)
                <option value="{{ $structure->id }}"
                    data-year="{{ $structure->academic_year_id }}"
                    data-program="{{ $structure->program_id }}"
                    @selected((int) old('fee_structure_id', $selectedStructureId ?? 0) === $structure->id)>
                    {{ $structure->name }} ({{ $structure->code }}) · {{ ucfirst($structure->status) }}
                </option>
            @endforeach
        </select>
        <p class="mt-1 text-xs text-slate-500">The structure must belong to the enrollment's academic year and program.</p>
        <p class="mt-1 text-xs text-rose-600">@error('fee_structure_id'){{ $message }}@enderror</p>
    </div>
@endif

<div>
    <label class="label" for="assigned_at">Assignment Date</label>
    <input class="input" id="assigned_at" name="assigned_at" type="date" required
        value="{{ old('assigned_at', isset($assignment) && $assignment->assigned_at ? $assignment->assigned_at->format('Y-m-d') : now()->format('Y-m-d')) }}">
    <p class="mt-1 text-xs text-rose-600">@error('assigned_at'){{ $message }}@enderror</p>
</div>
<div>
    <label class="label" for="status">Status</label>
    <select class="input" id="status" name="status" required>
        @foreach($assignmentStatuses as $status)
            <option value="{{ $status }}" @selected(old('status', $assignment->status ?? 'active') === $status)>{{ ucfirst($status) }}</option>
        @endforeach
    </select>
    <p class="mt-1 text-xs text-slate-500">Cancelled assignments carry no payable balance.</p>
    <p class="mt-1 text-xs text-rose-600">@error('status'){{ $message }}@enderror</p>
</div>
<div class="sm:col-span-2">
    <label class="label" for="remarks">Remarks</label>
    <textarea class="input" id="remarks" name="remarks" rows="2" maxlength="2000">{{ old('remarks', $assignment->remarks ?? '') }}</textarea>
    <p class="mt-1 text-xs text-rose-600">@error('remarks'){{ $message }}@enderror</p>
</div>
