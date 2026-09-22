@extends('layouts.app')
@section('title', 'New Leave Type')
@section('content')<div class="panel max-w-3xl"><h2 class="panel-title">New leave type</h2><form method="POST" action="{{ route('leave-types.store') }}">@include('leave_types._form', ['leaveType' => null, 'submitLabel' => 'Create leave type'])</form></div>@endsection
