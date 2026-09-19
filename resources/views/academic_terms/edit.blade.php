@extends('layouts.app')

@section('title', 'Edit Academic Term')

@section('content')
<div class="panel max-w-3xl">
    <h2 class="panel-title">Edit academic term / semester</h2>
    <p class="panel-subtitle">Updating {{ $academicTerm->name }} ({{ $academicTerm->code }}) for the active college.</p>

    <form method="POST" action="{{ route('academic-terms.update', $academicTerm) }}">
        @method('PUT')
        @include('academic_terms._form', ['submitLabel' => 'Save changes'])
    </form>
</div>
@endsection
