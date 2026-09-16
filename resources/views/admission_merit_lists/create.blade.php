@extends('layouts.app')
@section('title','New Merit List')
@section('content')
<div class="panel max-w-3xl">
    <h2 class="panel-title">New merit list</h2>
    <p class="panel-subtitle">Create a merit list scoped to academic year and program. Publish when ready.</p>
    <form method="POST" action="{{ route('admission-merit-lists.store') }}">
        @include('admission_merit_lists._form', ['submitLabel' => 'Create merit list'])
    </form>
</div>
@endsection
