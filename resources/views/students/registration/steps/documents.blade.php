@php
    /*
     * STEP 4 — Attach Documents.
     *
     * Requirements are COURSE-WISE and come from the existing configuration:
     * `admission_document_types` (the per-college master) plus the
     * `admission_document_type_program` pivot (is_required, can_submit_later)
     * for the programme chosen on step 3. Nothing is hard-coded here.
     *
     * An uploaded document is "awaiting verification" — never "verified". Only a
     * user with the Documents permission can verify it, after approval, in the
     * Documents module.
     *
     * Files are private: they are streamed through an authorised route, and no
     * stored path is ever rendered into the page.
     */
    $program = $merged['program_id'] ?? null;
@endphp

<section class="erp-card erp-wizard__card">
    <header class="erp-card__head">
        <span class="erp-card__num">01</span>
        <div>
            <h3 class="erp-card__title">Documents required for
                {{ isset($merged['program_id']) ? (\App\Models\Program::query()->find($merged['program_id'])?->name ?? 'this course') : 'this course' }}
            </h3>
            <p class="erp-card__desc">Required documents must be attached before submitting. Optional ones may be added later.</p>
        </div>
    </header>

    <div class="erp-card__body">
        @if(blank($program))
            <p class="erp-alert erp-alert--info">
                Choose the course on
                <a href="{{ route('students.registration.step', ['step' => 3]) }}">Step 3</a>
                to see which documents it requires. You can still attach documents below.
            </p>
        @endif

        @if($requirements->isEmpty())
            <p class="erp-alert erp-alert--info">
                No document types are configured for this college yet. Everything attached here is optional;
                an administrator can configure requirements under Admission → Document Types.
            </p>
        @else
            <ul class="erp-req-list">
                @foreach($requirements as $requirement)
                    <li>
                        <span class="erp-req-list__name">{{ $requirement['type']->name }}</span>
                        <span class="erp-chip erp-chip--{{ $requirement['status'] }}">{{ \App\Domain\Student\Services\CourseDocumentRequirementService::statusLabel($requirement['status']) }}</span>
                        @if($requirement['is_required'] && ! $requirement['can_submit_later'])
                            <span class="erp-help">Required before submission</span>
                        @elseif($requirement['is_required'])
                            <span class="erp-help">Required by the course; may be submitted after admission</span>
                        @else
                            <span class="erp-help">Optional</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</section>

<section class="erp-card erp-wizard__card">
    <header class="erp-card__head">
        <span class="erp-card__num">02</span>
        <div>
            <h3 class="erp-card__title">Attach a document</h3>
            <p class="erp-card__desc">PDF, JPG, JPEG or PNG depending on the document type · up to 5 MB.</p>
        </div>
    </header>

    <div class="erp-card__body">
        <form method="POST" action="{{ route('students.registration.documents.upload') }}" enctype="multipart/form-data">
            @csrf
            <input type="hidden" name="draft_id" value="{{ $draftId }}">

            <div class="erp-upload-row">
                <div class="erp-field">
                    <label class="erp-label" for="document_type_id">Document type</label>
                    <select class="erp-input" id="document_type_id" name="document_type_id">
                        <option value="">— Other document —</option>
                        @foreach($documentTypes as $type)
                            <option value="{{ $type->id }}" @selected((string) old('document_type_id') === (string) $type->id)>{{ $type->name }}</option>
                        @endforeach
                    </select>
                    <p class="erp-error">@error('document_type_id'){{ $message }}@enderror</p>
                </div>

                <div class="erp-field">
                    <label class="erp-label" for="document_title">Title (optional)</label>
                    <input class="erp-input" id="document_title" name="title" value="{{ old('title') }}" maxlength="255">
                    <p class="erp-error">@error('title'){{ $message }}@enderror</p>
                </div>

                <div class="erp-field">
                    <label class="erp-label" for="document_file">File <span class="erp-req">*</span></label>
                    <input class="erp-input" id="document_file" name="file" type="file" accept="application/pdf,image/jpeg,image/png" required>
                    <p class="erp-error">@error('file'){{ $message }}@enderror</p>
                </div>

                <div class="erp-field">
                    <button class="erp-btn" type="submit">Attach</button>
                </div>
            </div>

            <div class="erp-field">
                <label class="erp-label" for="document_remarks">Remarks (optional)</label>
                <input class="erp-input" id="document_remarks" name="remarks" value="{{ old('remarks') }}" maxlength="1000">
                <p class="erp-error">@error('remarks'){{ $message }}@enderror</p>
            </div>
        </form>
    </div>
</section>

<section class="erp-card erp-wizard__card">
    <header class="erp-card__head">
        <span class="erp-card__num">03</span>
        <div>
            <h3 class="erp-card__title">Attached documents</h3>
            <p class="erp-card__desc">Replace or remove a file before submitting. Verification happens after approval.</p>
        </div>
    </header>

    <div class="erp-card__body">
        @if($documents->isEmpty())
            <p class="erp-alert erp-alert--info">No document attached yet.</p>
        @else
            <table class="erp-doc-table">
                <thead>
                    <tr>
                        <th scope="col">Document</th>
                        <th scope="col">Type</th>
                        <th scope="col">File</th>
                        <th scope="col">Status</th>
                        <th scope="col">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($documents as $document)
                        <tr>
                            <td>{{ $document->title }}</td>
                            <td>{{ $document->documentType?->name ?? 'Other' }}</td>
                            <td>
                                <a href="{{ route('students.registration.documents.download', ['document' => $document->id]) }}">
                                    {{ $document->original_filename }}
                                </a>
                                <span class="erp-help">{{ number_format(((int) $document->file_size) / 1024, 0) }} KB</span>
                            </td>
                            <td><span class="erp-chip erp-chip--uploaded">Awaiting verification</span></td>
                            <td class="erp-doc-table__actions">
                                <form method="POST" action="{{ route('students.registration.documents.replace', ['document' => $document->id]) }}" enctype="multipart/form-data">
                                    @csrf
                                    <input type="hidden" name="draft_id" value="{{ $draftId }}">
                                    <input type="hidden" name="document_type_id" value="{{ $document->document_type_id }}">
                                    <label class="erp-visually-hidden" for="replace-{{ $document->id }}">Replacement file for {{ $document->title }}</label>
                                    <input id="replace-{{ $document->id }}" name="file" type="file" required
                                           accept="application/pdf,image/jpeg,image/png" style="max-width:150px;display:inline-block">
                                    <button class="erp-btn erp-btn--ghost erp-btn--sm" type="submit">Replace</button>
                                </form>

                                <form method="POST" action="{{ route('students.registration.documents.destroy', ['document' => $document->id]) }}"
                                      onsubmit="return confirm('Remove this document from the registration?');">
                                    @csrf
                                    <input type="hidden" name="draft_id" value="{{ $draftId }}">
                                    <button class="erp-btn erp-btn--ghost erp-btn--sm" type="submit">Remove</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</section>

{{-- Navigation only: this step has no data of its own to save. --}}
<form id="erp-step-form" data-erp-step-form method="POST" action="{{ route('students.registration.step.save', ['step' => 4]) }}">
    @csrf
    <input type="hidden" name="draft_id" value="{{ $draftId }}">
    @include('students.registration._actions', ['step' => 4, 'draftId' => $draftId, 'submitMode' => false])
</form>
