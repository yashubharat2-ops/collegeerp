@extends('layouts.app')
@section('title','New Enrollment')
@section('content')
<div class="panel max-w-4xl">
    <h2 class="panel-title">New enrollment</h2>
    <p class="panel-subtitle">Enroll a student into an academic year (and optional program). The enrollment number is generated server-side per college.</p>
    <form method="POST" action="{{ route('student-enrollments.store') }}">
        @include('student_enrollments._form', ['submitLabel' => 'Create enrollment'])
    </form>
</div>
@endsection
