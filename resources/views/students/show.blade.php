@extends('layouts.app')
@section('title', $student->fullName().' — Student Profile')
@section('content')
@php
    /*
     * STUDENT PROFILE — one student page, rebuilt on the records the rest of the
     * ERP already owns.
     *
     * Every value rendered here comes from a persisted record: the person data
     * the Registration Wizard wrote onto the student, the enrollment rows, the
     * qualification rows, the document rows and the audit history. Nothing is
     * hardcoded, and nothing academic is re-derived per section — the whole
     * vocabulary is resolved once by StudentProfileService ($profile) so the
     * header, the summary cards and the Academic Information panel can never
     * disagree about which enrollment is current or how a study level is named.
     *
     * The primary sections are LINKS (?tab=…), not scripted widgets: the
     * selected section survives a reload, can be bookmarked and is reachable
     * with a keyboard. Personal Information is the default; lifecycle modules
     * that own their own screens remain reachable from the quieter related row.
     */
    $tabs = [
        'personal' => 'Personal Information',
        'academic' => 'Academic Information',
        'qualifications' => 'Qualifications',
        'documents' => 'Documents',
        'enrollments' => 'Enrollment History',
    ];

    // The lifecycle modules keep their original tab keys, so every existing
    // bookmark (?tab=promotion, ?tab=history …) still lands on the same body.
    $relatedTabs = [
        'academic-records' => 'Academic Records',
        'id-card' => 'ID Card',
        'promotion' => 'Promotion',
        'transfer' => 'Transfer / TC',
        'history' => 'Lifecycle Log',
    ];

    // Preserve old Overview bookmarks by landing them on the new default.
    $tab = $tab === 'profile' ? 'personal' : $tab;
    $tab = array_key_exists($tab, $tabs) || array_key_exists($tab, $relatedTabs) ? $tab : 'personal';

    $currentEnrollment = $profile['currentEnrollment'];

    // Semantic badge classes: green = active/verified, amber = pending,
    // rose = rejected/inactive, slate = unknown, brand = "this is the current
    // one" (a positional marker, never a status claim).
    $badge = static fn (string $state): string => 'sp-badge sp-badge--'.$state;

    // One fact row, so every section renders label/value/empty identically.
    // Returns [label, printable value, isEmpty, note].
    $fact = static function (string $label, $value, ?string $note = null): array {
        $value = is_string($value) ? trim($value) : $value;
        $empty = $value === null || $value === '';

        return [$label, $empty ? 'Not recorded' : (string) $value, $empty, $note];
    };

    $enrollmentStatusState = $currentEnrollment
        ? ($currentEnrollment->isActive() ? 'ok' : ($currentEnrollment->isPendingApproval() ? 'pending' : 'muted'))
        : ($student->enrollments->contains(fn ($enrollment) => $enrollment->isPendingApproval()) ? 'pending' : 'muted');

    $registrationState = $student->isRegistrationRejected()
        ? 'danger'
        : ($student->isRegistrationPending() ? 'pending' : ($student->isRegistrationApproved() ? 'ok' : 'muted'));

    $historyEvents ??= collect();
@endphp

<div class="sp">

    {{-- =====================================================================
       * Header — photograph, identity, current academic context, statuses,
       * authorized actions only.
       * ================================================================== --}}
    <header class="sp-hero">
        <div class="sp-portrait">
            {{-- The initials are the base layer, and the <img> removes itself if
                 the stream fails, so a missing/renamed file can never leave a
                 broken-image icon behind. The file is read from the private disk
                 by an authorized streaming action: no storage path, no public
                 URL, is ever printed into the markup. --}}
            <span class="sp-portrait__initials" aria-hidden="true">{{ $profile['photo']['initials'] }}</span>
            @if($profile['photo']['available'])
                <img src="{{ route('students.photo', $student) }}"
                     alt="{{ $profile['photo']['alt'] }}"
                     width="104" height="128" loading="lazy" decoding="async"
                     onerror="this.remove()">
            @else
                <span class="sp-portrait__badge">No photograph</span>
            @endif
        </div>

        <div class="sp-identity">
            <h2 class="sp-identity__name">{{ $student->fullName() }}</h2>

            <dl class="sp-identity__ids">
                <div><dt>Student ID</dt> <dd>{{ $student->student_number }}</dd></div>
                @if($profile['admissionNumber'])
                    <div><dt>Admission No.</dt> <dd>{{ $profile['admissionNumber'] }}</dd></div>
                @endif
                @if($currentEnrollment?->enrollment_number)
                    <div><dt>Enrollment No.</dt> <dd>{{ $currentEnrollment->enrollment_number }}</dd></div>
                @endif
                @if($currentEnrollment?->roll_number)
                    <div><dt>Roll No.</dt> <dd>{{ $currentEnrollment->roll_number }}</dd></div>
                @endif
            </dl>

            <div class="sp-badges">
                <span class="{{ $badge($registrationState) }}">Registration: {{ $student->registrationStatusLabel() }}</span>
                <span class="{{ $badge($enrollmentStatusState) }}">
                    Enrollment:
                    @if($currentEnrollment)
                        {{ $currentEnrollment->statusLabel() }}
                    @elseif($enrollmentStatusState === 'pending')
                        pending approval
                    @else
                        Not currently enrolled
                    @endif
                </span>
                <span class="{{ $badge($student->status === 'active' ? 'ok' : 'muted') }}">Student status: {{ ucfirst($student->status) }}</span>
            </div>
        </div>

        <div class="sp-actions no-print">
            @can('update', $student)
                <a class="sp-btn" href="{{ route('students.edit', $student) }}">Edit student</a>
            @endcan
            @can('viewAny', App\Models\StudentDocument::class)
                <a class="sp-btn sp-btn--ghost" href="{{ route('student-documents.index', ['student_id' => $student->id]) }}">Manage documents</a>
            @endcan
            <button class="sp-btn sp-btn--quiet" type="button" data-print-now>Print</button>
        </div>
    </header>

    {{-- =====================================================================
       * Summary cards — the six mandated facts, real values only.
       * ================================================================== --}}
    <section class="sp-summary" aria-label="Registration and enrollment summary">
        @foreach($profile['summary'] as $card)
            <div class="sp-summary__item sp-summary__item--{{ $card['state'] }}">
                <p class="sp-summary__label">{{ $card['label'] }}</p>
                <p class="sp-summary__value @if($card['state'] === 'muted') sp-summary__value--muted @endif">{{ $card['value'] }}</p>
            </div>
        @endforeach
    </section>

    {{-- =====================================================================
       * Section navigation
       * ================================================================== --}}
    <nav class="sp-tabs no-print" aria-label="Student profile sections">
        @foreach($tabs as $key => $label)
            <a class="sp-tabs__link"
               href="{{ route('students.show', ['student' => $student, 'tab' => $key]) }}"
               @if($tab === $key) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
    </nav>

    <nav class="sp-related no-print" aria-label="Student lifecycle modules">
        <span class="sp-related__label">Records &amp; Lifecycle</span>
        @foreach($relatedTabs as $key => $label)
            <a class="sp-related__link"
               href="{{ route('students.show', ['student' => $student, 'tab' => $key]) }}"
               @if($tab === $key) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
    </nav>

    {{-- =====================================================================
       * 1. PERSONAL INFORMATION — the default profile section.
       * ================================================================== --}}
    @if($tab === 'personal')
        <div class="sp-personal-grid">
            <section class="sp-card sp-personal-card--wide">
                <header class="sp-card__head">
                    <div>
                        <h3 class="sp-card__title">Personal details</h3>
                        <p class="sp-card__hint">Personal information as saved on the student record.</p>
                    </div>
                </header>
                <div class="sp-card__body">
                    <div class="sp-detail-grid sp-detail-grid--four">
                        @foreach([
                            $fact('Full name', $student->fullName()),
                            $fact('Date of birth', $student->date_of_birth?->format('d M Y')),
                            $fact('Gender', $student->gender ? ucfirst(str_replace('_', ' ', $student->gender)) : null),
                            $fact('Blood group', $student->blood_group === 'unknown' ? null : $student->blood_group),
                            $fact('Nationality', $student->nationality),
                            $fact('Mother tongue', $student->mother_tongue),
                            $fact('Category', $student->category ? App\Models\Student::categoryLabel($student->category) : null),
                        ] as [$label, $value, $empty, $note])
                            <div class="sp-fact">
                                <p class="sp-fact__label">{{ $label }}</p>
                                <p class="sp-fact__value @if($empty) sp-fact__value--empty @endif">{{ $value }}</p>
                            </div>
                        @endforeach
                    </div>
                </div>
            </section>

            <section class="sp-card">
                <header class="sp-card__head">
                    <div>
                        <h3 class="sp-card__title">Contact information</h3>
                        <p class="sp-card__hint">Student and emergency contact details.</p>
                    </div>
                </header>
                <div class="sp-card__body">
                    <div class="sp-detail-grid sp-detail-grid--two">
                        @foreach([
                            $fact('Email address', $student->email),
                            $fact('Mobile number', $student->phone),
                            $fact('Alternate phone', $student->alternate_phone),
                            $fact('Emergency contact', collect([$student->emergency_contact_name, $student->emergency_contact_phone])->filter()->implode(' · ')),
                        ] as [$label, $value, $empty, $note])
                            <div class="sp-fact">
                                <p class="sp-fact__label">{{ $label }}</p>
                                <p class="sp-fact__value @if($empty) sp-fact__value--empty @endif">{{ $value }}</p>
                            </div>
                        @endforeach
                    </div>
                </div>
            </section>

            <section class="sp-card">
                <header class="sp-card__head">
                    <div>
                        <h3 class="sp-card__title">Address</h3>
                        <p class="sp-card__hint">Permanent and correspondence addresses.</p>
                    </div>
                </header>
                <div class="sp-card__body">
                    <div class="sp-address-grid">
                        <div class="sp-address">
                            <h4 class="sp-subhead">Permanent</h4>
                            <p class="sp-address__value @if($profile['addresses']['permanent'] === null) sp-fact__value--empty @endif">
                                {{ $profile['addresses']['permanent'] ?? 'Not recorded' }}
                            </p>
                        </div>
                        <div class="sp-address">
                            <h4 class="sp-subhead">Correspondence</h4>
                            <p class="sp-address__value @if($profile['addresses']['correspondence'] === null) sp-fact__value--empty @endif">
                                {{ $profile['addresses']['correspondence'] ?? 'Not recorded' }}
                            </p>
                            @if($profile['addresses']['sameAsPermanent'])
                                <p class="sp-fact__note">Same as the permanent address.</p>
                            @endif
                        </div>
                    </div>
                </div>
            </section>

            <section class="sp-card sp-personal-card--wide">
                <header class="sp-card__head">
                    <div>
                        <h3 class="sp-card__title">Parent &amp; guardian</h3>
                        <p class="sp-card__hint">Contact and relationship details saved on the student record.</p>
                    </div>
                </header>
                <div class="sp-card__body">
                    <div class="sp-guardian-grid">
                        <div class="sp-facts">
                            <h4 class="sp-subhead">Father</h4>
                            @foreach([
                                $fact('Name', $student->father_name),
                                $fact('Mobile', $student->father_phone),
                                $fact('Email', $student->father_email),
                                $fact('Occupation', $student->father_occupation),
                            ] as [$label, $value, $empty, $note])
                                <div class="sp-fact">
                                    <p class="sp-fact__label">{{ $label }}</p>
                                    <p class="sp-fact__value @if($empty) sp-fact__value--empty @endif">{{ $value }}</p>
                                </div>
                            @endforeach
                        </div>
                        <div class="sp-facts">
                            <h4 class="sp-subhead">Mother</h4>
                            @foreach([
                                $fact('Name', $student->mother_name),
                                $fact('Mobile', $student->mother_phone),
                                $fact('Email', $student->mother_email),
                                $fact('Occupation', $student->mother_occupation),
                            ] as [$label, $value, $empty, $note])
                                <div class="sp-fact">
                                    <p class="sp-fact__label">{{ $label }}</p>
                                    <p class="sp-fact__value @if($empty) sp-fact__value--empty @endif">{{ $value }}</p>
                                </div>
                            @endforeach
                        </div>
                        <div class="sp-facts">
                            <h4 class="sp-subhead">Guardian</h4>
                            @foreach([
                                $fact('Name', $student->guardian_name),
                                $fact('Relation', $student->guardian_relation ? App\Models\Student::guardianRelationLabel($student->guardian_relation) : null),
                                $fact('Mobile', $student->guardian_phone),
                                $fact('Email', $student->guardian_email),
                                $fact('Occupation', $student->guardian_occupation),
                                $fact('Address', $student->guardian_address),
                            ] as [$label, $value, $empty, $note])
                                <div class="sp-fact">
                                    <p class="sp-fact__label">{{ $label }}</p>
                                    <p class="sp-fact__value @if($empty) sp-fact__value--empty @endif">{{ $value }}</p>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </section>

            <section class="sp-card">
                <header class="sp-card__head">
                    <div>
                        <h3 class="sp-card__title">Identity &amp; government IDs</h3>
                        <p class="sp-card__hint">Aadhaar and government ID numbers use the existing masked display. APAAR / ABC ID follows its existing display treatment.</p>
                    </div>
                </header>
                <div class="sp-card__body">
                    <div class="sp-detail-grid sp-detail-grid--two">
                        @foreach([
                            $fact('Aadhaar', $student->maskedAadhaar()),
                            $fact('APAAR / ABC ID', $student->apaar_id),
                            $fact(App\Models\Student::govtIdTypeLabel($student->govt_id_type), $student->maskedGovtIdNumber()),
                        ] as [$label, $value, $empty, $note])
                            <div class="sp-fact">
                                <p class="sp-fact__label">{{ $label }}</p>
                                <p class="sp-fact__value @if($empty) sp-fact__value--empty @endif">{{ $value }}</p>
                            </div>
                        @endforeach
                    </div>
                </div>
            </section>

            <section class="sp-card">
                <header class="sp-card__head">
                    <div>
                        <h3 class="sp-card__title">Previous school / education snapshot</h3>
                        <p class="sp-card__hint">Registration snapshot; complete qualification rows are on the Qualifications tab.</p>
                    </div>
                </header>
                <div class="sp-card__body">
                    <div class="sp-detail-grid sp-detail-grid--two">
                        @foreach([
                            $fact('Previous qualification', $student->previous_qualification),
                            $fact('Previous board / university', $student->previous_school_board),
                            $fact('Previous institution', $student->previous_school_name),
                            $fact('Previous examination year', $student->previous_exam_year !== null ? (string) $student->previous_exam_year : null),
                            $fact('Previous percentage', $student->previous_percentage !== null ? number_format((float) $student->previous_percentage, 2).'%' : null),
                            $fact('Remarks', $student->remarks),
                        ] as [$label, $value, $empty, $note])
                            <div class="sp-fact">
                                <p class="sp-fact__label">{{ $label }}</p>
                                <p class="sp-fact__value @if($empty) sp-fact__value--empty @endif">{{ $value }}</p>
                            </div>
                        @endforeach
                    </div>
                </div>
            </section>
        </div>

    @elseif($tab === 'academic')
        <div class="sp-stack">
            <section class="sp-card">
                <header class="sp-card__head">
                    <div>
                        <h3 class="sp-card__title">Current academic details</h3>
                        <p class="sp-card__hint">
                            Read from the current enrollment — the active record whose academic year contains today.
                            A newer row that is still pending approval, or already completed, is never presented as current.
                        </p>
                    </div>
                    @if($currentEnrollment)
                        <span class="{{ $badge('brand') }}">Current enrollment</span>
                    @else
                        <span class="{{ $badge('muted') }}">No active enrollment</span>
                    @endif
                </header>
                <div class="sp-card__body">
                    @if(! $currentEnrollment)
                        <p class="sp-empty">
                            No active enrollment for the current academic year.
                            @if($student->isRegistrationPending())
                                The registration is awaiting approval, so no enrollment has been created yet.
                            @elseif($student->enrollments->contains(fn ($enrollment) => $enrollment->isPendingApproval()))
                                An enrollment is awaiting approval.
                            @elseif($student->enrollments->isNotEmpty())
                                {{ $student->enrollments->count() }} historical enrollment(s) are listed under Enrollment History.
                            @endif
                        </p>
                    @else
                        <div class="sp-grid">
                            @foreach([
                                $fact('Academic year', $currentEnrollment->academicYear?->name, $currentEnrollment->academicYear?->code),
                                $fact('Programme / course', $currentEnrollment->program?->name, $currentEnrollment->program?->code),
                                $fact('Semester / year of study', $currentEnrollment->semesterLabel()),
                                $fact('Section / batch', $currentEnrollment->section?->name, $currentEnrollment->section?->code),
                                $fact('Enrollment number', $currentEnrollment->enrollment_number),
                                $fact('Roll number', $currentEnrollment->roll_number),
                                $fact('Enrollment date', $currentEnrollment->enrollment_date?->format('d M Y')),
                                $fact('Enrollment status', $currentEnrollment->statusLabel()),
                            ] as [$label, $value, $empty, $note])
                                <div class="sp-fact">
                                    <p class="sp-fact__label">{{ $label }}</p>
                                    <p class="sp-fact__value @if($empty) sp-fact__value--empty @endif">{{ $value }}</p>
                                    @if($note)<p class="sp-fact__note">{{ $note }}</p>@endif
                                </div>
                            @endforeach
                        </div>

                        @if($currentEnrollment->remarks)
                            <p class="sp-fact__note" style="margin-top:14px">Enrollment remarks: {{ $currentEnrollment->remarks }}</p>
                        @endif
                    @endif
                </div>
            </section>

            <section class="sp-card">
                <header class="sp-card__head">
                    <div>
                        <h3 class="sp-card__title">Admission details</h3>
                        <p class="sp-card__hint">Admission and registration context comes from the student and linked admission application. It does not imply that an enrollment exists.</p>
                    </div>
                </header>
                <div class="sp-card__body">
                    <div class="sp-grid">
                        @foreach([
                            $fact('Submitted for approval', $student->registration_submitted_at?->format('d M Y, H:i')),
                            $fact('Admission number', $profile['academic']['admissionNumber'], $profile['academic']['admissionNumber'] ? null : ($profile['academic']['admissionApplication'] ? 'No admission number is linked to this application.' : 'Not issued for a direct registration.')),
                            $fact('Admission date', $student->admission_date?->format('d M Y')),
                            $fact('Admission category', App\Models\Student::categoryLabel($student->category)),
                            $fact('Admission application', $profile['academic']['admissionApplication'], $profile['academic']['admissionApplication'] ? null : 'No linked admission application.'),
                        ] as [$label, $value, $empty, $note])
                            <div class="sp-fact">
                                <p class="sp-fact__label">{{ $label }}</p>
                                <p class="sp-fact__value @if($empty) sp-fact__value--empty @endif">{{ $value }}</p>
                                @if($note)<p class="sp-fact__note">{{ $note }}</p>@endif
                            </div>
                        @endforeach
                    </div>
                </div>
            </section>

            <section class="sp-card">
                <header class="sp-card__head">
                    <div>
                        <h3 class="sp-card__title">Academic years at this college</h3>
                        <p class="sp-card__hint">Each row is one persisted enrollment. The current one is marked; the rest are history and stay untouched when the student advances.</p>
                    </div>
                    @can('viewAny', App\Models\StudentEnrollment::class)
                        <a class="sp-btn sp-btn--quiet no-print" href="{{ route('students.show', ['student' => $student, 'tab' => 'enrollments']) }}">Enrollment History</a>
                    @endcan
                </header>
                <div class="sp-card__body">
                    @if($profile['enrollmentHistory']->isEmpty())
                        <p class="sp-empty">No enrollment recorded for this student.</p>
                    @else
                        <div class="sp-table-wrap">
                            <table class="sp-table">
                                <caption>Enrollment history, newest academic year first</caption>
                                <thead>
                                    <tr>
                                        <th scope="col">Academic year</th>
                                        <th scope="col">Programme</th>
                                        <th scope="col">Semester / year</th>
                                        <th scope="col">Section</th>
                                        <th scope="col">Enrollment no.</th>
                                        <th scope="col">Roll no.</th>
                                        <th scope="col">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($profile['enrollmentHistory'] as $row)
                                        @php $enrollment = $row['enrollment']; @endphp
                                        <tr class="{{ $row['isCurrent'] ? 'sp-row--current' : 'sp-row--historical' }}">
                                            <td>
                                                {{ $enrollment->academicYear?->name ?? '—' }}
                                                @if($row['isCurrent'])<span class="{{ $badge('brand') }}" style="margin-left:6px">Current</span>@endif
                                            </td>
                                            <td>{{ $enrollment->program?->name ?? '—' }}</td>
                                            <td>{{ $enrollment->semesterLabel() ?? 'Not set' }}</td>
                                            <td>{{ $enrollment->section?->name ?? '—' }}</td>
                                            <td class="sp-table__num">{{ $enrollment->enrollment_number }}</td>
                                            <td class="sp-table__num">{{ $enrollment->roll_number ?? 'Pending' }}</td>
                                            <td>{{ $enrollment->statusLabel() }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </section>
        </div>

    {{-- =====================================================================
       * 4. QUALIFICATIONS
       * ================================================================== --}}
    @elseif($tab === 'qualifications')
        <section class="sp-card">
            <header class="sp-card__head">
                <div>
                    <h3 class="sp-card__title">Previous qualifications</h3>
                    <p class="sp-card__hint">
                        Results earned outside this college, exactly as saved during registration.
                        A percentage appears only for a marks-based result whose pair is coherent —
                        a grade-based result keeps its grade and shows none.
                    </p>
                </div>
                <span class="{{ $badge('muted') }}">{{ $profile['qualificationStats']['count'] }} recorded</span>
            </header>
            <div class="sp-card__body">
                @if($profile['qualifications']->isEmpty())
                    <p class="sp-empty">No qualifications recorded for this student.</p>
                @else
                    @foreach($profile['qualifications'] as $row)
                        @php $qualification = $row['qualification']; @endphp
                        <article class="sp-qual">
                            <header class="sp-qual__head">
                                <div>
                                    <h4 class="sp-qual__title">
                                        {{ $qualification->levelLabel() }}@if($qualification->qualification) — {{ $qualification->qualification }}@endif
                                    </h4>
                                    <p class="sp-qual__meta">
                                        {{ collect([$qualification->examination_year, $qualification->board_university, $qualification->institution])->filter()->implode(' · ') ?: 'No year, board or institution recorded' }}
                                    </p>
                                </div>
                                @if($qualification->result_status)
                                    <span class="{{ $badge($qualification->result_status === 'pass' ? 'ok' : ($qualification->result_status === 'fail' ? 'danger' : 'pending')) }}">
                                        {{ ucfirst(str_replace('_', ' ', $qualification->result_status)) }}
                                    </span>
                                @endif
                            </header>
                            <div class="sp-qual__body">
                                <div class="sp-grid">
                                    @foreach([
                                        $fact('Roll number', $qualification->roll_number),
                                        $fact('Registration number', $qualification->registration_number),
                                        $fact('Marks obtained', $qualification->marks_obtained !== null ? (string) $qualification->marks_obtained : null),
                                        $fact('Maximum marks', $qualification->max_marks !== null ? (string) $qualification->max_marks : null),
                                        $fact('Percentage', $row['percentage'] !== null ? number_format((float) $row['percentage'], 2).'%' : null),
                                        $fact('Grade', $qualification->grade),
                                        $fact('Grade letter', $qualification->grade_letter),
                                        $fact('Remarks', $qualification->remarks),
                                    ] as [$label, $value, $empty, $note])
                                        <div class="sp-fact">
                                            <p class="sp-fact__label">{{ $label }}</p>
                                            <p class="sp-fact__value @if($empty) sp-fact__value--empty @endif">{{ $value }}</p>
                                        </div>
                                    @endforeach
                                </div>

                                @if($row['subjects'] !== [])
                                    <div class="sp-qual__subjects">
                                        <p class="sp-qual__subjects-caption">Subjects</p>
                                        <div class="sp-table-wrap">
                                            <table class="sp-table">
                                                <thead>
                                                    <tr>
                                                        <th scope="col">Subject</th>
                                                        <th scope="col">Marks obtained</th>
                                                        <th scope="col">Maximum marks</th>
                                                        <th scope="col">Grade</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    @foreach($row['subjects'] as $subject)
                                                        <tr>
                                                            <td>{{ $subject['name'] ?? $subject['subject'] ?? $subject['title'] ?? '—' }}</td>
                                                            <td class="sp-table__num">{{ $subject['marks_obtained'] ?? $subject['marks'] ?? '—' }}</td>
                                                            <td class="sp-table__num">{{ $subject['max_marks'] ?? $subject['maximum'] ?? '—' }}</td>
                                                            <td>{{ $subject['grade'] ?? '—' }}</td>
                                                        </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        </article>
                    @endforeach
                @endif
            </div>
        </section>

    {{-- =====================================================================
       * 5. DOCUMENTS — authorized preview/thumbnail, never a public path.
       * ================================================================== --}}
    @elseif($tab === 'documents')
        <section class="sp-card">
            <header class="sp-card__head">
                <div>
                    <h3 class="sp-card__title">Documents</h3>
                    <p class="sp-card__hint">
                        Files uploaded with the registration and afterwards. Every preview and download goes through an
                        authorized action on the private disk; the verification badge repeats the stored status and never
                        assumes it.
                    </p>
                </div>
                @can('create', App\Models\StudentDocument::class)
                    <a class="sp-btn no-print" href="{{ route('student-documents.create', ['student_id' => $student->id]) }}">+ Upload document</a>
                @endcan
            </header>
            <div class="sp-card__body">
                @if($profile['documents']->isEmpty())
                    <p class="sp-empty">No documents uploaded yet.</p>
                @else
                    <p class="sp-card__hint" style="margin:0 0 12px">
                        {{ $profile['documentStats']['total'] }} file(s) ·
                        {{ $profile['documentStats']['verified'] }} verified ·
                        @if($profile['documentStats']['rejected'] > 0)
                            {{ $profile['documentStats']['pending'] }} pending verification ·
                            {{ $profile['documentStats']['rejected'] }} rejected
                        @else
                            {{ $profile['documentStats']['pending'] }} pending verification
                        @endif
                    </p>

                    <div class="sp-docs">
                        @foreach($profile['documents'] as $row)
                            @php
                                $document = $row['document'];
                                $verified = $document->isVerified();
                                $rejected = $document->isRejected();
                            @endphp
                            <article class="sp-doc">
                                <div class="sp-doc__thumb">
                                    {{-- The file-type tile is always present; an image document is
                                         streamed inline by the authorized preview action and layered
                                         over it. If the stored file is missing or unreadable the
                                         <img> removes itself and the tile remains — the gallery can
                                         never show a broken-image icon. --}}
                                    <div class="sp-doc__type">
                                        <span class="sp-doc__type-ext">{{ strtoupper($row['extension']) }}</span>
                                        <span class="sp-doc__type-label">{{ $row['preview'] === 'image' ? 'Image document' : ($row['preview'] === 'pdf' ? 'PDF document' : 'File') }}</span>
                                    </div>
                                    @if($row['preview'] === 'image')
                                        <img src="{{ route('student-documents.preview', $document) }}"
                                             alt="Preview of {{ $document->title }}"
                                             loading="lazy" decoding="async"
                                             onerror="this.remove()">
                                    @endif
                                </div>
                                <div class="sp-doc__body">
                                    <p class="sp-doc__title">{{ $document->title }}</p>
                                    <p class="sp-doc__meta">
                                        {{ $document->documentType?->name ?? 'Uncategorised' }}
                                        · {{ $document->original_filename }}
                                        · {{ $document->sizeInKb() }} KB
                                    </p>
                                    <p class="sp-doc__meta">
                                        Uploaded {{ $document->created_at?->format('d M Y') ?? '—' }}
                                        @if($document->issue_date) · issued {{ $document->issue_date->format('d M Y') }} @endif
                                        @if($document->isExpired()) · <span class="{{ $badge('danger') }}">Expired</span> @endif
                                    </p>
                                    <p>
                                        <span class="{{ $badge($verified ? 'ok' : ($rejected ? 'danger' : 'pending')) }}">
                                            {{ ucfirst((string) $document->verification_status) }}
                                        </span>
                                    </p>
                                    <div class="sp-doc__actions no-print">
                                        @can('download', $document)
                                            <a href="{{ route('student-documents.download', $document) }}">Download</a>
                                        @endcan
                                        @can('update', $document)
                                            <a href="{{ route('student-documents.edit', $document) }}">Edit</a>
                                        @endcan
                                    </div>
                                </div>
                            </article>
                        @endforeach
                    </div>
                @endif
            </div>
        </section>

    {{-- =====================================================================
       * 6. ENROLLMENT HISTORY — current vs historical, clearly distinguished.
       * ================================================================== --}}
    @elseif($tab === 'enrollments')
        <section class="sp-card">
            <header class="sp-card__head">
                <div>
                    <h3 class="sp-card__title">Enrollment history</h3>
                    <p class="sp-card__hint">
                        One row per academic year, newest first. A promotion or a new admission adds a row — it never
                        rewrites or deletes an earlier one. The Student ID is stable; the enrollment number, roll
                        number, year, programme, semester and section describe the enrollment.
                    </p>
                </div>
                @can('create', App\Models\StudentEnrollment::class)
                    <a class="sp-btn no-print" href="{{ route('student-enrollments.create', ['student_id' => $student->id]) }}">+ New enrollment</a>
                @endcan
                <span class="{{ $badge('muted') }}">{{ $student->enrollments->count() }} recorded</span>
            </header>
            <div class="sp-card__body">
                @if($profile['enrollmentHistory']->isEmpty())
                    <p class="sp-empty">No enrollments yet.</p>
                @else
                    <div class="sp-table-wrap">
                        <table class="sp-table">
                            <caption>Enrollments, newest academic year first</caption>
                            <thead>
                                <tr>
                                    <th scope="col">Enrollment no.</th>
                                    <th scope="col">Academic year</th>
                                    <th scope="col">Programme</th>
                                    <th scope="col">Semester / year</th>
                                    <th scope="col">Section</th>
                                    <th scope="col">Roll no.</th>
                                    <th scope="col">Enrollment date</th>
                                    <th scope="col">Status</th>
                                    <th scope="col" class="no-print">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($profile['enrollmentHistory'] as $row)
                                    @php $enrollment = $row['enrollment']; @endphp
                                    <tr class="{{ $row['isCurrent'] ? 'sp-row--current' : 'sp-row--historical' }}">
                                        <td class="sp-table__num">{{ $enrollment->enrollment_number }}</td>
                                        <td>
                                            {{ $enrollment->academicYear?->name ?? '—' }}
                                            @if($row['isCurrent'])<span class="{{ $badge('brand') }}">Current</span>@endif
                                        </td>
                                        <td>{{ $enrollment->program?->name ?? '—' }}</td>
                                        <td>{{ $enrollment->semesterLabel() ?? 'Not set' }}</td>
                                        <td>{{ $enrollment->section?->name ?? '—' }}</td>
                                        <td class="sp-table__num">{{ $enrollment->roll_number ?? 'Pending' }}</td>
                                        <td>{{ $enrollment->enrollment_date?->format('d M Y') ?? '—' }}</td>
                                        <td>{{ $enrollment->statusLabel() }}</td>
                                        <td class="no-print">
                                            @can('update', $enrollment)
                                                <a href="{{ route('student-enrollments.edit', $enrollment) }}">Edit</a>
                                            @endcan
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </section>

    {{-- =====================================================================
       * Related lifecycle modules: unchanged screens, reached from this page.
       * ================================================================== --}}
    @elseif($tab === 'academic-records')
        <section class="sp-card">
            <header class="sp-card__head">
                <div>
                    <h3 class="sp-card__title">Academic records</h3>
                    <p class="sp-card__hint">This college's own progression ledger: academic, promotion and completion status per period.</p>
                </div>
                @can('create', App\Models\StudentAcademicRecord::class)
                    <a class="sp-btn no-print" href="{{ route('student-academic-records.create', ['student_id' => $student->id]) }}">+ New record</a>
                @endcan
                <span class="{{ $badge('muted') }}">{{ $student->academicRecords->count() }} recorded</span>
            </header>
            <div class="sp-card__body">
                @if($student->academicRecords->isEmpty())
                    <p class="sp-empty">No academic records yet.</p>
                @else
                    <div class="sp-table-wrap">
                        <table class="sp-table">
                            <thead>
                                <tr>
                                    <th scope="col">Period</th>
                                    <th scope="col">Program</th>
                                    <th scope="col">Section</th>
                                    <th scope="col">Academic</th>
                                    <th scope="col">Promotion</th>
                                    <th scope="col">Completion</th>
                                    <th scope="col" class="no-print">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($student->academicRecords as $record)
                                    <tr>
                                        <td class="sp-table__num">{{ $record->periodLabel() }}</td>
                                        <td>{{ $record->program?->name ?? '—' }}</td>
                                        <td>{{ $record->section?->name ?? '—' }}</td>
                                        <td>{{ ucfirst(str_replace('_', ' ', $record->academic_status)) }}</td>
                                        <td>{{ ucfirst(str_replace('_', ' ', $record->promotion_status)) }}</td>
                                        <td>{{ ucfirst(str_replace('_', ' ', $record->completion_status)) }}</td>
                                        <td class="no-print">
                                            @can('update', $record)
                                                <a href="{{ route('student-academic-records.edit', $record) }}">Edit</a>
                                            @endcan
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </section>

    @elseif($tab === 'id-card')
        <section class="sp-card">
            <header class="sp-card__head">
                <div>
                    <h3 class="sp-card__title">ID card</h3>
                    <p class="sp-card__hint">Generated on demand from this student's record and current enrollment — it is not a separate identity record.</p>
                </div>
            </header>
            <div class="sp-card__body">
                @if(auth()->user()?->hasPermission('student_id_cards.generate'))
                    <a class="sp-btn no-print" href="{{ route('student-id-cards.show', $student) }}" target="_blank" rel="noopener">Generate / print ID card</a>
                @else
                    <p class="sp-empty">You do not have permission to generate ID cards.</p>
                @endif
            </div>
        </section>

    @elseif($tab === 'promotion')
        <section class="sp-card">
            <header class="sp-card__head">
                <div>
                    <h3 class="sp-card__title">Promotions</h3>
                    <p class="sp-card__hint">A promotion creates the next academic year's enrollment and keeps the previous one as history.</p>
                </div>
                @can('create', App\Models\StudentPromotion::class)
                    <a class="sp-btn no-print" href="{{ route('student-promotions.create', ['student_id' => $student->id]) }}">+ New promotion</a>
                @endcan
            </header>
            <div class="sp-card__body">
                @if($student->promotions->isEmpty())
                    <p class="sp-empty">No promotions recorded.</p>
                @else
                    <div class="sp-table-wrap">
                        <table class="sp-table">
                            <thead>
                                <tr>
                                    <th scope="col">From</th>
                                    <th scope="col">To</th>
                                    <th scope="col">Section</th>
                                    <th scope="col">Status</th>
                                    <th scope="col">Approved</th>
                                    <th scope="col" class="no-print">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($student->promotions as $promotion)
                                    <tr>
                                        <td>{{ $promotion->sourceAcademicYear?->name ?? '—' }}</td>
                                        <td>{{ $promotion->targetAcademicYear?->name ?? '—' }}</td>
                                        <td>{{ $promotion->targetSection?->name ?? '—' }}</td>
                                        <td>{{ ucfirst($promotion->status) }}</td>
                                        <td>{{ $promotion->approved_at?->format('d M Y H:i') ?? '—' }}</td>
                                        <td class="no-print">
                                            @if($promotion->isPending())
                                                @can('approve', $promotion)
                                                    <form method="POST" action="{{ route('student-promotions.approve', $promotion) }}"
                                                          onsubmit="return confirm(@js('Approve this promotion? A new enrollment will be created and the current one marked completed.'))">
                                                        @csrf
                                                        <button class="sp-btn sp-btn--quiet" type="submit">Approve</button>
                                                    </form>
                                                @endcan
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </section>

    @elseif($tab === 'transfer')
        <section class="sp-card">
            <header class="sp-card__head">
                <div>
                    <h3 class="sp-card__title">Transfer / TC</h3>
                    <p class="sp-card__hint">A transfer never deletes the student: statuses change, and the record, enrollments and documents are preserved.</p>
                </div>
                @can('create', App\Models\StudentTransfer::class)
                    <a class="sp-btn no-print" href="{{ route('student-transfers.create', ['student_id' => $student->id]) }}">+ New transfer request</a>
                @endcan
            </header>
            <div class="sp-card__body">
                @if($student->transfers->isEmpty())
                    <p class="sp-empty">No transfer requests.</p>
                @else
                    <div class="sp-table-wrap">
                        <table class="sp-table">
                            <thead>
                                <tr>
                                    <th scope="col">Transfer date</th>
                                    <th scope="col">Destination</th>
                                    <th scope="col">Request</th>
                                    <th scope="col">TC number</th>
                                    <th scope="col">TC status</th>
                                    <th scope="col" class="no-print">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($student->transfers as $transfer)
                                    <tr>
                                        <td>{{ $transfer->transfer_date?->format('d M Y') ?? '—' }}</td>
                                        <td>{{ $transfer->destination_institution ?? '—' }}</td>
                                        <td>{{ ucfirst($transfer->status) }}</td>
                                        <td class="sp-table__num">{{ $transfer->tc_number ?? '—' }}</td>
                                        <td>{{ ucfirst(str_replace('_', ' ', $transfer->tc_status)) }}</td>
                                        <td class="no-print">
                                            @if($transfer->hasTcFile())
                                                @can('download', $transfer)
                                                    <a href="{{ route('student-transfers.download', $transfer) }}">TC file</a>
                                                @endcan
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </section>

    @elseif($tab === 'history')
        <section class="sp-card">
            <header class="sp-card__head">
                <div>
                    <h3 class="sp-card__title">Lifecycle log</h3>
                    <p class="sp-card__hint">Derived from the audit trail — registration, approval, enrollment, promotion and transfer events in chronological order.</p>
                </div>
                @if(auth()->user()?->hasPermission('student_history.view'))
                    <a class="sp-btn sp-btn--quiet no-print" href="{{ route('student-history.show', $student) }}">Open full history</a>
                @endif
            </header>
            <div class="sp-card__body">
                <ol class="sp-timeline">
                    @forelse($historyEvents as $event)
                        <li class="sp-timeline__item">
                            <p class="sp-timeline__title">{{ $event->label }}</p>
                            <p class="sp-timeline__meta">{{ $event->occurredAtTime() }} · {{ ucfirst($event->category) }}</p>
                            @if($event->description !== '')
                                <p class="sp-timeline__desc">{{ $event->description }}</p>
                            @endif
                        </li>
                    @empty
                        <li class="sp-timeline__item">
                            <p class="sp-timeline__title">No history recorded yet.</p>
                        </li>
                    @endforelse
                </ol>
            </div>
        </section>
    @endif
</div>
@endsection
