@extends('layouts.app')
@section('title','Edit Applicant')
@section('content')
<div class="panel max-w-3xl">
    <h2 class="panel-title">Edit applicant: {{ $applicant->first_name }} {{ $applicant->last_name }}</h2>
    <p class="panel-subtitle">Update applicant information. College context is server-side.</p>
    <form method="POST" action="{{ route('admission-applicants.update', $applicant) }}">
        @csrf
        @method('PUT')
        @include('admission_applicants._form', ['submitLabel' => 'Update applicant'])
    </form>
</div>
@endsection
