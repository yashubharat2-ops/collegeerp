@extends('layouts.app')
@section('title', 'Create Role')
@section('content')
@include('administration.partials.context')
<div class="panel"><h2 class="panel-title">New college role</h2><p class="panel-subtitle">Creates a custom definition in the existing roles table, owned by the active college. Platform identifiers and system flags cannot be supplied.</p><form class="mt-6" method="POST" action="{{ route('admin.roles.store') }}">@csrf @include('administration.roles._form')</form></div>
@endsection
