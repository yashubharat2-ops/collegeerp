@extends('layouts.app')
@section('title','New Applicant')
@section('content')
<div class="panel max-w-3xl">
    <h2 class="panel-title">New applicant</h2>
    <p class="panel-subtitle">The applicant is created under the active college; you do not choose the college here. Minimal applicant (first name + phone/email) is sufficient for enquiry capture.</p>
    <form method="POST" action="{{ route('admission-applicants.store') }}">
        @include('admission_applicants._form', ['submitLabel' => 'Create applicant'])
    </form>
</div>
@endsection
