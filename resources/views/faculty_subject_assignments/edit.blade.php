@extends('layouts.app')

@section('title', 'Edit Faculty–Subject Assignment')

@section('content')
<div class="panel max-w-3xl">
    <h2 class="panel-title">Edit faculty–subject assignment</h2>
    <p class="panel-subtitle">Updating assignment for {{ $assignment->faculty?->full_name }} — {{ $assignment->subject?->name }}.</p>

    <form method="POST" action="{{ route('faculty-subject-assignments.update', $assignment) }}">
        @method('PUT')
        @include('faculty_subject_assignments._form', ['submitLabel' => 'Save changes'])
    </form>
</div>
@endsection
