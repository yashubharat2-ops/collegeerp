@extends('layouts.app')

@section('title', 'Edit Concession')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Edit Fee Concession</h2>
            <p class="panel-subtitle">Changing the type or value recalculates the amount and sends the concession back to pending — an approval applies to one specific amount.</p>
        </div>
        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('fee-concessions.index') }}">Back</a>
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

    <form method="POST" action="{{ route('fee-concessions.update', $concession) }}" class="mt-6 grid gap-4 sm:grid-cols-2">
        @csrf
        @method('PUT')
        @include('fee_concessions._form')
        <div class="sm:col-span-2 flex gap-2">
            <button class="button" type="submit">Update concession</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('fee-concessions.index') }}">Cancel</a>
        </div>
    </form>
</div>
@endsection
