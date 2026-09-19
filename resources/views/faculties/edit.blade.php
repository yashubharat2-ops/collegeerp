@extends('layouts.app')

@section('title', 'Edit Faculty Member')

@section('content')
<div class="panel max-w-3xl">
    <h2 class="panel-title">Edit faculty / staff member</h2>
    <p class="panel-subtitle">Updating {{ $faculty->full_name }} ({{ $faculty->employee_code }}) for the active college.</p>

    <form method="POST" action="{{ route('faculties.update', $faculty) }}">
        @method('PUT')
        @include('faculties._form', ['submitLabel' => 'Save changes'])
    </form>
</div>
@endsection
