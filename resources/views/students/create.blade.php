@extends('layouts.app')

@section('title', isset($fromAdmission) ? 'New Student from Admission' : (($registrationMode ?? false) ? 'Student Registration' : 'New Student'))

@section('content')
<div class="erp-student-page">
    <header class="erp-page-header">
        <div>
            <h2 class="erp-page-title">{{ isset($fromAdmission) ? 'New Student from Admission' : 'New Student' }}</h2>
            <p class="erp-page-sub">
                @isset($fromAdmission)
                    From admission {{ $fromAdmission->admission_number }}. Compatible applicant and academic fields are pre-filled; complete the remaining sections and save to create the student and first enrollment.
                @else
                    Create a new student record
                @endisset
            </p>
        </div>
        <p class="erp-page-note">Fields marked <span class="erp-req">*</span> are required.</p>
    </header>

    @php
        $isRegistration = (bool) ($registrationMode ?? false);
        $formAction = isset($fromAdmission)
            ? route('admissions.convert.store', $fromAdmission)
            : ($isRegistration ? route('students.registration.store') : route('students.store'));
        $submitLabel = isset($fromAdmission)
            ? 'Convert to Student'
            : ($isRegistration ? 'Submit Registration for Approval' : 'Create Student');
    @endphp

    <form method="POST" action="{{ $formAction }}" enctype="multipart/form-data">
        @include('students._form', [
            'submitLabel' => $submitLabel,
            'registrationMode' => $isRegistration,
        ])
    </form>
</div>
@endsection
