@extends('layouts.app')
@section('title', 'Correct Staff Attendance')
@section('content')<div class="panel max-w-3xl"><h2 class="panel-title">Correct staff attendance</h2><p class="panel-subtitle">Changes are audited.</p><form method="POST" action="{{ route('staff-attendance.update', $attendance) }}">@method('PUT')@include('staff_attendance._form', ['submitLabel' => 'Save correction'])</form></div>@endsection
