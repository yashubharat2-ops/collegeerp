@extends('layouts.app')

@section('title', 'Add Room')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Add Room</h2>
            <p class="panel-subtitle">Create a room under an existing building / block of the active college. The room number must be unique within the building, including archived records.</p>
        </div>
        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('hostel-rooms.index') }}">Back</a>
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

    <form method="POST" action="{{ route('hostel-rooms.store') }}" class="mt-6 grid gap-4 sm:grid-cols-2">
        @csrf
        @include('hostel_rooms._form')
        <div class="sm:col-span-2 flex gap-2">
            <button class="button" type="submit">Save room</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('hostel-rooms.index') }}">Cancel</a>
        </div>
    </form>
</div>
@endsection
