@extends('layouts.app')

@section('title', 'Edit Subject')

@section('content')
<div class="panel max-w-3xl">
    <h2 class="panel-title">Edit subject</h2>
    <p class="panel-subtitle">Updating {{ $subject->name }} ({{ $subject->code }}) for the active college.</p>

    <form method="POST" action="{{ route('subjects.update', $subject) }}">
        @method('PUT')
        @include('subjects._form', ['submitLabel' => 'Save changes'])
    </form>
</div>
@endsection
