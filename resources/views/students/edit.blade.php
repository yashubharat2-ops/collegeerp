@extends('layouts.app')

@section('title', 'Edit Student')

@section('content')
<div class="erp-student-page">
    {{-- Page header: identical structure to the create page. --}}
    <header class="erp-page-header">
        <div>
            <h2 class="erp-page-title">Edit Student</h2>
            <p class="erp-page-sub">Update this student record — {{ $student->fullName() }} ({{ $student->student_number }})</p>
        </div>
        <p class="erp-page-note">Identity numbers are masked; leave a field blank to keep the stored value.</p>
    </header>

    {{-- multipart: the Basic information section can replace or remove the portrait. --}}
    <form method="POST" action="{{ route('students.update', $student) }}" enctype="multipart/form-data">
        @method('PUT')
        @include('students._form', ['submitLabel' => 'Update Student'])
    </form>
</div>
@endsection
