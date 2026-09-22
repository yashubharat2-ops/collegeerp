@extends('layouts.app')
@section('title', 'Edit Leave Type')
@section('content')<div class="panel max-w-3xl"><h2 class="panel-title">Edit leave type</h2><form method="POST" action="{{ route('leave-types.update', $leaveType) }}">@method('PUT')@include('leave_types._form', ['submitLabel' => 'Save leave type'])</form></div>@endsection
