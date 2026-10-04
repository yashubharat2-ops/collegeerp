@extends('layouts.app')

@section('title', 'New student')

@section('content')
<div class="erp-student-page">
    <div class="erp-form-shell">
        <div class="erp-form-head">
            <h2 class="erp-form-title">New student</h2>
            <p class="erp-form-note">Fields marked <span class="erp-req">*</span> are required.</p>
        </div>
        <p class="erp-form-subtitle">
            Created under the active college; the student number is generated server-side. Only the basic information is required —
            the remaining sections may be completed now or later from this same form.
        </p>
        {{-- multipart: the Basic information section uploads the portrait. --}}
        <form method="POST" action="{{ route('students.store') }}" enctype="multipart/form-data">
            @include('students._form', ['submitLabel' => 'Create student'])
        </form>
    </div>
</div>
@endsection
