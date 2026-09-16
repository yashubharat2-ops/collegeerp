@extends('layouts.app')
@section('title','Edit Enquiry')
@section('content')
<div class="panel max-w-4xl">
    <h2 class="panel-title">Edit enquiry: {{ $enquiry->enquiry_number }}</h2>
    <p class="panel-subtitle">Update enquiry information. Applicant cannot be changed after creation to preserve history.</p>
    <form method="POST" action="{{ route('admission-enquiries.update', $enquiry) }}">
        @csrf
        @method('PUT')
        @include('admission_enquiries._form', ['submitLabel' => 'Update enquiry'])
    </form>
</div>
@endsection
