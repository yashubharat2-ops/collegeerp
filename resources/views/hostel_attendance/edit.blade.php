@extends('layouts.app')

@section('title', 'Correct Hostel Attendance')

@section('content')
<div class="panel">
    <div>
        <h2 class="panel-title">Correct Hostel Attendance</h2>
        <p class="panel-subtitle">Authorized correction of an existing mark. The resident cannot be changed; correct the status, date or remarks instead of creating a second row.</p>
    </div>

    <form class="mt-6" method="POST" action="{{ route('hostel-attendance.update', $attendance) }}">
        @method('PUT')
        @include('hostel_attendance._form')
        <div class="mt-6 flex gap-2">
            <button class="button" type="submit">Save correction</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('hostel-attendance.index') }}">Cancel</a>
        </div>
    </form>
</div>
@endsection
