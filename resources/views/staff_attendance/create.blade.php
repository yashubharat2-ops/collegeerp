@extends('layouts.app')
@section('title', 'Record Staff Attendance')
@section('content')<div class="panel max-w-3xl"><h2 class="panel-title">Record staff attendance</h2><p class="panel-subtitle">Attendance is unique per employee and date.</p><form method="POST" action="{{ route('staff-attendance.store') }}">@include('staff_attendance._form', ['attendance' => null, 'submitLabel' => 'Save attendance'])</form></div>@endsection
