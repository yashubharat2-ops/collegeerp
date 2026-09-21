@extends('layouts.app')

@section('title', 'Print Receipt')

@section('content')
<div class="no-print mb-4 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h2 class="panel-title">Print Receipt — {{ $payment->payment_number }}</h2>
        <p class="panel-subtitle">Use the print dialog to print this receipt or save it as a PDF. Navigation and buttons are excluded from the printed page.</p>
    </div>
    <div class="flex gap-2">
        <button class="button" type="button" onclick="window.print()">Print now</button>
        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('receipts.show', $payment) }}">Back to receipt</a>
    </div>
</div>

@include('receipts._document')
@endsection
