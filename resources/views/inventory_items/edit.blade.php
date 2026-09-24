@extends('layouts.app')

@section('title', 'Edit Item / Asset')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Edit Item / Asset</h2>
            <p class="panel-subtitle">{{ $item->name }} ({{ $item->code }}) · {{ ucfirst($item->item_type) }}</p>
        </div>
        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('inventory-items.index') }}">Back</a>
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

    <form method="POST" action="{{ route('inventory-items.update', $item) }}" class="mt-6 grid gap-4 sm:grid-cols-2">
        @csrf
        @method('PUT')
        @include('inventory_items._form')
        <div class="sm:col-span-2 flex flex-wrap gap-2">
            <button class="button" type="submit">Update item</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('inventory-items.index') }}">Cancel</a>
        </div>
    </form>
</div>
@endsection
