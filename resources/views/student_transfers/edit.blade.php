@extends('layouts.app')
@section('title','Edit Transfer Request')
@section('content')
<div class="panel max-w-4xl">
    <h2 class="panel-title">Edit transfer request</h2>
    <p class="panel-subtitle">Only pending requests can be edited; an approved or closed request is a record of what was decided.</p>
    <form method="POST" action="{{ route('student-transfers.update', $transfer) }}">
        @method('PUT')
        @include('student_transfers._form', ['submitLabel' => 'Update transfer request'])
    </form>
</div>
@endsection
