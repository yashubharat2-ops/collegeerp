{{--
    Library member form. On edit the enrollment is frozen — membership is not
    a way to reassign a student. XSS safety: Blade {{ }} escaping throughout.
--}}
@if(isset($member))
    <div class="sm:col-span-2 rounded-2xl border border-slate-200 bg-slate-50 p-4">
        <p class="text-sm font-semibold text-slate-900">{{ $member->studentName() }}</p>
        <p class="mt-1 text-xs text-slate-600">
            {{ $member->studentEnrollment?->enrollment_number ?? '—' }}
            · {{ $member->studentEnrollment?->academicYear?->name ?? '—' }}
            · student {{ $member->studentEnrollment?->student?->student_number ?? '—' }}
        </p>
        <p class="mt-2 text-xs text-slate-500">The enrollment cannot be changed. Create a new membership if a different enrollment should be linked.</p>
    </div>
@else
    <div class="sm:col-span-2">
        <label class="label" for="student_enrollment_id">Student enrollment</label>
        <select class="input" id="student_enrollment_id" name="student_enrollment_id" required>
            <option value="">Select enrollment</option>
            @foreach($enrollments as $enrollment)
                <option value="{{ $enrollment->id }}" @selected((int) old('student_enrollment_id', $selectedEnrollmentId ?? 0) === $enrollment->id)>
                    {{ $enrollment->student?->fullName() ?? $enrollment->student?->student_number ?? 'Student' }}
                    · {{ $enrollment->enrollment_number }}
                    · {{ $enrollment->academicYear?->name ?? 'Year' }}
                    ({{ ucfirst($enrollment->status) }})
                </option>
            @endforeach
        </select>
        <p class="mt-1 text-xs text-slate-500">Membership references an existing enrollment. Student name and number are not copied onto the member.</p>
        <p class="mt-1 text-xs text-rose-600">@error('student_enrollment_id'){{ $message }}@enderror</p>
    </div>
@endif
<div>
    <label class="label" for="member_code">Member code</label>
    <input class="input" id="member_code" name="member_code" type="text" value="{{ old('member_code', $member->member_code ?? '') }}" required maxlength="50" placeholder="e.g. LM-0001">
    <p class="mt-1 text-xs text-slate-500">Unique among this college's active memberships. Stored upper-cased.</p>
    <p class="mt-1 text-xs text-rose-600">@error('member_code'){{ $message }}@enderror</p>
</div>
<div>
    <label class="label" for="status">Status</label>
    <select class="input" id="status" name="status" required>
        @foreach($statuses as $status)
            <option value="{{ $status }}" @selected(old('status', $member->status ?? 'active') === $status)>{{ ucfirst($status) }}</option>
        @endforeach
    </select>
    <p class="mt-1 text-xs text-slate-500">Only one active membership is allowed per enrollment.</p>
    <p class="mt-1 text-xs text-rose-600">@error('status'){{ $message }}@enderror</p>
</div>
<div>
    <label class="label" for="membership_date">Membership date</label>
    <input class="input" id="membership_date" name="membership_date" type="date" required value="{{ old('membership_date', isset($member) && $member->membership_date ? $member->membership_date->format('Y-m-d') : now()->format('Y-m-d')) }}">
    <p class="mt-1 text-xs text-rose-600">@error('membership_date'){{ $message }}@enderror</p>
</div>
<div>
    <label class="label" for="expiry_date">Expiry date</label>
    <input class="input" id="expiry_date" name="expiry_date" type="date" value="{{ old('expiry_date', isset($member) && $member->expiry_date ? $member->expiry_date->format('Y-m-d') : '') }}">
    <p class="mt-1 text-xs text-slate-500">Optional. A past expiry blocks new issues even if the status is still active.</p>
    <p class="mt-1 text-xs text-rose-600">@error('expiry_date'){{ $message }}@enderror</p>
</div>
<div class="sm:col-span-2">
    <label class="label" for="remarks">Remarks</label>
    <textarea class="input" id="remarks" name="remarks" rows="2" maxlength="2000">{{ old('remarks', $member->remarks ?? '') }}</textarea>
    <p class="mt-1 text-xs text-rose-600">@error('remarks'){{ $message }}@enderror</p>
</div>
