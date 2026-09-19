@extends('layouts.app')
@section('title','Edit Campus')
@section('content')
<div class="panel max-w-3xl">
    <h2 class="panel-title">Edit campus</h2>
    <p class="panel-subtitle">Updating {{ $campus->name }} ({{ $campus->code }}) for the active college.</p>
    <form method="POST" action="{{ route('campuses.update', $campus) }}">
        @method('PUT')
        @include('campuses._form', ['submitLabel' => 'Save changes'])
    </form>
</div>
@endsection
