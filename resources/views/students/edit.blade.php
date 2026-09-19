@extends('layouts.app')
@section('title','Edit Student')
@section('content')
<div class="panel max-w-4xl">
    <h2 class="panel-title">Edit student: {{ $student->first_name }} {{ $student->last_name }}</h2>
    <p class="panel-subtitle">Student number <span class="font-semibold">{{ $student->student_number }}</span> is server-generated and cannot be changed here.</p>
    <form method="POST" action="{{ route('students.update', $student) }}">
        @method('PUT')
        @include('students._form', ['submitLabel' => 'Update student'])
    </form>
</div>
@endsection
