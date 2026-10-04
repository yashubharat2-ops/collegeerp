@extends('layouts.app')

@section('title', 'New Student')

@section('content')
<div class="erp-student-page">
    {{-- Page header: clearly separated from the section cards below. --}}
    <header class="erp-page-header">
        <div>
            <h2 class="erp-page-title">New Student</h2>
            <p class="erp-page-sub">Create a new student record</p>
        </div>
        <p class="erp-page-note">Fields marked <span class="erp-req">*</span> are required.</p>
    </header>

    {{-- multipart: the Basic information section uploads the portrait. --}}
    <form method="POST" action="{{ route('students.store') }}" enctype="multipart/form-data">
        @include('students._form', ['submitLabel' => 'Create Student'])
    </form>
</div>
@endsection
