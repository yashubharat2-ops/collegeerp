@extends('layouts.app')
@section('title', 'Edit Designation')
@section('content')<div class="panel max-w-3xl"><h2 class="panel-title">Edit designation</h2><p class="panel-subtitle">Updating {{ $designation->name }} ({{ $designation->code }}).</p><form method="POST" action="{{ route('designations.update', $designation) }}">@method('PUT')@include('designations._form', ['submitLabel' => 'Save changes'])</form></div>@endsection
