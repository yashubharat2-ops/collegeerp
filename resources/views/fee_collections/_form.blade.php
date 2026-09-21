{{--
    Fee collection form (create + edit).

    On create, the assignment is chosen first (usually reached from the
    assignment list or the dues screen with ?student_fee_assignment_id=…), and the
    live ledger is echoed back so the collector sees the outstanding balance. The
    amount, the payment number and collected_by are all server-controlled.

    XSS safety: every value is echoed through Blade's {{ }} escaping.
--}}
@if(isset($payment))
    <div class="sm:col-span-2 rounded-2xl border border-slate-200 bg-slate-50 p-4">
        <p class="text-sm font-semibold text-slate-900">Payment {{ $payment->payment_number }}</p>
        <p class="mt-1 text-xs text-slate-600">
            {{ $payment->studentEnrollment?->student?->fullName() ?? '—' }} ·
            amount {{ number_format((float) $payment->amount, 2) }} ·
            {{ ucfirst($payment->status) }}
        </p>
        <p class="mt-2 text-xs text-slate-500">The amount and the payment number are immutable. Cancel the payment and record it again to correct an amount.</p>
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
        <div class="mt-3 grid gap-2 text-sm text-slate-700 sm:grid-cols-4">
            <p>Assigned<br><span class="font-semibold">{{ number_format((float) $assignment->assigned_amount, 2) }}</span></p>
            <p>Concession<br><span class="font-semibold">{{ number_format((float) ($ledger['concession'] ?? 0), 2) }}</span></p>
            <p>Collected<br><span class="font-semibold">{{ number_format((float) ($ledger['net_collected'] ?? 0), 2) }}</span></p>
            <p>Outstanding<br><span class="font-semibold">{{ number_format((float) ($ledger['outstanding'] ?? 0), 2) }}</span></p>
        </div>
        <input type="hidden" name="student_fee_assignment_id" value="{{ $assignment->id }}">
        <p class="mt-2 text-xs text-slate-500">The outstanding balance is recalculated on the server when the collection is saved.</p>
    </div>
@else
    <div class="sm:col-span-2 rounded-2xl border border-dashed border-slate-300 p-4">
        <p class="text-sm text-slate-600">Choose the fee assignment to collect against.</p>
    </div>
@endif

@if(! isset($payment))
    <div>
        <label class="label" for="amount">Amount</label>
        <input class="input" id="amount" name="amount" type="number" step="0.01" min="0.01" required value="{{ old('amount') }}">
        <p class="mt-1 text-xs text-rose-600">@error('amount'){{ $message }}@enderror</p>
    </div>
@endif

<div>
    <label class="label" for="payment_date">Payment Date</label>
    <input class="input" id="payment_date" name="payment_date" type="date" required
        value="{{ old('payment_date', isset($payment) && $payment->payment_date ? $payment->payment_date->format('Y-m-d') : now()->format('Y-m-d')) }}">
    <p class="mt-1 text-xs text-rose-600">@error('payment_date'){{ $message }}@enderror</p>
</div>
<div>
    <label class="label" for="payment_mode">Payment Mode</label>
    <select class="input" id="payment_mode" name="payment_mode" required>
        @foreach($paymentModes as $mode)
            <option value="{{ $mode }}" @selected(old('payment_mode', $payment->payment_mode ?? 'cash') === $mode)>{{ ucfirst(str_replace('_', ' ', $mode)) }}</option>
        @endforeach
    </select>
    <p class="mt-1 text-xs text-rose-600">@error('payment_mode'){{ $message }}@enderror</p>
</div>
<div>
    <label class="label" for="reference_number">Reference Number (optional)</label>
    <input class="input" id="reference_number" name="reference_number" type="text" maxlength="100" value="{{ old('reference_number', $payment->reference_number ?? '') }}" placeholder="Cheque / UTR / transaction reference">
    <p class="mt-1 text-xs text-slate-500">For cheque, bank transfer, online and UPI collections this is the bank-side reference used to detect duplicate submissions.</p>
    <p class="mt-1 text-xs text-rose-600">@error('reference_number'){{ $message }}@enderror</p>
</div>
<div class="sm:col-span-2">
    <label class="label" for="remarks">Remarks</label>
    <textarea class="input" id="remarks" name="remarks" rows="2" maxlength="2000">{{ old('remarks', $payment->remarks ?? '') }}</textarea>
    <p class="mt-1 text-xs text-rose-600">@error('remarks'){{ $message }}@enderror</p>
</div>
