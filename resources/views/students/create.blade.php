@extends('layouts.app')
@section('title','New Student')
@section('content')
<div class="panel max-w-4xl">
    <h2 class="panel-title">New student</h2>
    <p class="panel-subtitle">
        The student is created under the active college; you do not choose the college here, and the student number is
        generated server-side. Only the basic information is required — the remaining sections may be completed now or
        later from this same form.
    </p>
    {{-- multipart: the Basic information section uploads the portrait. --}}
    <form method="POST" action="{{ route('students.store') }}" enctype="multipart/form-data">
        @include('students._form', ['submitLabel' => 'Create student'])
    </form>
</div>
@endsection
