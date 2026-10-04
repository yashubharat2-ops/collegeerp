@extends('layouts.app')
@section('title','Edit Student')
@section('content')
<div class="panel max-w-4xl">
    <h2 class="panel-title">Edit student: {{ $student->first_name }} {{ $student->last_name }}</h2>
    <p class="panel-subtitle">
        Student number <span class="font-semibold">{{ $student->student_number }}</span> is server-generated and cannot be
        changed here. Identity numbers are shown masked only — leave a field blank to keep the stored value.
    </p>
    {{-- multipart: the Basic information section can replace or remove the portrait. --}}
    <form method="POST" action="{{ route('students.update', $student) }}" enctype="multipart/form-data">
        @method('PUT')
        @include('students._form', ['submitLabel' => 'Update student'])
    </form>
</div>
@endsection
