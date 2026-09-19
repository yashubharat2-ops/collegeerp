@extends('layouts.app')

@section('title', 'New Subject')

@section('content')
<div class="panel max-w-3xl">
    <h2 class="panel-title">New subject</h2>
    <p class="panel-subtitle">The subject is created under the active college; you do not choose the college here.</p>

    <form method="POST" action="{{ route('subjects.store') }}">
        @include('subjects._form', ['submitLabel' => 'Create subject', 'subject' => null])
    </form>
</div>
@endsection
