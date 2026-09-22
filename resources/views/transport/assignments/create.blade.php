@extends('layouts.app')
@section('title', 'Assign Student Transport')
@section('content')
<div class="panel max-w-4xl">
    <h2 class="panel-title">Assign student transport</h2>
    <p class="panel-subtitle">Reuse existing enrollments, routes and stops. Only one active assignment may exist per enrollment and academic year.</p>
    @if($errors->any())<div class="alert-error mt-4"><ul class="list-inside list-disc">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <form method="POST" action="{{ route('transport-assignments.store') }}">
        @include('transport.assignments._form', ['submitLabel' => 'Create assignment'])
    </form>
</div>
@endsection
