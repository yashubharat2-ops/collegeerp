@php
    /*
     * STEP 3 — Attach Documents.
     *
     * Three separate things, in this order, and they are never conflated:
     *
     *   A. DECLARATIONS ("Select the documents you have"). A checkbox card per
     *      configured type — `admission_document_types` of this college, with the
     *      course-wise requirement resolved from `admission_document_type_program`
     *      (is_required / can_submit_later). Nothing is hard-coded and nothing is
     *      pre-ticked: the applicant states what they have. A type the selected
     *      course requires MUST be declared before Final Review / submit (checked
     *      in the browser for convenience, and again on the server in
     *      StudentRegistrationWizardController::persistStep() and
     *      RegistrationSubmissionService::assertDocumentRequirements()).
     *
     *   B. FILES (optional). Ticking a box says "I have this document"; it never
     *      attaches, selects or verifies a file. The upload card for a type is
     *      therefore optional even when the type is required, and a card that
     *      already holds a file stays visible even if its box is un-ticked —
     *      un-ticking never deletes anything. Removal is always the explicit
     *      "Remove" action.
     *
     *   C. "+ Attach a document" — one collapsed row for a file that is not in the
     *      list above (a type can be chosen, a title is optional). The file is
     *      still required for that row: an empty form must not create a record.
     *
     * An uploaded document is "awaiting verification" — never "verified". Only a
     * user with the Documents permission can verify it, after approval, in the
     * Documents module. Files are private: they are streamed through an
     * authorised route, and no stored path is ever rendered into the page.
     */
    $programName = isset($merged['program_id'])
        ? (\App\Models\Program::query()->find($merged['program_id'])?->name ?? 'this course')
        : null;

    // The declarations, as stored in the draft — or as re-posted after a
    // rejected save/submit, so a failed validation never silently clears ticks.
    $storedDeclared = array_values(array_map('intval', (array) ($declaredDocuments ?? [])));
    $oldDeclared = old('declared_documents');
    $declaredList = is_array($oldDeclared) ? array_values(array_map('intval', $oldDeclared)) : $storedDeclared;

    // Files are grouped by type so a card can state what it holds. A file with no
    // type belongs to section C only and is listed in the file table below.
    $filesByType = $documents->groupBy(fn ($document) => (int) $document->document_type_id);
    $untypedFiles = $documents->filter(fn ($document) => $document->document_type_id === null)->values();

    $requiredRows = $requirements->filter(fn ($row) => $row['is_required'] && ! $row['can_submit_later']);
    $requiredNames = $requiredRows->map(fn ($row) => $row['type']->name)->values();
    $missingRequired = $requiredRows->reject(fn ($row) => in_array((int) $row['type']->id, $declaredList, true))->values();

    $statusText = function ($files): string {
        if ($files->contains(fn ($document) => ($document->verification_status ?? 'pending') === 'verified')) {
            return 'Verified';
        }

        return $files->isNotEmpty() ? 'File attached — awaiting verification' : 'No file attached';
    };
@endphp

{{-- --- A. Declarations ---------------------------------------------------- --}}
<form id="erp-step-form" data-erp-step-form data-erp-declaration-form method="POST"
      action="{{ route('students.registration.step.save', ['step' => 3]) }}">
    @csrf
    <input type="hidden" name="draft_id" value="{{ $draftId }}">

    <section class="erp-card erp-wizard__card" aria-labelledby="wz-docs-pick">
        <header class="erp-card__head">
            <div>
                <h3 class="erp-card__title" id="wz-docs-pick">1. Select the documents you have</h3>
                <p class="erp-card__desc">
                    @if($programName)
                        Documents for {{ $programName }}. Attaching the files is optional and can be done now or later —
                        every required type must simply be selected here before you continue to the review.
                    @else
                        Choose the course on <a href="{{ route('students.registration.step', ['step' => 2]) }}">Step 2</a>
                        to see which documents it requires. You can still select and attach documents now.
                    @endif
                </p>
            </div>
        </header>

        <div class="erp-card__body">
            @if($requirements->isEmpty())
                <p class="erp-alert erp-alert--info">
                    No document types are configured for this college yet, so every attachment is optional.
                    An administrator configures them under Admission → Document Types.
                </p>
            @else
                @if($requiredNames->isNotEmpty())
                    <p class="erp-help" data-erp-doc-required-hint>
                        Required by this course and to be selected before continuing:
                        <strong>{{ $requiredNames->implode(', ') }}</strong>.
                        The file itself may be attached now or later.
                    </p>
                @endif

                <ul class="erp-doc-picker" data-erp-doc-picker>
                    @foreach($requirements as $row)
                        @php
                            $typeId = (int) $row['type']->id;
                            $isRequired = $row['is_required'] && ! $row['can_submit_later'];
                            $typeFiles = $filesByType->get($typeId, collect());
                        @endphp
                        <li>
                            <label class="erp-doc-option {{ $isRequired ? 'erp-doc-option--required' : '' }}"
                                   for="doc-type-{{ $typeId }}" data-erp-doc-option="{{ $typeId }}">
                                <input type="checkbox" id="doc-type-{{ $typeId }}"
                                       name="declared_documents[]" value="{{ $typeId }}"
                                       data-erp-doc-toggle="{{ $typeId }}"
                                       data-erp-doc-name="{{ $row['type']->name }}"
                                       @if($isRequired) data-erp-doc-required="true" @endif
                                       aria-describedby="doc-state-{{ $typeId }}"
                                       @checked(in_array($typeId, $declaredList, true))>

                                <span class="erp-doc-option__label">{{ $row['type']->name }}</span>

                                @if($isRequired)
                                    <span class="erp-chip erp-chip--required">Required</span>
                                @elseif($row['is_required'])
                                    <span class="erp-chip erp-chip--deferred">May be submitted later</span>
                                @else
                                    <span class="erp-chip erp-chip--optional">Optional</span>
                                @endif

                                <span class="erp-chip erp-chip--declared"
                                      data-erp-doc-declared="{{ $typeId }}"
                                      @if(! in_array($typeId, $declaredList, true)) hidden @endif>Selected</span>

                                <span class="erp-doc-option__state" id="doc-state-{{ $typeId }}">
                                    {{ $statusText($typeFiles) }}
                                </span>
                            </label>
                        </li>
                    @endforeach
                </ul>

                <p class="erp-help">
                    Selecting a document states that you have it. It does not attach a file and it does not verify
                    anything — verification is done by the college against the file you attach.
                </p>
            @endif

            @error('declared_documents')<p class="erp-error">{{ $message }}</p>@enderror
            @error('declared_documents.*')<p class="erp-error">{{ $message }}</p>@enderror

            <p class="erp-alert erp-alert--error" role="alert" data-erp-doc-missing hidden>
                <strong>Select the documents your course requires before continuing:</strong>
                <span data-erp-doc-missing-names>{{ $missingRequired->map(fn ($row) => $row['type']->name)->implode(', ') }}</span>.
                The files may be attached later — only the selection is needed to continue.
            </p>

            @error('documents')<p class="erp-error" role="alert">{{ $message }}</p>@enderror
        </div>
    </section>
</form>

{{-- --- B. Files (optional) ------------------------------------------------ --}}
<section class="erp-card erp-wizard__card" aria-labelledby="wz-docs-attach">
    <header class="erp-card__head">
        <div>
            <h3 class="erp-card__title" id="wz-docs-attach">2. Attach the files (optional)</h3>
            <p class="erp-card__desc">
                PDF, JPG, JPEG or PNG depending on the document type · up to 5 MB each. You can submit with no file
                attached; documents can also be added later from the student's Documents tab.
            </p>
        </div>
    </header>

    <div class="erp-card__body">
        <div class="erp-doc-cards">
            @foreach($requirements as $row)
                @php
                    $typeId = (int) $row['type']->id;
                    $typeDocuments = $filesByType->get($typeId, collect());
                    $attached = $typeDocuments->isNotEmpty();
                @endphp

                <div class="erp-doc-card {{ $attached ? 'erp-doc-card--attached' : '' }}"
                     data-erp-doc-card="{{ $typeId }}"
                     data-erp-doc-attached="{{ $attached ? 'true' : 'false' }}"
                     @if(! in_array($typeId, $declaredList, true) && ! $attached) hidden @endif>
                    <div class="erp-doc-card__head">
                        <h4 class="erp-doc-card__title">{{ $row['type']->name }}</h4>

                        @if($row['is_required'] && ! $row['can_submit_later'])
                            <span class="erp-chip erp-chip--required">Required</span>
                        @elseif($row['is_required'])
                            <span class="erp-chip erp-chip--deferred">May follow</span>
                        @else
                            <span class="erp-chip erp-chip--optional">Optional</span>
                        @endif

                        <span class="erp-chip erp-chip--{{ $attached ? 'uploaded' : 'not-uploaded' }}">
                            {{ $attached ? 'File attached' : 'No file yet' }}
                        </span>
                    </div>

                    @if($attached)
                        <p class="erp-help">
                            {{ $statusText($typeDocuments) }}. This file is kept even if you un-select the type above;
                            use Remove to delete it.
                        </p>
                    @endif

                    <form method="POST" action="{{ route('students.registration.documents.upload') }}"
                          enctype="multipart/form-data" class="erp-upload-row">
                        @csrf
                        <input type="hidden" name="draft_id" value="{{ $draftId }}">
                        <input type="hidden" name="document_type_id" value="{{ $typeId }}">
                        <div class="erp-field">
                            <label class="erp-label" for="file-{{ $typeId }}">
                                {{ $attached ? 'Add another file' : 'File' }}
                            </label>
                            <input class="erp-input" id="file-{{ $typeId }}" name="file" type="file" required
                                   accept="application/pdf,image/jpeg,image/png">
                        </div>
                        <div class="erp-field">
                            <label class="erp-label" for="remarks-{{ $typeId }}">Remarks (optional)</label>
                            <input class="erp-input" id="remarks-{{ $typeId }}" name="remarks" maxlength="1000">
                        </div>
                        <div class="erp-field">
                            <button class="erp-btn erp-btn--outline erp-btn--sm" type="submit">Attach file</button>
                        </div>
                    </form>
                </div>
            @endforeach
        </div>

        <p class="erp-alert erp-alert--info" data-erp-doc-empty
           @if($declaredList !== [] || $requirements->isEmpty()) hidden @endif>
            Select a document type above to attach its file.
        </p>

        {{-- --- C. One optional row for anything else -------------------------- --}}
        <details class="erp-doc-other" data-erp-doc-other
                 @if(old('document_type_id') !== null || $errors->has('file')) open @endif>
            <summary>+ Attach a document</summary>

            <form method="POST" action="{{ route('students.registration.documents.upload') }}"
                  enctype="multipart/form-data" class="erp-upload-row" data-erp-doc-attach-form>
                @csrf
                <input type="hidden" name="draft_id" value="{{ $draftId }}">

                <div class="erp-field">
                    <label class="erp-label" for="document_type_id">Document type</label>
                    <select class="erp-input" id="document_type_id" name="document_type_id">
                        <option value="">— Not in the list (other document) —</option>
                        @foreach($documentTypes as $type)
                            <option value="{{ $type->id }}" @selected((string) old('document_type_id') === (string) $type->id)>{{ $type->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="erp-field">
                    <label class="erp-label" for="document_title">Title (optional)</label>
                    <input class="erp-input" id="document_title" name="title" value="{{ old('title') }}" maxlength="255">
                </div>

                <div class="erp-field">
                    <label class="erp-label" for="document_file">File</label>
                    {{-- Not required just because the row is open: it is required only
                         when this row is actually submitted, and the server refuses a
                         submission without it (no empty "attachment" is ever created). --}}
                    <input class="erp-input" id="document_file" name="file" type="file" required
                           accept="application/pdf,image/jpeg,image/png">
                </div>

                <div class="erp-field">
                    <button class="erp-btn erp-btn--sm" type="submit">Attach</button>
                </div>

                <p class="erp-error" data-erp-doc-attach-error hidden role="alert">
                    Choose a file to attach, or close this row to continue without adding one.
                </p>
            </form>
        </details>

        @error('file')<p class="erp-error" role="alert">{{ $message }}</p>@enderror
        @error('document_type_id')<p class="erp-error" role="alert">{{ $message }}</p>@enderror
        @error('title')<p class="erp-error" role="alert">{{ $message }}</p>@enderror

        {{-- --- Attached files ------------------------------------------------- --}}
        @if($documents->isEmpty())
            <p class="erp-alert erp-alert--info" data-erp-doc-files-empty>
                No file attached yet. This is not a problem: selected documents are enough to submit, and the files
                can be added later from the student's Documents tab.
            </p>
        @else
            <table class="erp-doc-table erp-doc-files" data-erp-doc-files>
                <caption class="erp-help">{{ $documents->count() }} {{ \Illuminate\Support\Str::plural('file', $documents->count()) }} attached — every one is awaiting verification.</caption>
                <thead>
                    <tr>
                        <th scope="col">Document type</th>
                        <th scope="col">File</th>
                        <th scope="col">Size</th>
                        <th scope="col">Status</th>
                        <th scope="col"><span class="erp-visually-hidden">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($documents as $document)
                        <tr>
                            <td>{{ $document->documentType?->name ?? ($document->title ?: 'Other document') }}</td>
                            <td>
                                <a href="{{ route('students.registration.documents.download', ['document' => $document->id]) }}">
                                    {{ $document->original_filename }}
                                </a>
                                @if($document->title && $document->title !== $document->original_filename)
                                    <span class="erp-help">· {{ $document->title }}</span>
                                @endif
                            </td>
                            <td>{{ number_format(((int) $document->file_size) / 1024, 0) }} KB</td>
                            <td>
                                <span class="erp-chip erp-chip--uploaded">
                                    {{ ($document->verification_status ?? 'pending') === 'verified' ? 'Verified' : 'Awaiting verification' }}
                                </span>
                            </td>
                            <td class="erp-doc-table__actions">
                                <div class="erp-doc-card__actions">
                                    <form method="POST"
                                          action="{{ route('students.registration.documents.replace', ['document' => $document->id]) }}"
                                          enctype="multipart/form-data">
                                        @csrf
                                        <input type="hidden" name="draft_id" value="{{ $draftId }}">
                                        <label class="erp-visually-hidden" for="replace-{{ $document->id }}">
                                            Replacement file for {{ $document->original_filename }}
                                        </label>
                                        <input id="replace-{{ $document->id }}" name="file" type="file" required
                                               accept="application/pdf,image/jpeg,image/png">
                                        <button class="erp-btn erp-btn--ghost erp-btn--sm" type="submit">Replace</button>
                                    </form>

                                    <form method="POST"
                                          action="{{ route('students.registration.documents.destroy', ['document' => $document->id]) }}"
                                          onsubmit="return confirm('Remove this file from the registration?');">
                                        @csrf
                                        <input type="hidden" name="draft_id" value="{{ $draftId }}">
                                        <button class="erp-btn erp-btn--ghost erp-btn--sm" type="submit">Remove</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            @if($untypedFiles->isNotEmpty())
                <p class="erp-help">
                    {{ $untypedFiles->count() }} {{ \Illuminate\Support\Str::plural('file', $untypedFiles->count()) }}
                    were attached without a configured type.
                </p>
            @endif
        @endif
    </div>
</section>

{{--
    The action bar sits OUTSIDE the step form (HTML has no nested forms): its
    buttons address the declaration form by id, and file uploads keep their own
    forms. Everything the step stores is the declaration list.
--}}
@include('students.registration._actions', ['step' => 3, 'draftId' => $draftId, 'submitMode' => false])
