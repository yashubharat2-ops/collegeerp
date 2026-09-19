@extends('layouts.app')
@section('title','New Campus')
@section('content')
<div class="panel max-w-3xl">
    <h2 class="panel-title">New campus</h2>
    <p class="panel-subtitle">The campus is created under the active college; you do not choose the college here.</p>
    <form method="POST" action="{{ route('campuses.store') }}">
        @include('campuses._form', ['submitLabel' => 'Create campus'])
    </form>
</div>
@endsection
