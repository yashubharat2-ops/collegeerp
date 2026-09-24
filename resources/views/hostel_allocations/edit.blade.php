@extends('layouts.app')

@section('title', 'Edit Hostel Allocation')

@section('content')
<div class="panel">
    <div>
        <h2 class="panel-title">Edit Hostel Allocation</h2>
        <p class="panel-subtitle">Allocation hierarchy (enrollment, year, hostel, building, room, bed) is immutable after creation — vacate and create a new allocation to reallocate. Only dates, status and remarks are editable here.</p>
    </div>

    <form class="mt-6" method="POST" action="{{ route('hostel-allocations.update', $allocation) }}">
        @csrf
        @method('PUT')
        @include('hostel_allocations._form')
        <div class="mt-6 flex gap-2">
            <button class="button" type="submit">Update allocation</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('hostel-allocations.index') }}">Cancel</a>
        </div>
    </form>
</div>
@endsection
