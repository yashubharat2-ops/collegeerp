@extends('layouts.app')

@section('title', 'Add Vendor')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Add Vendor</h2>
            <p class="panel-subtitle">Record a supplier for this college. The code must be unique among the college's active vendors. Purchase orders are a later phase.</p>
        </div>
        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('inventory-vendors.index') }}">Back</a>
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

    <form method="POST" action="{{ route('inventory-vendors.store') }}" class="mt-6 grid gap-4 sm:grid-cols-2">
        @csrf
        @include('inventory_vendors._form')
        <div class="sm:col-span-2 flex flex-wrap gap-2">
            <button class="button" type="submit">Save vendor</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('inventory-vendors.index') }}">Cancel</a>
        </div>
    </form>
</div>
@endsection
