@extends('layouts.app')

@section('title', 'New Exam Schedule Entry')

@section('content')
<div class="panel max-w-3xl">
    <h2 class="panel-title">New exam schedule entry</h2>
    <p class="panel-subtitle">Create a schedule slot for an examination paper within the active college.</p>

    <form method="POST" action="{{ route('exam-schedules.store') }}">
        @include('exam_schedules._form', ['submitLabel' => 'Create schedule entry', 'schedule' => null])
    </form>
</div>
@endsection
