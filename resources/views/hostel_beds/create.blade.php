@extends('layouts.app')

@section('title', 'Add Bed')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Add Bed</h2>
            <p class="panel-subtitle">Create a bed under an existing room of the active college. The bed number must be unique within the room, including archived records, and the room's capacity is never exceeded.</p>
        </div>
        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('hostel-beds.index') }}">Back</a>
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

    <form method="POST" action="{{ route('hostel-beds.store') }}" class="mt-6 grid gap-4 sm:grid-cols-2">
        @csrf
        @include('hostel_beds._form')
        <div class="sm:col-span-2 flex gap-2">
            <button class="button" type="submit">Save bed</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('hostel-beds.index') }}">Cancel</a>
        </div>
    </form>
</div>
@endsection
