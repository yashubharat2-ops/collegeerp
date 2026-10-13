@extends('layouts.app')

@section('title', 'Edit Academic Year')

@section('content')
<div class="panel max-w-3xl">
    <h2 class="panel-title">Edit academic year</h2>
    <p class="panel-subtitle">Updating {{ $academicYear->name }} ({{ $academicYear->code }}) for the active college.</p>

    <form method="POST" action="{{ route('academic-years.update', $academicYear) }}">
        @method('PUT')
        @include('academic-years._form', ['submitLabel' => 'Save changes'])
    </form>
</div>
@endsection
