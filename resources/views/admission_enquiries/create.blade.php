@extends('layouts.app')
@section('title','New Enquiry')
@section('content')
<div class="panel max-w-4xl">
    <h2 class="panel-title">New enquiry</h2>
    <p class="panel-subtitle">Create applicant and enquiry together in one transaction. College context is server-side. Duplicate check will warn if same phone/email exists in this college.</p>
    <form method="POST" action="{{ route('admission-enquiries.store') }}">
        @include('admission_enquiries._form', ['submitLabel' => 'Create enquiry'])
    </form>
</div>
@endsection
