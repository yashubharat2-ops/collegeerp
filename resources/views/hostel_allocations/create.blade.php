@extends('layouts.app')

@section('title', 'Allocate Hostel Bed')

@section('content')
<div class="panel">
    <div>
        <h2 class="panel-title">Allocate Hostel Bed</h2>
        <p class="panel-subtitle">Allocate an existing student enrollment to an existing hostel bed. All tenant identity comes from the active college context; cross-tenant combinations are rejected server-side. Bed availability is checked under a row lock.</p>
    </div>

    <form class="mt-6" method="POST" action="{{ route('hostel-allocations.store') }}">
        @csrf
        @include('hostel_allocations._form', ['allocation' => new App\Models\HostelAllocation()])
        <div class="mt-6 flex gap-2">
            <button class="button" type="submit">Allocate</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('hostel-allocations.index') }}">Cancel</a>
        </div>
    </form>
</div>
@endsection
