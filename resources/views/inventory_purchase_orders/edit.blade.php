@extends('layouts.app')

@section('title', 'Edit Purchase Order')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Edit Purchase Order</h2>
            <p class="panel-subtitle">{{ $order->number }} · {{ $order->vendor?->name ?? '—' }} · {{ ucfirst(str_replace('_', ' ', $order->status)) }}</p>
        </div>
        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('inventory-purchase-orders.show', $order) }}">Back</a>
    </div>

    <div class="alert-success mt-4 !bg-amber-50 !text-amber-900">
        Only a draft can be edited. Saving replaces the whole line set, so check the received history before changing an order that has been sent out.
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

    <form method="POST" action="{{ route('inventory-purchase-orders.update', $order) }}" class="mt-6 grid gap-4 sm:grid-cols-2">
        @csrf
        @method('PUT')
        @include('inventory_purchase_orders._form')
        <div class="sm:col-span-2 flex flex-wrap gap-2">
            <button class="button" type="submit">Update order</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('inventory-purchase-orders.show', $order) }}">Cancel</a>
        </div>
    </form>
</div>
@endsection
