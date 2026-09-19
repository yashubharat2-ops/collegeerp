@extends('layouts.app')

@section('title', 'New Faculty–Subject Assignment')

@section('content')
<div class="panel max-w-3xl">
    <h2 class="panel-title">New faculty–subject assignment</h2>
    <p class="panel-subtitle">The assignment is created under the active college; you do not choose the college here.</p>

    <form method="POST" action="{{ route('faculty-subject-assignments.store') }}">
        @include('faculty_subject_assignments._form', ['submitLabel' => 'Create assignment', 'assignment' => null])
    </form>
</div>
@endsection
