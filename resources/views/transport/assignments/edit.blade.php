@extends('layouts.app')
@section('title', 'Edit Student Transport Assignment')
@section('content')
<div class="panel max-w-4xl">
    <h2 class="panel-title">Edit transport assignment</h2>
    <p class="panel-subtitle">Route, stop, dates and status can be corrected; the student enrollment and academic year are fixed history.</p>
    @if($errors->any())<div class="alert-error mt-4"><ul class="list-inside list-disc">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <form method="POST" action="{{ route('transport-assignments.update', $assignment->id) }}">
        @method('PUT')
        @include('transport.assignments._form', ['submitLabel' => 'Update assignment'])
    </form>
</div>
@endsection
