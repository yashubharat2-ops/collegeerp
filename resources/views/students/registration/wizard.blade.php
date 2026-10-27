@extends('layouts.app')

@php
    /*
     * The registration wizard shell: header, progress indicator, the current
     * step's fields (and NOTHING from the other steps) and the action bar.
     *
     * The action bar is part of the shell, not of each step, so Previous /
     * Save draft / Next always sit in the same place and are labelled the same
     * way on every screen.
     */
    $stepTitle = $steps[$step] ?? 'Registration';
    $isFirst = (int) $step === 1;
    $isLast = (int) $step === \App\Models\StudentRegistrationDraft::LAST_STEP;
    $intro = match ((int) $step) {
        1 => 'Identifiers, personal details, parents, guardian, addresses, photograph and signature.',
        2 => 'Previous qualifications, then the course being joined.',
        3 => 'Pick the documents your course requires, then attach them.',
        default => 'Check everything, then submit for approval.',
    };
@endphp

@section('title', 'Student Registration — '.$stepTitle)

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/erp-registration-wizard.css') }}">
@endpush

@section('content')
<div class="erp-student-page erp-wizard"
     data-erp-wizard
     data-geo-url="{{ route('students.registration.geo.children') }}"
     data-academic-options-url="{{ route('students.registration.academic-options') }}"
     data-draft-url="{{ route('students.registration.step.draft', ['step' => $step]) }}">

    <header class="erp-page-header">
        <div>
            <h2 class="erp-page-title">Student Registration</h2>
            <p class="erp-page-sub">Step {{ $step }} of {{ count($labels) }} — {{ $stepTitle }}. {{ $intro }}</p>
        </div>
        <p class="erp-page-note">
            <span data-erp-autosave-state>Draft saved</span>
            · Fields marked <span class="erp-req">*</span> are required.
        </p>
    </header>

    @include('students.registration._progress')

    @if($errors->any())
        <div class="erp-alert erp-alert--error" role="alert">
            <strong>Please correct the following before continuing:</strong>
            <ul>
                @foreach($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if(session('success'))
        <div class="erp-alert erp-alert--ok" role="status">{{ session('success') }}</div>
    @endif

    @include('students.registration.steps.'.match ((int) $step) {
        1 => 'basic',
        2 => 'academic',
        3 => 'documents',
        default => 'review',
    })

    {{--
        The cancel action is a form of its own, rendered OUTSIDE the step form
        (HTML has no nested forms) and referenced by the action bar's button via
        its `form` attribute.
    --}}
    <form id="erp-cancel-form" method="POST" action="{{ route('students.registration.cancel') }}"
          onsubmit="return confirm('Cancel this registration? The saved draft and its uploaded files are removed.');">
        @csrf
        <input type="hidden" name="draft_id" value="{{ $draftId }}">
    </form>
</div>
@endsection

@push('scripts')
    <script src="{{ asset('js/erp-registration-wizard.js') }}" defer></script>
@endpush
