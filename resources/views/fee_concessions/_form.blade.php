{{--
    Fee concession form (create + edit).

    Only `type` and `value` are accepted as the concession input: the money amount
    is computed on the server from the assignment, and approval is a separate
    action. The applicable fee and the remaining concessionable amount are echoed
    for the user's benefit — the server re-checks them.

    XSS safety: every value is echoed through Blade's {{ }} escaping.
--}}
@if(isset($concession))
    <div class="sm:col-span-2 rounded-2xl border border-slate-200 bg-slate-50 p-4">
        <p class="text-sm font-semibold text-slate-900">{{ $concession->studentFeeAssignment?->studentEnrollment?->student?->fullName() ?? '—' }}</p>
        <p class="mt-1 text-xs text-slate-600">
            {{ $concession->studentFeeAssignment?->studentEnrollment?->academicYear?->name ?? '—' }} ·
            {{ $concession->studentFeeAssignment?->studentEnrollment?->program?->name ?? '—' }} ·
            {{ $concession->studentFeeAssignment?->feeStructure?->name ?? '—' }}
        </p>
        <p class="mt-2 text-sm text-slate-700">Assigned fee: <span class="font-semibold">{{ number_format((float) $concession->studentFeeAssignment?->assigned_amount, 2) }}</span></p>
        <p class="mt-1 text-xs text-slate-500">Current concession on this assignment: {{ number_format((float) $concession->amount, 2) }} ({{ ucfirst($concession->status) }})</p>
    </div>
@elseif(isset($assignment) && $assignment)
    <div class="sm:col-span-2 rounded-2xl border border-slate-200 bg-slate-50 p-4">
        <p class="text-sm font-semibold text-slate-900">
            {{ $assignment->studentEnrollment?->student?->fullName() ?? '—' }} · {{ $assignment->studentEnrollment?->enrollment_number ?? '—' }}
        </p>
        <p class="mt-1 text-xs text-slate-600">
            {{ $assignment->studentEnrollment?->academicYear?->name ?? '—' }} ·
            {{ $assignment->studentEnrollment?->program?->name ?? '—' }} ·
            {{ $assignment->feeStructure?->name ?? '—' }}
        </p>
        <p class="mt-2 text-sm text-slate-700">Assigned fee: <span class="font-semibold">{{ number_format((float) $assignment->assigned_amount, 2) }}</span></p>
        <input type="hidden" name="student_fee_assignment_id" value="{{ $assignment->id }}">
        <p class="mt-2 text-xs text-slate-500">A percentage is applied to this amount on the server; the concession can never exceed the applicable fee.</p>
    </div>
@endif

<div>
    <label class="label" for="type">Type</label>
    <select class="input" id="type" name="type" required>
        @foreach($concessionTypes as $type)
            <option value="{{ $type }}" @selected(old('type', $concession->type ?? 'fixed') === $type)>{{ ucfirst($type) }}</option>
        @endforeach
    </select>
    <p class="mt-1 text-xs text-slate-500">Fixed = a money amount; Percentage = 0–100% of the assigned fee.</p>
    <p class="mt-1 text-xs text-rose-600">@error('type'){{ $message }}@enderror</p>
</div>
<div>
    <label class="label" for="value">Value</label>
    <input class="input" id="value" name="value" type="number" step="0.01" min="0" required value="{{ old('value', $concession->value ?? '') }}">
    <p class="mt-1 text-xs text-rose-600">@error('value'){{ $message }}@enderror</p>
</div>
<div>
    <label class="label" for="status">Status</label>
    <select class="input" id="status" name="status">
        @foreach(['pending', 'rejected', 'cancelled'] as $statusOption)
            <option value="{{ $statusOption }}" @selected(old('status', $concession->status ?? 'pending') === $statusOption)>{{ ucfirst($statusOption) }}</option>
        @endforeach
    </select>
    <p class="mt-1 text-xs text-slate-500">Approval is granted from the concession list by a user holding the approve permission.</p>
    <p class="mt-1 text-xs text-rose-600">@error('status'){{ $message }}@enderror</p>
</div>
<div class="sm:col-span-2">
    <label class="label" for="reason">Reason</label>
    <textarea class="input" id="reason" name="reason" rows="2" maxlength="2000">{{ old('reason', $concession->reason ?? '') }}</textarea>
    <p class="mt-1 text-xs text-rose-600">@error('reason'){{ $message }}@enderror</p>
</div>
