@extends('layouts.app')
@section('title','New Merit Entry')
@section('content')
<div class="panel max-w-3xl">
    <h2 class="panel-title">New merit entry</h2>
    <p class="panel-subtitle">Add application to merit list with score, rank and selection status.</p>
    <form method="POST" action="{{ route('admission-merit-entries.store') }}">
        @include('admission_merit_entries._form', ['submitLabel' => 'Create entry'])
    </form>
</div>
@endsection
