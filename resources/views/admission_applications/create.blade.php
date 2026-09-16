@extends('layouts.app')
@section('title','New Application')
@section('content')
<div class="panel max-w-4xl">
    <h2 class="panel-title">New application</h2>
    <p class="panel-subtitle">Create a formal application for an applicant, academic year and program. College context and application number are server-side; an originating enquiry may optionally be linked.</p>
    <form method="POST" action="{{ route('admission-applications.store') }}">
        @include('admission_applications._form', ['submitLabel' => 'Create application'])
    </form>
</div>
@endsection
