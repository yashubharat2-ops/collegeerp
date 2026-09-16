@extends('layouts.app')
@section('title','Edit Admission')
@section('content')
<div class="panel max-w-4xl">
    <h2 class="panel-title">Edit admission: {{ $admission->admission_number }}</h2>
    <form method="POST" action="{{ route('admissions.update', $admission) }}">
        @method('PUT')
        @include('admissions._form', ['submitLabel' => 'Update admission'])
    </form>
</div>
@endsection
