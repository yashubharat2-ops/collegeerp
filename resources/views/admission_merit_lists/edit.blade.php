@extends('layouts.app')
@section('title','Edit Merit List')
@section('content')
<div class="panel max-w-3xl">
    <h2 class="panel-title">Edit merit list: {{ $meritList->code }}</h2>
    <form method="POST" action="{{ route('admission-merit-lists.update', $meritList) }}">
        @method('PUT')
        @include('admission_merit_lists._form', ['submitLabel' => 'Update merit list'])
    </form>
</div>
@endsection
