@extends('layouts.app')
@section('title', 'New Leave Request')
@section('content')<div class="panel max-w-3xl"><h2 class="panel-title">New leave request</h2><p class="panel-subtitle">The number of days is calculated on the server.</p><form method="POST" action="{{ route('leave-requests.store') }}">@include('leave_requests._form', ['leaveRequest' => null, 'submitLabel' => 'Submit request'])</form></div>@endsection
