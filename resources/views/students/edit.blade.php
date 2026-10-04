@extends('layouts.app')

@section('title', 'Edit student')

@section('content')
<div class="erp-student-page">
    <div class="erp-form-shell">
        <div class="erp-form-head">
            <h2 class="erp-form-title">Edit student: {{ $student->first_name }} {{ $student->last_name }}</h2>
            <p class="erp-form-note">Student number {{ $student->student_number }} is server-generated.</p>
        </div>
        <p class="erp-form-subtitle">
            Identity numbers are shown masked only — leave a field blank to keep the stored value, or tick the box to remove it.
        </p>
        {{-- multipart: the Basic information section can replace or remove the portrait. --}}
        <form method="POST" action="{{ route('students.update', $student) }}" enctype="multipart/form-data">
            @method('PUT')
            @include('students._form', ['submitLabel' => 'Update student'])
        </form>
    </div>
</div>
@endsection
