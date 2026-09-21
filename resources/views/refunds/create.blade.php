@extends('layouts.app')

@section('title', 'Add Refund')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Add Refund</h2>
            <p class="panel-subtitle">Refund part or all of a recorded collection. The refund number is generated server-side.</p>
        </div>
        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('refunds.index') }}">Back</a>
    </div>

    @if($errors->any())
        <div class="alert-error mt-4">
            <ul class="list-inside list-disc space-y-1">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if(! isset($payment) || ! $payment)
        <form class="mt-6 grid gap-3 rounded-2xl border border-dashed border-slate-300 p-4 sm:grid-cols-[1fr_auto]" method="GET" action="{{ route('refunds.create') }}">
            <div>
                <label class="label" for="fee_payment_id">Payment</label>
                <select class="input" id="fee_payment_id" name="fee_payment_id" required>
                    <option value="">Select a completed payment</option>
                    @foreach($payments ?? [] as $option)
                        <option value="{{ $option->id }}" @selected((int) ($selectedPaymentId ?? 0) === $option->id)>
                            {{ $option->payment_number }} · {{ $option->studentEnrollment?->student?->fullName() ?? '—' }} · {{ number_format((float) $option->amount, 2) }}
                        </option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-slate-500">Cancelled payments are never listed: money that was reversed cannot be refunded.</p>
            </div>
            <div class="flex items-end">
                <button class="button !bg-slate-200 !text-slate-700" type="submit">Load payment</button>
            </div>
        </form>
    @endif

    <form method="POST" action="{{ route('refunds.store') }}" class="mt-6 grid gap-4 sm:grid-cols-2">
        @csrf
        @include('refunds._form')
        <div class="sm:col-span-2 flex gap-2">
            <button class="button" type="submit" @disabled(! isset($payment) || ! $payment)>Save refund</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('refunds.index') }}">Cancel</a>
        </div>
    </form>
</div>
@endsection
