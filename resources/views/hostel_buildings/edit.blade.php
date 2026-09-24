@extends('layouts.app')

@section('title', 'Edit Building / Block')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Edit Building / Block</h2>
            <p class="panel-subtitle">{{ $building->name }} ({{ $building->code }}) · {{ $building->hostel?->name }} · {{ $building->rooms_count }} {{ Str::plural('room', $building->rooms_count) }} · {{ $building->beds_count }} {{ Str::plural('bed', $building->beds_count) }}</p>
        </div>
        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('hostel-buildings.index') }}">Back</a>
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

    <form method="POST" action="{{ route('hostel-buildings.update', $building) }}" class="mt-6 grid gap-4 sm:grid-cols-2">
        @csrf
        @method('PUT')
        @include('hostel_buildings._form')
        <div class="sm:col-span-2 flex gap-2">
            <button class="button" type="submit">Update building</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('hostel-buildings.index') }}">Cancel</a>
        </div>
    </form>
</div>
@endsection
