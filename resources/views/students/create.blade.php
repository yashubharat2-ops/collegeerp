@extends('layouts.app')

@section('title', isset($fromAdmission) ? 'New Student from Admission' : 'New Student')

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

    <form method="POST"
          action="{{ isset($fromAdmission) ? route('admissions.convert.store', $fromAdmission) : route('students.store') }}"
          enctype="multipart/form-data">
        @include('students._form', ['submitLabel' => isset($fromAdmission) ? 'Convert to Student' : 'Create Student'])
    </form>
</div>
@endsection
