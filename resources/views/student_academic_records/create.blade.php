@extends('layouts.app')
@section('title','New Academic Record')
@section('content')
<div class="panel max-w-4xl">
    <h2 class="panel-title">New academic record</h2>
    <p class="panel-subtitle">Record a student's academic standing for an academic year / term. One live record per student, year and term.</p>
    <form method="POST" action="{{ route('student-academic-records.store') }}">
        @include('student_academic_records._form', ['submitLabel' => 'Create academic record'])
    </form>
</div>
@endsection
