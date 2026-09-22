@extends('layouts.app')
@section('title', 'Edit Leave Request')
@section('content')<div class="panel max-w-3xl"><h2 class="panel-title">Edit leave request</h2><form method="POST" action="{{ route('leave-requests.update', $leaveRequest) }}">@method('PUT')@include('leave_requests._form', ['submitLabel' => 'Save request'])</form></div>@endsection
