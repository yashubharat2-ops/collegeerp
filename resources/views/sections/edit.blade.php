@extends('layouts.app')

@section('title', 'Edit Section')

@section('content')
<div class="panel max-w-3xl">
    <h2 class="panel-title">Edit section / batch</h2>
    <p class="panel-subtitle">Updating {{ $section->name }} ({{ $section->code }}) for the active college.</p>

    <form method="POST" action="{{ route('sections.update', $section) }}">
        @method('PUT')
        @include('sections._form', ['submitLabel' => 'Save changes'])
    </form>
</div>
@endsection
