@extends('layouts.app')
@section('title','Edit Application')
@section('content')
<div class="panel max-w-4xl">
    <h2 class="panel-title">Edit application: {{ $application->application_number }}</h2>
    <p class="panel-subtitle">Update application information. The applicant cannot be changed after creation to preserve history.</p>
    <form method="POST" action="{{ route('admission-applications.update', $application) }}">
        @csrf
        @method('PUT')
        @include('admission_applications._form', ['submitLabel' => 'Update application'])
    </form>
</div>
@endsection
