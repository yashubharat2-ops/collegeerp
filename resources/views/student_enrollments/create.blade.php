@extends('layouts.app')
@section('title','New Enrollment')
@section('content')
<div class="panel max-w-4xl">
    <h2 class="panel-title">New enrollment</h2>
    <p class="panel-subtitle">Enroll an existing, approved student into an academic year (and optional program). The enrollment number and roll number are generated server-side; the Student ID is never re-issued.</p>

    @if(! empty($approvalRequired))
        <p class="mt-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
            This college requires approval for new enrollments: the enrollment is created as
            <strong>Pending approval</strong> and only becomes active when it is decided in Approval Management.
            It already carries its generated Enrollment Number and Roll Number.
        </p>
    @endif
    <form method="POST" action="{{ route('student-enrollments.store') }}">
        @include('student_enrollments._form', ['submitLabel' => 'Create enrollment'])
    </form>
</div>
@endsection
