@csrf
<div class="mt-6 grid gap-5 md:grid-cols-2">
    @if(!isset($transfer))
        <div>
            <label class="label" for="student_id">Student *</label>
            <select class="input" id="student_id" name="student_id" required>
                <option value="">— Select student —</option>
                @foreach($students as $s)
                    <option value="{{ $s->id }}" @selected((int) old('student_id', $selectedStudentId ?? 0) === $s->id)>{{ $s->student_number }} — {{ $s->first_name }} {{ $s->last_name }} ({{ $s->status }})</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-rose-600">@error('student_id'){{ $message }}@enderror</p>
        </div>

        <div>
            <label class="label" for="enrollment_id">Enrollment being transferred out of</label>
            <select class="input" id="enrollment_id" name="enrollment_id">
                <option value="">— No specific enrollment —</option>
                @foreach($enrollments as $enrollment)
                    <option value="{{ $enrollment->id }}"
                            data-student-id="{{ $enrollment->student_id }}"
                            @selected((int) old('enrollment_id') === $enrollment->id)>
                        {{ $enrollment->enrollment_number }} — {{ $enrollment->academicYear?->name ?? '—' }}{{ $enrollment->program ? ' · '.$enrollment->program->name : '' }} ({{ $enrollment->status }})
                    </option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-rose-600">@error('enrollment_id'){{ $message }}@enderror</p>
            <p class="mt-1 text-xs text-slate-500">Issuing the TC marks this enrollment withdrawn. The row is never deleted.</p>
        </div>
    @else
        <div class="md:col-span-2">
            <p class="text-sm"><span class="font-semibold">Student:</span> {{ $transfer->student?->fullName() }} ({{ $transfer->student?->student_number }})</p>
            <p class="text-sm"><span class="font-semibold">Enrollment:</span> {{ $transfer->enrollment?->enrollment_number ?? '—' }}</p>
            <p class="text-sm"><span class="font-semibold">Request status:</span> {{ ucfirst($transfer->status) }} · <span class="font-semibold">TC:</span> {{ ucfirst(str_replace('_', ' ', $transfer->tc_status)) }}</p>
        </div>
    @endif

    <div>
        <label class="label" for="transfer_date">Transfer date *</label>
        <input class="input" type="date" id="transfer_date" name="transfer_date" required
               value="{{ old('transfer_date', isset($transfer) ? $transfer->transfer_date?->format('Y-m-d') : now()->format('Y-m-d')) }}">
        <p class="mt-1 text-xs text-rose-600">@error('transfer_date'){{ $message }}@enderror</p>
    </div>

    <div>
        <label class="label" for="destination_institution">Destination institution</label>
        <input class="input" type="text" id="destination_institution" name="destination_institution" maxlength="255"
               value="{{ old('destination_institution', $transfer->destination_institution ?? '') }}" placeholder="e.g. Govt. Model Science College">
        <p class="mt-1 text-xs text-rose-600">@error('destination_institution'){{ $message }}@enderror</p>
    </div>

    <div class="md:col-span-2">
        <label class="label" for="reason">Reason *</label>
        <textarea class="input" id="reason" name="reason" rows="3" maxlength="2000" required>{{ old('reason', $transfer->reason ?? '') }}</textarea>
        <p class="mt-1 text-xs text-rose-600">@error('reason'){{ $message }}@enderror</p>
    </div>

    <div class="md:col-span-2">
        <label class="label" for="remarks">Remarks</label>
        <textarea class="input" id="remarks" name="remarks" rows="2" maxlength="2000">{{ old('remarks', $transfer->remarks ?? '') }}</textarea>
        <p class="mt-1 text-xs text-rose-600">@error('remarks'){{ $message }}@enderror</p>
    </div>

    <div class="md:col-span-2 flex gap-2">
        <button class="button" type="submit">{{ $submitLabel }}</button>
        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('student-transfers.index') }}">Cancel</a>
    </div>
</div>

@push('scripts')
<script>
    // Show only the selected student's enrollments. The server re-validates the
    // enrollment belongs to the student in this college.
    (function () {
        const student = document.getElementById('student_id');
        const enrollment = document.getElementById('enrollment_id');
        if (!student || !enrollment) return;

        function apply() {
            const studentId = student.value;
            Array.from(enrollment.options).forEach(function (option, index) {
                if (index === 0) return;
                const visible = !studentId || option.dataset.studentId === studentId;
                option.hidden = !visible;
                option.disabled = !visible;
            });
            if (enrollment.selectedOptions[0] && enrollment.selectedOptions[0].hidden) {
                enrollment.value = '';
            }
        }

        student.addEventListener('change', apply);
        apply();
    })();
</script>
@endpush
