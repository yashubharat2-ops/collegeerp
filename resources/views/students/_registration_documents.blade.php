@php
    $selectedDeclarations = old('registration_document_type_ids', []);
    $selectedDeclarations = is_array($selectedDeclarations)
        ? array_map('strval', array_filter($selectedDeclarations, fn ($value) => is_string($value) || is_int($value)))
        : [];
    $programValue = old('program_id', 0);
    $selectedProgram = is_numeric($programValue) ? (int) $programValue : 0;
@endphp
<div class="erp-registration-documents">
    <input type="hidden" name="registration_declarations_present" value="1">
    <fieldset class="erp-reg-fieldset">
        <legend class="erp-subhead">Document declarations</legend>
        <p class="erp-help">Select every required document. This is a declaration only, not confirmation of receipt or verification. No file is required.</p>
        <div class="erp-reg-check-grid">
            @forelse($registrationDocumentTypes as $type)
                @php
                    $requiredForProgram = array_key_exists($selectedProgram, $type['programs'])
                        ? $type['programs'][$selectedProgram] : $type['required'];
                @endphp
                <label class="erp-reg-check-card">
                    <input type="checkbox" name="registration_document_type_ids[]" value="{{ $type['id'] }}"
                           data-registration-declaration data-doc-name="{{ $type['name'] }}"
                           data-default-required="{{ $type['required'] ? '1' : '0' }}"
                           data-program-requirements="{{ json_encode($type['programs'], JSON_FORCE_OBJECT) }}"
                           @checked(in_array((string) $type['id'], $selectedDeclarations, true))>
                    <span class="erp-reg-check-card__text">
                        <strong>{{ $type['name'] }}</strong>
                        <small data-registration-required-label>{{ $requiredForProgram ? 'Required' : 'Optional' }}</small>
                    </span>
                </label>
            @empty
                <p class="erp-help">No document types are configured for this college.</p>
            @endforelse
        </div>
        <p class="erp-error">@error('registration_document_type_ids'){{ $message }}@enderror @error('registration_document_type_ids.*'){{ $message }}@enderror</p>
    </fieldset>

    <div class="erp-reg-uploaded" data-registration-attachments data-initial-attachments="{{ json_encode($registrationAttachments) }}">
        <h4>Attached files <span>Optional</span></h4>
        <p class="erp-help">PDF, JPG or PNG, up to 5 MB each and 10 files total. Document-type limits also apply. Files are private and not yet verified.</p>
        <div data-registration-attachment-list>
            @forelse($registrationAttachments as $attachment)
                <div class="erp-reg-file">
                    <div>
                        <strong>{{ $attachment['type_name'] }}</strong>
                        @if($attachment['title'] !== $attachment['filename'])<span>{{ $attachment['title'] }}</span>@endif
                        <span>{{ $attachment['filename'] }}</span>
                        <small>{{ $attachment['status'] }} · Not verified</small>
                    </div>
                    <button class="erp-reg-remove" type="button" data-remove-attachment="{{ $attachment['id'] }}">Remove</button>
                </div>
            @empty
                <p class="erp-help" data-no-attachments>No files attached.</p>
            @endforelse
        </div>
    </div>

    <details class="erp-reg-attachment erp-reg-js-only" data-registration-attachment-form>
        <summary>+ Attach a document</summary>
        <div class="erp-reg-attachment__body">
            <div class="erp-reg-upload-grid">
                <div class="erp-field">
                    <label class="erp-label" for="attachment_type_id">Document Type</label>
                    <select class="erp-input" id="attachment_type_id" name="attachment_type_id">
                        <option value="">— Unclassified —</option>
                        @foreach($registrationDocumentTypes as $type)
                            <option value="{{ $type['id'] }}">{{ $type['name'] }} ({{ $type['code'] }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="erp-field">
                    <label class="erp-label" for="attachment_title">Title <span class="erp-help">(optional)</span></label>
                    <input class="erp-input" id="attachment_title" name="attachment_title" maxlength="255">
                </div>
                <div class="erp-field">
                    <label class="erp-label" for="attachment_file">File <span class="erp-help">(optional)</span></label>
                    <input class="erp-file-input" id="attachment_file" name="attachment_file" type="file" accept=".pdf,.jpg,.jpeg,.png">
                </div>
            </div>
            <input type="hidden" name="attachment_token" data-attachment-token value="{{ (string) \Illuminate\Support\Str::uuid() }}">
            <p class="erp-help">Use Attach to save a selected file. You can close this form without attaching anything.</p>
            <p class="erp-error" data-attachment-error role="alert"></p>
            <div class="erp-reg-attachment__actions">
                <button class="erp-btn erp-btn--sm" type="button" data-attachment-add>Attach</button>
                <button class="erp-btn erp-btn--sm erp-btn--ghost" type="button" data-attachment-cancel>Cancel</button>
            </div>
        </div>
    </details>

    <noscript>
        <div class="erp-reg-attachment__body">
            <p class="erp-help">Without JavaScript, optional files can be sent as a single batch with the registration.</p>
            <label class="erp-label" for="registration_document_type_id">Document type for the batch</label>
            <select class="erp-input" id="registration_document_type_id" name="registration_document_type_id">
                <option value="">— Unclassified —</option>
                @foreach($registrationDocumentTypes as $type)
                    <option value="{{ $type['id'] }}">{{ $type['name'] }}</option>
                @endforeach
            </select>
            <label class="erp-label" for="registration_documents">Optional files</label>
            <input class="erp-file-input" id="registration_documents" name="registration_documents[]" type="file" multiple accept=".pdf,.jpg,.jpeg,.png">
        </div>
    </noscript>
</div>
