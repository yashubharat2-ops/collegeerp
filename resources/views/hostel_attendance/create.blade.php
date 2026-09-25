@extends('layouts.app')

@section('title', 'Mark Hostel Attendance')

@section('content')
<div class="panel">
    <div>
        <h2 class="panel-title">Mark Hostel Attendance</h2>
        <p class="panel-subtitle">Mark one current resident. The college, enrollment and audit fields are set by the server from the active allocation — they cannot be overridden.</p>
    </div>

    <form class="mt-6" method="POST" action="{{ route('hostel-attendance.store') }}">
        @include('hostel_attendance._form', ['attendance' => new App\Models\HostelAttendance()])
        <div class="mt-6 flex gap-2">
            <button class="button" type="submit">Save attendance</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('hostel-attendance.index') }}">Cancel</a>
        </div>
    </form>
</div>
@endsection
