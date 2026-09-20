@extends('layouts.app')

@section('title', 'Edit Exam Schedule Entry')

@section('content')
<div class="panel max-w-3xl">
    <h2 class="panel-title">Edit exam schedule entry</h2>
    <p class="panel-subtitle">Updating schedule slot for {{ $schedule->subject?->name ?? 'paper' }} under {{ $schedule->examination?->name ?? 'examination' }}.</p>

    <form method="POST" action="{{ route('exam-schedules.update', $schedule) }}">
        @method('PUT')
        @include('exam_schedules._form', ['submitLabel' => 'Save changes'])
    </form>
</div>
@endsection
