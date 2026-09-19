@extends('layouts.app')
@section('title','New Student')
@section('content')
<div class="panel max-w-4xl">
    <h2 class="panel-title">New student</h2>
    <p class="panel-subtitle">The student is created under the active college; you do not choose the college here. The student number is generated server-side.</p>
    <form method="POST" action="{{ route('students.store') }}">
        @include('students._form', ['submitLabel' => 'Create student'])
    </form>
</div>
@endsection
