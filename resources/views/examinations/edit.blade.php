@extends('layouts.app')

@section('title', 'Edit Examination')

@section('content')
<div class="panel max-w-3xl">
    <h2 class="panel-title">Edit examination</h2>
    <p class="panel-subtitle">Updating {{ $examination->name }} ({{ $examination->code }}) for the active college.</p>

    <form method="POST" action="{{ route('examinations.update', $examination) }}">
        @method('PUT')
        @include('examinations._form', ['submitLabel' => 'Save changes'])
    </form>
</div>
@endsection
