@extends('layouts.app')
@section('title', 'Edit Role')
@section('content')
@include('administration.partials.context')
<div class="panel"><h2 class="panel-title">Edit {{ $role->name }}</h2><p class="panel-subtitle">Changes affect existing assignments of this college role. College ownership, stable identifiers and system flags are immutable here.</p><form class="mt-6" method="POST" action="{{ route('admin.roles.update', $role) }}">@csrf @method('PUT') @include('administration.roles._form')</form></div>
@endsection
