@extends('layouts.app')

@section('title', isset($fromAdmission) ? 'New Student from Admission' : (($registrationMode ?? false) ? 'Student Registration' : 'New Student'))

@section('content')
<div class="erp-student-page">
    <header class="erp-page-header">
        <div>
            <h2 class="erp-page-title">{{ isset($fromAdmission) ? 'New Student from Admission' : (($registrationMode ?? false) ? 'Student Registration' : 'New Student') }}</h2>
            <p class="erp-page-sub">
                @isset($fromAdmission)
                    From admission {{ $fromAdmission->admission_number }}. Compatible applicant and academic fields are pre-filled; complete the remaining sections and save to create the student and first enrollment.
                @else
                    {{ ($registrationMode ?? false) ? 'Complete the four steps and submit the registration for approval.' : 'Create a new student record' }}
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

    @if($isRegistration && $latestRegistrationDraft && $latestRegistrationDraft->id !== $registrationDraftId)
        <p class="erp-reg-resume"><a href="{{ route('students.registration.create', ['draft' => $latestRegistrationDraft->id]) }}">Resume your most recently saved draft</a></p>
    @endif

    <form method="POST" action="{{ $formAction }}" enctype="multipart/form-data"
          @if($isRegistration)
              data-registration-wizard
              data-draft-id="{{ $registrationDraftId }}"
              data-save-url="{{ route('students.registration.drafts.save', $registrationDraftId) }}"
              data-review-url="{{ route('students.registration.drafts.review', $registrationDraftId) }}"
              data-attach-url="{{ route('students.registration.drafts.attach', $registrationDraftId) }}"
              data-remove-url="{{ route('students.registration.drafts.remove', ['draft' => $registrationDraftId, 'attachment' => '__ATTACHMENT__']) }}"
          @endif>
        @include('students._form', [
            'submitLabel' => $submitLabel,
            'registrationMode' => $isRegistration,
        ])
    </form>
</div>
@endsection

@if($isRegistration)
    @push('scripts')
        <script type="module" src="{{ asset('js/erp-student-registration.js') }}@if(is_file(public_path('js/erp-student-registration.js')))?v={{ hash_file('crc32b', public_path('js/erp-student-registration.js')) }}@endif"></script>
    @endpush
@endif
