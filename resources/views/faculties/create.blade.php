@extends('layouts.app')

@section('title', 'New Faculty Member')

@section('content')
<div class="panel max-w-3xl">
    <h2 class="panel-title">New faculty / staff member</h2>
    <p class="panel-subtitle">The faculty member is created under the active college; you do not choose the college here.</p>

    <form method="POST" action="{{ route('faculties.store') }}">
        @include('faculties._form', ['submitLabel' => 'Create faculty member', 'faculty' => null])
    </form>
</div>
@endsection
