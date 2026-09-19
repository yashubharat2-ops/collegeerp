@extends('layouts.app')
@section('title','Edit Academic Record')
@section('content')
<div class="panel max-w-4xl">
    <h2 class="panel-title">Edit academic record</h2>
    <p class="panel-subtitle">
        {{ $record->student?->fullName() }} ({{ $record->student?->student_number }}) · {{ $record->periodLabel() }}
    </p>
    <form method="POST" action="{{ route('student-academic-records.update', $record) }}">
        @method('PUT')
        @include('student_academic_records._form', ['submitLabel' => 'Update academic record'])
    </form>
</div>
@endsection
