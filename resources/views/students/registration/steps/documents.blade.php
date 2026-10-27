@php
    /*
     * STEP 4 — Attach Documents.
     *
     * Two compact parts:
     *
     *   A. Select required document types — a checkbox grid built from the
     *      college's own document master (`admission_document_types`) with the
     *      course-wise requirement resolved from
     *      `admission_document_type_program` (is_required / can_submit_later).
     *      Nothing is hard-coded here, and a document the selected course
     *      requires is rendered ticked and locked, so un-ticking can never make
     *      the requirement disappear.
     *
     *   B. Attach the selected documents — one compact upload card per selected
     *      type. A card that already holds a file stays visible (and its file is
     *      never silently discarded) even if its box is un-ticked; removal is
     *      always an explicit "Remove" action.
     *
     * Server-side truth: the requirement check at submission reads the STORED
     * files, never the checkbox state, so the browser cannot bypass a mandatory
     * document. See RegistrationSubmissionService::assertDocumentRequirements().
     *
     * An uploaded document is "awaiting verification" — never "verified". Only a
     * user with the Documents permission can verify it, after approval, in the
     * Documents module.
     *
     * Files are private: they are streamed through an authorised route, and no
     * stored path is ever rendered into the page.
     */
    $program = $merged['program_id'] ?? null;
    $programName = isset($merged['program_id'])
        ? (\App\Models\Program::query()->find($merged['program_id'])?->name ?? 'this course')
        : null;

    // What the applicant ticked before a failed upload/submit round-trip.
    $selected = old('selected_documents');
    $selected = is_array($selected) ? array_map('intval', $selected) : null;

    $chosen = [];

    foreach ($requirements as $row) {
        $typeId = (int) $row['type']->id;
        $locked = $row['is_required'] && ! $row['can_submit_later'];

        // Locked (course-required) types are always selected; the rest follow
        // the applicant's own choice, defaulting to "selected when a file is
        // already attached" so a resumed draft shows its upload cards.
        $chosen[$typeId] = $locked
            || ($selected !== null ? in_array($typeId, $selected, true) : $row['uploaded']);
    }
@endphp

<section class="erp-card erp-wizard__card" aria-labelledby="wz-docs-pick">
    <header class="erp-card__head">
        <div>
            <h3 class="erp-card__title" id="wz-docs-pick">Select documents</h3>
            <p class="erp-card__desc">
                @if($programName)
                    Documents for {{ $programName }}. Required ones must be attached before submitting.
                @else
                    Choose the course on <a href="{{ route('students.registration.step', ['step' => 2]) }}">Step 2</a>
                    to see which documents it requires. You can still attach documents now.
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
            <ul class="erp-doc-picker" data-erp-doc-picker>
                @foreach($requirements as $row)
                    @php
                        $typeId = (int) $row['type']->id;
                        $locked = $row['is_required'] && ! $row['can_submit_later'];
                    @endphp
                    <li>
                        <label class="erp-doc-option {{ $locked ? 'erp-doc-option--required' : '' }}"
                               for="doc-type-{{ $typeId }}">
                            @if($locked)
                                {{-- A locked requirement still posts its id, even though
                                     the checkbox itself is disabled in the browser. --}}
                                <input type="hidden" name="selected_documents[]" value="{{ $typeId }}">
                            @endif
                            <input type="checkbox" id="doc-type-{{ $typeId }}"
                                   name="selected_documents[]" value="{{ $typeId }}"
                                   data-erp-doc-toggle="{{ $typeId }}"
                                   @checked($chosen[$typeId])
                                   @if($locked) disabled @endif>
                            <span class="erp-doc-option__label">{{ $row['type']->name }}</span>
                            @if($locked)
                                <span class="erp-chip erp-chip--required">Required</span>
                            @elseif($row['is_required'])
                                <span class="erp-chip erp-chip--deferred">Later</span>
                            @else
                                <span class="erp-chip erp-chip--optional">Optional</span>
                            @endif
                            <span class="erp-doc-option__state">
                                {{ $row['uploaded'] ? 'Uploaded' : 'Not uploaded' }}
                            </span>
                        </label>
                    </li>
                @endforeach
            </ul>

            <p class="erp-help">
                Required documents are ticked and cannot be un-ticked. Optional and deferred documents are
                your choice; a file you already attached is always kept until you remove it.
            </p>
        @endif
    </div>
</section>

<section class="erp-card erp-wizard__card" aria-labelledby="wz-docs-attach">
    <header class="erp-card__head">
        <div>
            <h3 class="erp-card__title" id="wz-docs-attach">Attach documents</h3>
            <p class="erp-card__desc">PDF, JPG, JPEG or PNG depending on the document type · up to 5 MB each.</p>
        </div>
    </header>

    <div class="erp-card__body">
        <div class="erp-doc-cards">
            @foreach($requirements as $row)
                @php
                    $typeId = (int) $row['type']->id;
                    $typeDocuments = $row['documents'];
                    $attached = $typeDocuments->isNotEmpty();
                @endphp

                <div class="erp-doc-card {{ $attached ? 'erp-doc-card--attached' : '' }}"
                     data-erp-doc-card="{{ $typeId }}"
                     @if($attached) data-erp-doc-attached="true" @endif
                     @if(! $chosen[$typeId] && ! $attached) hidden @endif>
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
                            {{ $attached ? 'Uploaded' : 'Not uploaded' }}
                        </span>
                    </div>

                    @if($attached)
                        @foreach($typeDocuments as $document)
                            <p class="erp-doc-card__file">
                                <a href="{{ route('students.registration.documents.download', ['document' => $document->id]) }}">
                                    {{ $document->original_filename }}
                                </a>
                                <span class="erp-help">{{ number_format(((int) $document->file_size) / 1024, 0) }} KB ·
                                    awaiting verification</span>
                            </p>
                        @endforeach

                        <p class="erp-help">
                            This file is kept even if you un-tick the type above. Use Replace or Remove to change it.
                        </p>

                        <div class="erp-doc-card__actions">
                            <form method="POST" action="{{ route('students.registration.documents.replace', ['document' => $typeDocuments->first()->id]) }}"
                                  enctype="multipart/form-data">
                                @csrf
                                <input type="hidden" name="draft_id" value="{{ $draftId }}">
                                <input type="hidden" name="document_type_id" value="{{ $typeId }}">
                                <label class="erp-visually-hidden" for="replace-{{ $typeId }}">Replacement file for {{ $row['type']->name }}</label>
                                <input id="replace-{{ $typeId }}" name="file" type="file" required
                                       accept="application/pdf,image/jpeg,image/png">
                                <button class="erp-btn erp-btn--ghost erp-btn--sm" type="submit">Replace</button>
                            </form>

                            <form method="POST" action="{{ route('students.registration.documents.destroy', ['document' => $typeDocuments->first()->id]) }}"
                                  onsubmit="return confirm('Remove this document from the registration?');">
                                @csrf
                                <input type="hidden" name="draft_id" value="{{ $draftId }}">
                                <button class="erp-btn erp-btn--ghost erp-btn--sm" type="submit">Remove</button>
                            </form>
                        </div>
                    @else
                        <form method="POST" action="{{ route('students.registration.documents.upload') }}" enctype="multipart/form-data">
                            @csrf
                            <input type="hidden" name="draft_id" value="{{ $draftId }}">
                            <input type="hidden" name="document_type_id" value="{{ $typeId }}">
                            <label class="erp-visually-hidden" for="file-{{ $typeId }}">File for {{ $row['type']->name }}</label>
                            <input id="file-{{ $typeId }}" name="file" type="file" required
                                   accept="application/pdf,image/jpeg,image/png">
                            <div class="erp-doc-card__actions">
                                <input class="erp-input" name="remarks" maxlength="1000" placeholder="Remarks (optional)"
                                       aria-label="Remarks for {{ $row['type']->name }}" style="max-width: 190px">
                                <button class="erp-btn erp-btn--sm" type="submit">Attach</button>
                            </div>
                        </form>
                    @endif
                </div>
            @endforeach
        </div>

        <p class="erp-alert erp-alert--info" data-erp-doc-empty
           @if(collect($chosen)->contains(true) || $requirements->isEmpty()) hidden @endif>
            Tick a document type above to attach its file.
        </p>

        @error('selected_documents')<p class="erp-error">{{ $message }}</p>@enderror
        @error('file')<p class="erp-error">{{ $message }}</p>@enderror
        @error('document_type_id')<p class="erp-error">{{ $message }}</p>@enderror

        {{-- "Other" attachment: no type, so it is not part of the requirement
             list. Kept collapsed so it never crowds the configured types. --}}
        <details class="erp-doc-other">
            <summary>Attach another document (not in the list above)</summary>
            <form method="POST" action="{{ route('students.registration.documents.upload') }}"
                  enctype="multipart/form-data" class="erp-upload-row">
                @csrf
                <input type="hidden" name="draft_id" value="{{ $draftId }}">
                <div class="erp-field">
                    <label class="erp-label" for="document_type_id">Document type</label>
                    <select class="erp-input" id="document_type_id" name="document_type_id">
                        <option value="">— Other document —</option>
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
                    <label class="erp-label" for="document_file">File <span class="erp-req">*</span></label>
                    <input class="erp-input" id="document_file" name="file" type="file" accept="application/pdf,image/jpeg,image/png" required>
                </div>
                <div class="erp-field">
                    <button class="erp-btn erp-btn--sm" type="submit">Attach</button>
                </div>
            </form>
        </details>

        @if($documents->isNotEmpty())
            <p class="erp-help">
                {{ $documents->count() }} {{ \Illuminate\Support\Str::plural('file', $documents->count()) }} attached to
                this registration. Every one of them is awaiting verification.
            </p>
        @endif
    </div>
</section>

{{-- Navigation only: this step has no data of its own to save. --}}
<form id="erp-step-form" data-erp-step-form method="POST" action="{{ route('students.registration.step.save', ['step' => 3]) }}">
    @csrf
    <input type="hidden" name="draft_id" value="{{ $draftId }}">
    @include('students.registration._actions', ['step' => 3, 'draftId' => $draftId, 'submitMode' => false])
</form>
