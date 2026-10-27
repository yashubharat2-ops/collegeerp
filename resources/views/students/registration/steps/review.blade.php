@php
    /*
     * STEP 4 — Final Review → Submit for Approval.
     *
     * One group per section of the application, each with its own Edit button
     * back to the step that owns the data (parent/guardian lives on step 1 now).
     * Nothing here is editable except the final remarks: the review screen shows
     * exactly what will be submitted.
     *
     * Submitting raises ONE pending student and ONE approval request through the
     * existing approval framework. The student stays pending — and out of the
     * approved Student List — until an approver decides.
     */
    $show = fn ($key) => filled($merged[$key] ?? null) ? $merged[$key] : null;
    $address = function (array $keys) use ($merged) {
        $parts = [];

        foreach ($keys as $key) {
            if (filled($merged[$key] ?? null)) {
                $parts[] = $merged[$key];
            }
        }

        return $parts === [] ? null : implode(', ', $parts);
    };

    $permanentParts = $address(['address_line_1', 'address_line_2', 'gram_panchayat_ward', 'city', 'block_tehsil', 'district', 'state', 'postal_code', 'country']);
    $sameAsPermanent = filter_var($merged['correspondence_same_as_permanent'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
    $correspondenceParts = $sameAsPermanent
        ? null
        : $address(['correspondence_address_line_1', 'correspondence_address_line_2', 'correspondence_city', 'correspondence_district', 'correspondence_state', 'correspondence_postal_code', 'correspondence_country']);

    $missingDocuments = $requirements->filter(fn ($row) => $row['status'] === \App\Domain\Student\Services\CourseDocumentRequirementService::STATUS_MISSING);
@endphp

@if($missingDocuments->isNotEmpty())
    <div class="erp-alert erp-alert--error" role="alert">
        <strong>Required documents are missing:</strong>
        {{ $missingDocuments->map(fn ($row) => $row['type']->name)->implode(', ') }}.
        <a href="{{ route('students.registration.step', ['step' => 3]) }}">Attach them</a> before submitting.
    </div>
@endif

@if(! empty($duplicateWarnings))
    <div class="erp-alert erp-alert--info" role="status" data-erp-review-duplicates>
        <strong>Possible duplicate — the approver will see this:</strong>
        <ul>
            @foreach($duplicateWarnings as $warning)
                <li>{{ $warning['message'] }}</li>
            @endforeach
        </ul>
        This is a warning only. Verified identifiers (mobile, Aadhaar, APAAR) are checked separately and do block.
    </div>
@endif

{{-- --- Student identification ------------------------------------------ --}}
<section class="erp-review-group">
    <header class="erp-review-group__head">
        <h3 class="erp-review-group__title">Student Identification</h3>
    </header>
    <div class="erp-review-group__body">
        <dl class="erp-review-list">
            @foreach($identifiers as $key => $identifier)
                <div>
                    <dt>{{ $identifier['label'] }}</dt>
                    <dd data-erp-identifier="{{ $key }}">
                        {{ $identifier['value'] ?? 'Pending' }}
                        @if($identifier['state'] === 'pending')
                            <span class="erp-chip erp-chip--pending">Pending</span>
                        @endif
                    </dd>
                </div>
            @endforeach
        </dl>
    </div>
</section>

{{-- --- Basic information ------------------------------------------------ --}}
<section class="erp-review-group">
    <header class="erp-review-group__head">
        <h3 class="erp-review-group__title">Basic Information</h3>
        <a class="erp-btn erp-btn--ghost erp-btn--sm" href="{{ route('students.registration.step', ['step' => 1]) }}">Edit</a>
    </header>
    <div class="erp-review-group__body">
        <dl class="erp-review-list">
            <div><dt>Name</dt><dd>{{ $fullName }}</dd></div>
            <div><dt>Date of birth</dt><dd>{{ $show('date_of_birth') }}</dd></div>
            <div><dt>Gender</dt><dd>{{ $show('gender') ? ucfirst(str_replace('_', ' ', $merged['gender'])) : null }}</dd></div>
            <div><dt>Category</dt><dd>{{ filled($merged['category'] ?? null) ? \App\Models\Student::categoryLabel($merged['category']) : null }}</dd></div>
            <div><dt>Mother tongue</dt><dd>{{ $show('mother_tongue') }}</dd></div>
            <div><dt>Blood group</dt><dd>{{ $show('blood_group') }}</dd></div>
            <div><dt>Nationality</dt><dd>{{ $show('nationality') }}</dd></div>
            <div><dt>Mobile</dt><dd>{{ $show('phone') }}</dd></div>
            <div><dt>Alternate mobile</dt><dd>{{ $show('alternate_phone') }}</dd></div>
            <div><dt>Email</dt><dd>{{ $show('email') }}</dd></div>
            <div><dt>Aadhaar</dt><dd>{{ $aadhaarMasked ?? 'Not recorded' }}</dd></div>
            <div><dt>APAAR / ABC ID</dt><dd>{{ $show('apaar_id') }}</dd></div>
            <div><dt>Permanent address</dt><dd>{{ $permanentParts }}</dd></div>
            <div><dt>Correspondence</dt><dd>{{ $sameAsPermanent ? 'Same as permanent address' : $correspondenceParts }}</dd></div>
            <div>
                <dt>Media</dt>
                <dd>
                    {{ collect([
                        'photo_path' => 'Photograph',
                        'signature_path' => 'Signature',
                        'father_photo_path' => "Father's photo",
                        'mother_photo_path' => "Mother's photo",
                        'guardian_photo_path' => "Guardian's photo",
                    ])->filter(fn ($label, $key) => filled($merged[$key] ?? null))->implode(', ') ?: null }}
                </dd>
            </div>
        </dl>
    </div>
</section>

{{-- --- Parent / guardian -------------------------------------------------- --}}
<section class="erp-review-group">
    <header class="erp-review-group__head">
        <h3 class="erp-review-group__title">Parent &amp; Guardian</h3>
        <a class="erp-btn erp-btn--ghost erp-btn--sm" href="{{ route('students.registration.step', ['step' => 1]) }}">Edit</a>
    </header>
    <div class="erp-review-group__body">
        <dl class="erp-review-list">
            <div><dt>Father</dt><dd>{{ $show('father_name') }}</dd></div>
            <div><dt>Father's mobile</dt><dd>{{ $show('father_phone') }}</dd></div>
            <div><dt>Father's occupation</dt><dd>{{ $show('father_occupation') }}</dd></div>
            <div><dt>Mother</dt><dd>{{ $show('mother_name') }}</dd></div>
            <div><dt>Mother's mobile</dt><dd>{{ $show('mother_phone') }}</dd></div>
            <div><dt>Mother's occupation</dt><dd>{{ $show('mother_occupation') }}</dd></div>
            <div><dt>Guardian</dt><dd>{{ $show('guardian_name') }}</dd></div>
            <div><dt>Guardian is</dt><dd>{{ filled($merged['guardian_relation'] ?? null) ? \App\Models\Student::guardianRelationLabel($merged['guardian_relation']) : null }}</dd></div>
            <div><dt>Guardian's mobile</dt><dd>{{ $show('guardian_phone') }}</dd></div>
            <div><dt>Guardian's email</dt><dd>{{ $show('guardian_email') }}</dd></div>
        </dl>
    </div>
</section>

{{-- --- Academic record ---------------------------------------------------- --}}
<section class="erp-review-group">
    <header class="erp-review-group__head">
        <h3 class="erp-review-group__title">Academic Record</h3>
        <a class="erp-btn erp-btn--ghost erp-btn--sm" href="{{ route('students.registration.step', ['step' => 2]) }}">Edit</a>
    </header>
    <div class="erp-review-group__body">
        <dl class="erp-review-list">
            <div><dt>Academic year</dt><dd>{{ $academicYear?->name }}</dd></div>
            <div><dt>Programme</dt><dd>{{ $program?->name }}</dd></div>
            {{-- The configured level is shown as the college configured it; the
                 stored label is the fallback for a year that has no levels yet. --}}
            <div><dt>Semester / year</dt><dd>{{ $academicTerm?->name ?? $show('semester') }}</dd></div>
            <div><dt>Section</dt><dd>{{ $section?->name }}</dd></div>
            <div><dt>Admission date</dt><dd>{{ $show('admission_date') }}</dd></div>
            <div><dt>Enrollment date</dt><dd>{{ $show('enrollment_date') }}</dd></div>
            <div><dt>Admission category</dt><dd>{{ \App\Models\Student::categoryLabel($merged['category'] ?? null) }}</dd></div>
        </dl>

        <h4 class="erp-subhead">Previous qualifications</h4>

        @if(empty($qualificationRows))
            <p class="erp-alert erp-alert--info">No previous qualification recorded.</p>
        @else
            <table class="erp-quals">
                <thead>
                    <tr>
                        <th scope="col">Level</th>
                        <th scope="col">Qualification</th>
                        <th scope="col">Year</th>
                        <th scope="col">Board / University</th>
                        <th scope="col">Marks</th>
                        <th scope="col">Grade</th>
                        <th scope="col">Result</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($qualificationRows as $row)
                        @php
                            $obtained = $row['marks_obtained'] ?? null;
                            $max = $row['max_marks'] ?? null;
                            $percentage = is_numeric($obtained) && is_numeric($max) && (float) $max > 0
                                ? round(((float) $obtained / (float) $max) * 100, 2).'%'
                                : null;
                        @endphp
                        <tr>
                            <td>{{ \App\Models\StudentQualification::LEVEL_LABELS[$row['level'] ?? ''] ?? '—' }}</td>
                            <td>{{ $row['qualification'] ?? '—' }}</td>
                            <td>{{ $row['examination_year'] ?? '—' }}</td>
                            <td>{{ $row['board_university'] ?? '—' }}</td>
                            <td>
                                @if($percentage)
                                    {{ $obtained }} / {{ $max }} ({{ $percentage }})
                                @else
                                    —
                                @endif
                            </td>
                            <td>{{ $row['grade'] ?? $row['grade_letter'] ?? '—' }}</td>
                            <td>{{ filled($row['result_status'] ?? null) ? ucfirst($row['result_status']) : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</section>

{{-- --- Documents ---------------------------------------------------------- --}}
<section class="erp-review-group">
    <header class="erp-review-group__head">
        <h3 class="erp-review-group__title">Documents</h3>
        <a class="erp-btn erp-btn--ghost erp-btn--sm" href="{{ route('students.registration.step', ['step' => 3]) }}">Edit</a>
    </header>
    <div class="erp-review-group__body">
        @if($requirements->isNotEmpty())
            <ul class="erp-req-list">
                @foreach($requirements as $requirement)
                    <li>
                        <span class="erp-req-list__name">{{ $requirement['type']->name }}</span>
                        <span class="erp-chip erp-chip--{{ $requirement['status'] }}">{{ \App\Domain\Student\Services\CourseDocumentRequirementService::statusLabel($requirement['status']) }}</span>
                    </li>
                @endforeach
            </ul>
        @endif

        @if($documents->isEmpty())
            <p class="erp-alert erp-alert--info">No document attached.</p>
        @else
            <ul class="erp-req-list">
                @foreach($documents as $document)
                    <li>
                        <span class="erp-req-list__name">{{ $document->title }}</span>
                        <span class="erp-help">{{ $document->original_filename }}</span>
                        <span class="erp-chip erp-chip--uploaded">Awaiting verification</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</section>

{{-- --- Submit -------------------------------------------------------------- --}}
<form id="erp-submit-form" method="POST" action="{{ route('students.registration.submit') }}">
    @csrf
    <input type="hidden" name="draft_id" value="{{ $draftId }}">

    <section class="erp-card erp-wizard__card">
        <header class="erp-card__head">
            <div>
                <h3 class="erp-card__title">Final remarks &amp; declaration</h3>
                <p class="erp-card__desc">Optional remarks for the approver, then submit.</p>
            </div>
        </header>

        <div class="erp-card__body">
            <div class="erp-field">
                <label class="erp-label" for="remarks">Remarks</label>
                <textarea class="erp-input" id="remarks" name="remarks" rows="3" maxlength="2000">{{ old('remarks', $stepData['remarks'] ?? '') }}</textarea>
                <p class="erp-error">@error('remarks'){{ $message }}@enderror</p>
            </div>

            <div class="erp-field">
                <label class="erp-checkline">
                    <input type="checkbox" name="confirm_declaration" value="1" required @checked(old('confirm_declaration'))>
                    I confirm the details above are correct to the best of my knowledge.
                </label>
                <p class="erp-error">@error('confirm_declaration'){{ $message }}@enderror</p>
            </div>

            <p class="erp-help">
                Submitting creates one pending registration and one approval request. The student appears on the
                approved Student List only after an approver accepts it.
            </p>
        </div>
    </section>

    @include('students.registration._actions', ['step' => 4, 'draftId' => $draftId, 'submitMode' => true])
</form>
