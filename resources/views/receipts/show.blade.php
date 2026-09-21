@extends('layouts.app')

@section('title', 'Receipt')

@section('content')
<div class="no-print mb-4 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h2 class="panel-title">Receipt — {{ $payment->payment_number }}</h2>
        <p class="panel-subtitle">{{ $payment->studentEnrollment?->student?->fullName() ?? '—' }} · {{ $payment->payment_date?->format('M d, Y') ?? '—' }}</p>
    </div>
    <div class="flex gap-2">
        @can('print', $receipt)
            <a class="button" href="{{ route('receipts.print', $payment) }}">Print receipt</a>
        @endcan
        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('receipts.index') }}">Back to receipts</a>
    </div>
</div>

@include('receipts._document')

<p class="no-print mt-4 text-xs text-slate-500">
    The receipt is a printable view of the recorded payment. Use your browser's print dialog to print it or save it as a PDF — no other document is generated.
</p>
@endsection
