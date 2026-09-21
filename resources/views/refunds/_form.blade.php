{{--
    Refund form (create + edit).

    A refund always hangs off an existing collection: the payment is selected
    first, and the refundable amount (payment amount minus its valid refunds) is
    shown. The refund number and the approval/processing metadata are
    server-controlled.

    XSS safety: every value is echoed through Blade's {{ }} escaping.
--}}
@if(isset($refund))
    <div class="sm:col-span-2 rounded-2xl border border-slate-200 bg-slate-50 p-4">
        <p class="text-sm font-semibold text-slate-900">Refund {{ $refund->refund_number }} · payment {{ $refund->payment?->payment_number ?? '—' }}</p>
        <p class="mt-1 text-xs text-slate-600">
            {{ $refund->payment?->studentEnrollment?->student?->fullName() ?? '—' }} ·
            payment amount {{ number_format((float) $refund->payment?->amount, 2) }} ·
            {{ ucfirst($refund->status) }}
        </p>
        <p class="mt-2 text-xs text-slate-500">The refund can never exceed the payment's refundable amount, which is re-checked on the server.</p>
    </div>
@elseif(isset($payment) && $payment)
    <div class="sm:col-span-2 rounded-2xl border border-slate-200 bg-slate-50 p-4">
        <p class="text-sm font-semibold text-slate-900">Payment {{ $payment->payment_number }}</p>
        <p class="mt-1 text-xs text-slate-600">
            {{ $payment->studentEnrollment?->student?->fullName() ?? '—' }} ·
            {{ $payment->studentEnrollment?->academicYear?->name ?? '—' }} ·
            {{ $payment->feeStructure?->name ?? '—' }}
        </p>
        <div class="mt-3 grid gap-2 text-sm text-slate-700 sm:grid-cols-3">
            <p>Payment amount<br><span class="font-semibold">{{ number_format((float) $payment->amount, 2) }}</span></p>
            <p>Already refunded<br><span class="font-semibold">{{ number_format((float) $payment->validRefunds()->sum('amount'), 2) }}</span></p>
            <p>Refundable<br><span class="font-semibold">{{ number_format($payment->refundableAmount(), 2) }}</span></p>
        </div>
        <input type="hidden" name="fee_payment_id" value="{{ $payment->id }}">
    </div>
@endif

<div>
    <label class="label" for="amount">Refund Amount</label>
    <input class="input" id="amount" name="amount" type="number" step="0.01" min="0.01" required value="{{ old('amount', isset($refund) ? $refund->amount : '') }}">
    <p class="mt-1 text-xs text-rose-600">@error('amount'){{ $message }}@enderror</p>
</div>
<div>
    <label class="label" for="refund_date">Refund Date</label>
    <input class="input" id="refund_date" name="refund_date" type="date" required
        value="{{ old('refund_date', isset($refund) && $refund->refund_date ? $refund->refund_date->format('Y-m-d') : now()->format('Y-m-d')) }}">
    <p class="mt-1 text-xs text-rose-600">@error('refund_date'){{ $message }}@enderror</p>
</div>
@if(isset($refund))
    <div>
        <label class="label" for="status">Status</label>
        <select class="input" id="status" name="status">
            @foreach(['pending', 'rejected', 'cancelled'] as $statusOption)
                <option value="{{ $statusOption }}" @selected(old('status', $refund->status) === $statusOption)>{{ ucfirst($statusOption) }}</option>
            @endforeach
        </select>
        <p class="mt-1 text-xs text-slate-500">Approval and processing happen from the refund list. Changing the amount sends the refund back to pending.</p>
        <p class="mt-1 text-xs text-rose-600">@error('status'){{ $message }}@enderror</p>
    </div>
@endif
<div class="sm:col-span-2">
    <label class="label" for="reason">Reason</label>
    <textarea class="input" id="reason" name="reason" rows="2" maxlength="2000">{{ old('reason', $refund->reason ?? '') }}</textarea>
    <p class="mt-1 text-xs text-rose-600">@error('reason'){{ $message }}@enderror</p>
</div>
