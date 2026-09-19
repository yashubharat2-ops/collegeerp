@extends('layouts.app')
@section('title','New Transfer Request')
@section('content')
<div class="panel max-w-4xl">
    <h2 class="panel-title">New transfer / TC request</h2>
    <p class="panel-subtitle">The request starts as pending. Approval, then issuance, changes statuses only — the student's record and history are never deleted.</p>
    <form method="POST" action="{{ route('student-transfers.store') }}">
        @include('student_transfers._form', ['submitLabel' => 'Record transfer request'])
    </form>
</div>
@endsection
