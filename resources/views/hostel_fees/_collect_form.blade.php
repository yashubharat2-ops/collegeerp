{{--
    Hostel fee collection — records through the EXISTING Finance payment
    rows (FeeCollectionService::collectHostelFee). The payment number, the
    outstanding cap and the collector are all server-controlled.
--}}
@php($token = (string) \Illuminate\Support\Str::uuid())
<form method="POST" action="{{ route('hostel-fees.collect', $feeAssignment->id) }}" class="grid gap-3 rounded-lg border border-slate-200 bg-slate-50 p-4 sm:grid-cols-2">
    @csrf
    <input type="hidden" name="submission_token" value="{{ $token }}">
    <p class="text-sm font-semibold sm:col-span-2">Record payment (existing Finance receipt flow)</p>
    <p class="text-xs text-slate-500 sm:col-span-2">Outstanding <span class="font-semibold">{{ number_format((float) ($ledger['outstanding'] ?? 0), 2) }}</span> — the balance is re-checked on the server under a row lock before the payment is stored.</p>
    <label class="block text-sm font-medium text-slate-700">
        Amount *
        <input class="input mt-1" type="number" name="amount" step="0.01" min="0.01" max="{{ number_format((float) ($ledger['outstanding'] ?? 0), 2, '.', '') }}" required value="{{ old('amount', number_format((float) ($ledger['outstanding'] ?? 0), 2, '.', '')) }}">
        @error('amount')<span class="text-red-600">{{ $message }}</span>@enderror
    </label>
    <label class="block text-sm font-medium text-slate-700">
        Payment date *
        <input class="input mt-1" type="date" name="payment_date" required value="{{ old('payment_date', now()->format('Y-m-d')) }}">
        @error('payment_date')<span class="text-red-600">{{ $message }}</span>@enderror
    </label>
    <label class="block text-sm font-medium text-slate-700">
        Payment mode *
        <select class="input mt-1" name="payment_mode" required>
            @foreach($paymentModes as $mode)
                <option value="{{ $mode }}" @selected(old('payment_mode') === $mode)>{{ ucfirst(str_replace('_', ' ', $mode)) }}</option>
            @endforeach
        </select>
        @error('payment_mode')<span class="text-red-600">{{ $message }}</span>@enderror
    </label>
    <label class="block text-sm font-medium text-slate-700">
        Reference number
        <input class="input mt-1" type="text" name="reference_number" maxlength="100" value="{{ old('reference_number') }}" placeholder="Cheque / UTR / gateway reference">
        @error('reference_number')<span class="text-red-600">{{ $message }}</span>@enderror
    </label>
    <label class="block text-sm font-medium text-slate-700 sm:col-span-2">
        Remarks
        <textarea class="input mt-1" name="remarks" maxlength="2000" rows="2">{{ old('remarks') }}</textarea>
        @error('remarks')<span class="text-red-600">{{ $message }}</span>@enderror
    </label>
    <div class="sm:col-span-2">
        <button class="button" type="submit">Record payment</button>
    </div>
</form>
