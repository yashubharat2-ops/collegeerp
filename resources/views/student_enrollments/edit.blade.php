@extends('layouts.app')
@section('title','Edit Enrollment')
@section('content')
<div class="panel max-w-4xl">
    <h2 class="panel-title">Edit enrollment: {{ $enrollment->enrollment_number }}</h2>
    <p class="panel-subtitle">The student/academic-year/program triple is immutable after creation; the enrollment number is server-generated.</p>
    <form method="POST" action="{{ route('student-enrollments.update', $enrollment) }}">
        @method('PUT')
        @include('student_enrollments._form', ['submitLabel' => 'Update enrollment'])
    </form>
</div>
@endsection
