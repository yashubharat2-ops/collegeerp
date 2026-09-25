@extends('layouts.app')

@section('title', 'New Purchase Order')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">New Purchase Order</h2>
            <p class="panel-subtitle">Raise an order for the active college. It is saved as a draft — nothing reaches the vendor, and no stock moves, until it is submitted.</p>
        </div>
        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('inventory-purchase-orders.index') }}">Back</a>
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

    <form method="POST" action="{{ route('inventory-purchase-orders.store') }}" class="mt-6 grid gap-4 sm:grid-cols-2">
        @csrf
        @include('inventory_purchase_orders._form')
        <div class="sm:col-span-2 flex flex-wrap gap-2">
            <button class="button" type="submit">Save draft</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('inventory-purchase-orders.index') }}">Cancel</a>
        </div>
    </form>
</div>
@endsection
