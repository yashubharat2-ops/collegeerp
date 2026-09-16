@extends('layouts.app')
@section('title','New Admission')
@section('content')
<div class="panel max-w-4xl">
    <h2 class="panel-title">New admission</h2>
    <p class="panel-subtitle">Convert approved/selected application to final admission. Admission number generated server-side.</p>
    <form method="POST" action="{{ route('admissions.store') }}">
        @include('admissions._form', ['submitLabel' => 'Create admission'])
    </form>
</div>
@endsection
