@extends('layouts.app')
@section('title', 'New Designation')
@section('content')<div class="panel max-w-3xl"><h2 class="panel-title">New designation</h2><p class="panel-subtitle">Designations are scoped to the active college.</p><form method="POST" action="{{ route('designations.store') }}">@include('designations._form', ['designation' => null, 'submitLabel' => 'Create designation'])</form></div>@endsection
