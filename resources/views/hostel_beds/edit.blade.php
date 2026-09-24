@extends('layouts.app')

@section('title', 'Edit Bed')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Edit Bed</h2>
            <p class="panel-subtitle">Bed {{ $bed->bed_number }} · Room {{ $bed->room?->room_number }} · {{ $bed->building?->name }} ({{ $bed->hostel?->name }}) · {{ ucfirst($bed->status) }}</p>
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

    <form method="POST" action="{{ route('hostel-beds.update', $bed) }}" class="mt-6 grid gap-4 sm:grid-cols-2">
        @csrf
        @method('PUT')
        @include('hostel_beds._form')
        <div class="sm:col-span-2 flex gap-2">
            <button class="button" type="submit">Update bed</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('hostel-beds.index') }}">Cancel</a>
        </div>
    </form>
</div>
@endsection
