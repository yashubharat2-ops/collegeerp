(function () {
    'use strict';

    const form = document.querySelector('[data-registration-wizard]');
    if (!form) return;

    const page = form.closest('.erp-student-page');
    const sections = Array.from(form.querySelectorAll('[data-registration-step]'));
    const indicators = Array.from(form.querySelectorAll('[data-registration-indicator]'));
    const declarations = Array.from(form.querySelectorAll('[data-registration-declaration]'));
    const program = form.querySelector('[name="program_id"]');
    const feedback = form.querySelector('[data-registration-feedback]');
    const back = form.querySelector('[data-registration-back]');
    const next = form.querySelector('[data-registration-next]');
    const save = form.querySelector('[data-registration-save]');
    const submit = form.querySelector('[data-registration-submit]');
    const draftId = form.querySelector('[data-registration-draft-id]');
    const attachmentsBox = form.querySelector('[data-registration-attachments]');
    const attachmentList = form.querySelector('[data-registration-attachment-list]');
    const attachmentForm = form.querySelector('[data-registration-attachment-form]');
    const attachmentType = form.querySelector('[name="attachment_type_id"]');
    const attachmentTitle = form.querySelector('[name="attachment_title"]');
    const attachmentFile = form.querySelector('[name="attachment_file"]');
    const attachmentToken = form.querySelector('[data-attachment-token]');
    const attachmentError = form.querySelector('[data-attachment-error]');
    const attachmentAdd = form.querySelector('[data-attachment-add]');
    const attachmentCancel = form.querySelector('[data-attachment-cancel]');
    let attachments = [];
    let step = 1;
    let busy = false;
    let submitting = false;

    try { attachments = JSON.parse(attachmentsBox.dataset.initialAttachments || '[]'); } catch (error) { attachments = []; }
    if (!Array.isArray(attachments)) attachments = [];
    page.classList.add('erp-reg-js');

    function message(text, isError) {
        feedback.textContent = text || '';
        feedback.classList.toggle('is-error', !!isError);
    }

    function requiredDeclarations() {
        const programId = program ? program.value : '';
        declarations.forEach(function (checkbox) {
            let overrides = {};
            try { overrides = JSON.parse(checkbox.dataset.programRequirements || '{}'); } catch (error) { /* use college default */ }
            const required = Object.prototype.hasOwnProperty.call(overrides, programId)
                ? !!overrides[programId] : checkbox.dataset.defaultRequired === '1';
            checkbox.required = required;
            checkbox.setCustomValidity(required && !checkbox.checked
                ? 'Select ' + checkbox.dataset.docName + ' before continuing.' : '');
            checkbox.closest('.erp-reg-check-card').querySelector('[data-registration-required-label]').textContent = required ? 'Required' : 'Optional';
        });
    }

    function item(text) {
        const li = document.createElement('li');
        li.textContent = text;
        return li;
    }

    function selectText(name) {
        const select = form.querySelector('[name="' + name + '"]');
        return select && select.value && select.selectedOptions.length ? select.selectedOptions[0].textContent.trim() : '—';
    }

    function renderReview() {
        const name = ['first_name', 'middle_name', 'last_name'].map(function (key) {
            const input = form.querySelector('[name="' + key + '"]');
            return input ? input.value.trim() : '';
        }).filter(Boolean).join(' ');
        form.querySelector('[data-review-student]').textContent = name || '—';
        form.querySelector('[data-review-year]').textContent = selectText('academic_year_id');
        form.querySelector('[data-review-program]').textContent = selectText('program_id');
        const selected = declarations.filter(function (checkbox) { return checkbox.checked; });
        const declarationList = form.querySelector('[data-review-declarations]');
        declarationList.replaceChildren();
        if (!selected.length) declarationList.append(item('No document declarations selected.'));
        selected.forEach(function (checkbox) { declarationList.append(item(checkbox.dataset.docName)); });
        const files = form.querySelector('[data-review-attachments]');
        files.replaceChildren();
        if (!attachments.length) files.append(item('No files attached.'));
        attachments.forEach(function (file) { files.append(item(file.type_name + ' — ' + file.filename + ' · ' + file.status + ' · Not verified')); });
    }

    function showStep(number) {
        step = number;
        form.querySelector('[data-registration-step-counter]').textContent = 'Step ' + step + ' of 4';
        sections.forEach(function (section) { section.hidden = Number(section.dataset.registrationStep) !== step; });
        indicators.forEach(function (indicator) {
            const active = Number(indicator.dataset.registrationIndicator) === step;
            indicator.classList.toggle('is-active', active);
            if (active) indicator.setAttribute('aria-current', 'step'); else indicator.removeAttribute('aria-current');
        });
        back.hidden = step === 1;
        next.hidden = step === 4;
        submit.hidden = step !== 4;
        next.textContent = step === 3 ? 'Continue to final review' : 'Continue';
        if (step === 4) renderReview();
    }

    function renderAttachments() {
        attachmentList.replaceChildren();
        if (!attachments.length) {
            const empty = document.createElement('p');
            empty.className = 'erp-help';
            empty.textContent = 'No files attached.';
            attachmentList.append(empty);
            return;
        }
        attachments.forEach(function (file) {
            const row = document.createElement('div');
            row.className = 'erp-reg-file';
            const details = document.createElement('div');
            const type = document.createElement('strong');
            type.textContent = file.type_name;
            const name = document.createElement('span');
            name.textContent = file.filename;
            details.append(type);
            if (file.title && file.title !== file.filename) {
                const title = document.createElement('span');
                title.textContent = file.title;
                details.append(title);
            }
            const status = document.createElement('small');
            status.textContent = file.status + ' · Not verified';
            const remove = document.createElement('button');
            remove.className = 'erp-reg-remove';
            remove.type = 'button';
            remove.dataset.removeAttachment = file.id;
            remove.textContent = 'Remove';
            remove.setAttribute('aria-label', 'Remove ' + file.filename);
            details.append(name, status);
            row.append(details, remove);
            attachmentList.append(row);
        });
    }

    function setBusy(value) {
        busy = value;
        [save, next, back, submit, attachmentAdd, attachmentCancel].forEach(function (button) { button.disabled = value; });
        form.querySelectorAll('[data-remove-attachment]').forEach(function (button) { button.disabled = value; });
    }

    async function send(url, includeFile) {
        if (busy) return null;
        setBusy(true);
        try {
            const data = new FormData(form);
            // Portrait selection is submitted normally, not stored in the draft.
            data.delete('photo');
            if (!includeFile) data.delete('attachment_file');
            const response = await fetch(url, { method: 'POST', body: data, headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
            let result;
            try { result = await response.json(); } catch (error) { throw new Error('Unable to save the draft. Please try again.'); }
            if (!response.ok || !result.ok) {
                const errors = result.errors ? Object.values(result.errors).flat() : [];
                throw new Error(errors[0] || result.message || 'Unable to save the draft. Please try again.');
            }
            draftId.value = form.dataset.draftId;
            const address = new URL(window.location.href);
            address.searchParams.set('draft', form.dataset.draftId);
            window.history.replaceState(null, '', address);
            if (Array.isArray(result.attachments)) {
                attachments = result.attachments;
                renderAttachments();
            }
            form.querySelectorAll('[name="registration_clear_aadhaar"], [name="registration_clear_govt_id"]').forEach(function (control) { control.checked = false; });
            return result;
        } finally {
            setBusy(false);
        }
    }

    function validateStep(number) {
        const fields = sections.filter(function (section) { return Number(section.dataset.registrationStep) === number; })
            .flatMap(function (section) { return Array.from(section.querySelectorAll('input, select, textarea')); });
        const invalid = fields.find(function (field) { return field.willValidate && !field.checkValidity(); });
        if (invalid) {
            invalid.reportValidity();
            return false;
        }
        return true;
    }

    if (program) program.addEventListener('change', requiredDeclarations);
    declarations.forEach(function (checkbox) { checkbox.addEventListener('change', requiredDeclarations); });
    form.querySelector('[name="registration_clear_aadhaar"]').addEventListener('change', function () {
        if (this.checked) form.querySelector('[name="aadhaar_number"]').value = '';
    });
    form.querySelector('[name="registration_clear_govt_id"]').addEventListener('change', function () {
        if (this.checked) {
            form.querySelector('[name="govt_id_number"]').value = '';
            form.querySelector('[name="govt_id_type"]').value = '';
        }
    });
    requiredDeclarations();

    next.addEventListener('click', async function () {
        message('');
        requiredDeclarations();
        if (!validateStep(step)) return;
        if (step < 3) {
            showStep(step + 1);
            return;
        }
        try {
            const result = await send(form.dataset.reviewUrl, false);
            if (result) showStep(4);
        } catch (error) { message(error.message, true); }
    });

    back.addEventListener('click', function () { message(''); if (!busy && step > 1) showStep(step - 1); });
    save.addEventListener('click', async function () {
        try {
            const result = await send(form.dataset.saveUrl, false);
            if (result) message('Draft saved. You can resume it from Student Registration. Portraits must be reselected after resuming.');
        } catch (error) { message(error.message, true); }
    });

    attachmentCancel.addEventListener('click', function () {
        attachmentForm.open = false;
        attachmentType.value = '';
        attachmentTitle.value = '';
        attachmentFile.value = '';
        attachmentError.textContent = '';
    });
    attachmentAdd.addEventListener('click', async function () {
        attachmentError.textContent = '';
        if (!attachmentFile.files.length) {
            attachmentError.textContent = 'Select a file to attach, or cancel without adding one.';
            return;
        }
        try {
            const result = await send(form.dataset.attachUrl, true);
            if (!result) return;
            attachmentToken.value = result.next_token;
            attachmentType.value = '';
            attachmentTitle.value = '';
            attachmentFile.value = '';
            attachmentForm.open = false;
            message('File attached to the draft. It has not been verified.');
        } catch (error) { attachmentError.textContent = error.message; }
    });

    attachmentList.addEventListener('click', async function (event) {
        const button = event.target.closest('[data-remove-attachment]');
        if (!button || busy || !window.confirm('Remove this attachment from the draft?')) return;
        try {
            const url = form.dataset.removeUrl.replace('__ATTACHMENT__', encodeURIComponent(button.dataset.removeAttachment));
            const result = await send(url, false);
            if (result) message('Attachment removed from the draft.');
        } catch (error) { message(error.message, true); }
    });

    // Bring a hidden step into view if native validation blocks final submit.
    form.addEventListener('invalid', function (event) {
        const section = event.target.closest('[data-registration-step]');
        if (section) showStep(Number(section.dataset.registrationStep));
    }, true);
    form.addEventListener('submit', function (event) {
        if (busy || submitting || step !== 4) {
            event.preventDefault();
            return;
        }
        submitting = true;
        // An un-added file is never silently treated as an attachment.
        attachmentFile.disabled = true;
        submit.disabled = true;
    });

    renderAttachments();
    const firstError = Array.from(form.querySelectorAll('.erp-error')).find(function (element) { return element.textContent.trim(); });
    const errorSection = firstError && firstError.closest('[data-registration-step]');
    showStep(errorSection ? Number(errorSection.dataset.registrationStep) : 1);
})();
