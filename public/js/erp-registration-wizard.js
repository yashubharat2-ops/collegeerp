/*
 * Student registration wizard — progressive enhancement only.
 *
 * Everything this file does is a convenience layered on top of a form that
 * already works without JavaScript:
 *
 *  1. dependent address selects (Country → State → District → Block → Village);
 *  2. "Same as permanent address" for the correspondence block;
 *  3. guardian auto-fill from Father / Mother / Self, and normal editable fields
 *     for "Other";
 *  4. the repeating qualification table (add / remove) with a derived
 *     percentage that never overwrites a grade-based result, and the document
 *     picker that reveals one upload card per ticked document type;
 *  5. image preview before upload;
 *  6. an idle auto-save of the current step as a DRAFT, plus a leave-page guard
 *     so nothing typed is lost silently.
 *
 * The server re-validates everything (including the address chain and the
 * guardian derivation), so a browser with this file blocked still produces a
 * correct submission.
 */
(function () {
    'use strict';

    var wizard = document.querySelector('[data-erp-wizard]');

    if (!wizard) {
        return;
    }

    var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    var geoUrl = wizard.getAttribute('data-geo-url') || '';
    var draftUrl = wizard.getAttribute('data-draft-url') || '';
    var dirty = false;

    function markDirty() {
        dirty = true;
        var status = document.querySelector('[data-erp-autosave-state]');
        if (status) {
            status.textContent = 'Unsaved changes';
        }
    }

    /* ------------------------------------------------------------------
     * 1. Dependent address cascade
     * ------------------------------------------------------------------ */

    var LEVELS = ['country', 'state', 'district', 'block', 'village'];
    var ID_FIELDS = {
        country: 'country_id',
        state: 'state_id',
        district: 'district_id',
        block: 'block_id',
        village: 'village_id'
    };

    function selectFor(level) {
        return wizard.querySelector('[data-geo-level="' + level + '"]');
    }

    function clearFrom(level) {
        var index = LEVELS.indexOf(level);

        for (var i = index + 1; i < LEVELS.length; i++) {
            var select = selectFor(LEVELS[i]);

            if (!select) {
                continue;
            }

            while (select.options.length > 1) {
                select.remove(1);
            }

            select.value = '';
            select.setAttribute('disabled', 'disabled');

            var empty = select.getAttribute('data-empty-label') || '— Select —';
            select.options[0].textContent = empty;
        }
    }

    function loadChildren(level, parentId) {
        var select = selectFor(level);

        if (!select || !geoUrl) {
            return;
        }

        var url = geoUrl + '?level=' + encodeURIComponent(level);

        if (parentId) {
            url += '&parent_id=' + encodeURIComponent(parentId);
        }

        select.setAttribute('aria-busy', 'true');

        fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('geo ' + response.status);
                }

                return response.json();
            })
            .then(function (payload) {
                var nodes = payload.data || [];

                while (select.options.length > 1) {
                    select.remove(1);
                }

                nodes.forEach(function (node) {
                    var option = document.createElement('option');
                    option.value = String(node.id);
                    option.textContent = node.name;
                    select.appendChild(option);
                });

                // An empty level is said out loud instead of showing a blank
                // closed select that looks broken.
                var label = select.getAttribute('data-empty-label') || '— Select —';
                select.options[0].textContent = nodes.length === 0
                    ? label.replace('Select', 'No entries for')
                    : label;

                select.removeAttribute('disabled');
                select.removeAttribute('aria-busy');
            })
            .catch(function () {
                // A failed request must not leave a silently empty dropdown.
                while (select.options.length > 1) {
                    select.remove(1);
                }

                select.options[0].textContent = 'Could not load — try again';
                select.setAttribute('disabled', 'disabled');
                select.removeAttribute('aria-busy');
            });
    }

    LEVELS.forEach(function (level) {
        var select = selectFor(level);

        if (!select) {
            return;
        }

        select.addEventListener('change', function () {
            markDirty();
            clearFrom(level);

            var next = LEVELS[LEVELS.indexOf(level) + 1];

            if (next && select.value) {
                loadChildren(next, select.value);
            }
        });
    });

    /* ------------------------------------------------------------------
     * 2. Correspondence address — "Same as permanent"
     * ------------------------------------------------------------------ */

    var sameToggle = wizard.querySelector('[data-erp-same-address]');
    var correspondence = wizard.querySelector('[data-erp-correspondence]');

    function syncCorrespondence() {
        if (!sameToggle || !correspondence) {
            return;
        }

        var same = sameToggle.checked;
        correspondence.hidden = same;

        Array.prototype.forEach.call(correspondence.querySelectorAll('input, select, textarea'), function (input) {
            input.disabled = same;
        });
    }

    if (sameToggle) {
        sameToggle.addEventListener('change', function () {
            markDirty();
            syncCorrespondence();
        });
        syncCorrespondence();
    }

    /* ------------------------------------------------------------------
     * 3. Guardian auto-fill
     * ------------------------------------------------------------------ */

    var guardianRelation = wizard.querySelector('[data-erp-guardian-relation]');
    var guardianFields = {
        name: wizard.querySelector('[data-erp-guardian="name"]'),
        phone: wizard.querySelector('[data-erp-guardian="phone"]'),
        email: wizard.querySelector('[data-erp-guardian="email"]'),
        occupation: wizard.querySelector('[data-erp-guardian="occupation"]')
    };
    var sources = {
        father: {
            name: wizard.querySelector('[name="father_name"]'),
            phone: wizard.querySelector('[name="father_phone"]'),
            email: wizard.querySelector('[name="father_email"]'),
            occupation: wizard.querySelector('[name="father_occupation"]')
        },
        mother: {
            name: wizard.querySelector('[name="mother_name"]'),
            phone: wizard.querySelector('[name="mother_phone"]'),
            email: wizard.querySelector('[name="mother_email"]'),
            occupation: wizard.querySelector('[name="mother_occupation"]')
        },
        self: {
            name: wizard.querySelector('[data-erp-self="name"]'),
            phone: wizard.querySelector('[data-erp-self="phone"]'),
            email: wizard.querySelector('[data-erp-self="email"]'),
            occupation: null
        }
    };

    /*
     * The "Self" source is carried by hidden inputs, so the derivation also
     * works when the personal fields are not on the page. Parent/Guardian used
     * to be its own step; it now lives in Basic Information, so those hidden
     * values are kept in step with what the applicant is actually typing.
     */
    var selfSources = {
        name: wizard.querySelector('[data-erp-self="name"]'),
        phone: wizard.querySelector('[data-erp-self="phone"]'),
        email: wizard.querySelector('[data-erp-self="email"]')
    };

    function syncSelfSources() {
        var parts = ['first_name', 'middle_name', 'last_name'].map(function (field) {
            var input = wizard.querySelector('[name="' + field + '"]');

            return input && typeof input.value === 'string' ? input.value.trim() : '';
        }).filter(function (part) { return part !== ''; });

        if (selfSources.name) {
            selfSources.name.value = parts.join(' ');
        }

        ['phone', 'email'].forEach(function (key) {
            var input = wizard.querySelector('[name="' + key + '"]');

            if (selfSources[key] && input) {
                selfSources[key].value = input.value || '';
            }
        });
    }

    ['first_name', 'middle_name', 'last_name', 'phone', 'email'].forEach(function (field) {
        var input = wizard.querySelector('[name="' + field + '"]');

        if (!input) {
            return;
        }

        input.addEventListener('input', function () {
            syncSelfSources();

            var relation = wizard.querySelector('[data-erp-guardian-relation]');

            if (relation && relation.value === 'self') {
                applyGuardian('self');
            }
        });
    });

    syncSelfSources();

    var derivedNote = wizard.querySelector('[data-erp-guardian-note]');

    function applyGuardian(relation) {
        var source = sources[relation];
        var derived = !!source;

        Object.keys(guardianFields).forEach(function (key) {
            var field = guardianFields[key];

            if (!field) {
                return;
            }

            var wrapper = field.closest('.erp-field');

            if (derived) {
                var value = '';

                if (source[key]) {
                    value = source[key].value || '';
                } else if (key === 'occupation' && relation === 'self') {
                    value = 'Student';
                }

                field.value = value;
                field.readOnly = true;
                field.setAttribute('tabindex', '-1');

                if (wrapper) {
                    wrapper.setAttribute('data-autofilled', 'true');
                }
            } else {
                field.readOnly = false;
                field.removeAttribute('tabindex');

                if (wrapper) {
                    wrapper.removeAttribute('data-autofilled');
                }
            }
        });

        if (derivedNote) {
            if (relation === 'father' || relation === 'mother') {
                derivedNote.textContent = 'Filled from the ' + relation + '\'s details above and kept in step with them. Choose "Other" to enter different guardian details.';
            } else if (relation === 'self') {
                derivedNote.textContent = 'The student is their own guardian: the name, mobile and email come from the Basic Information step.';
            } else {
                derivedNote.textContent = 'Enter the guardian\'s details. These are maintained separately from the father and mother records.';
            }
            derivedNote.hidden = false;
        }
    }

    if (guardianRelation) {
        // Pre-fill from the source ONLY when the user changes the selection:
        // an independent guardian block that was typed under "Other" is never
        // overwritten just because the page reloaded.
        guardianRelation.addEventListener('change', function () {
            markDirty();
            applyGuardian(guardianRelation.value);
        });

        // Keep a derived value in step with its source while typing.
        Object.keys(sources).forEach(function (relation) {
            Object.keys(sources[relation]).forEach(function (key) {
                var input = sources[relation][key];

                if (!input || !guardianRelation || guardianRelation.value !== relation) {
                    return;
                }

                input.addEventListener('input', function () {
                    if (guardianRelation.value === relation) {
                        applyGuardian(relation);
                    }
                });
            });
        });

        applyGuardian(guardianRelation.value);
    }

    /* ------------------------------------------------------------------
     * 4. Qualification rows
     * ------------------------------------------------------------------ */

    var qualList = wizard.querySelector('[data-erp-qualifications]');
    var qualTemplate = wizard.querySelector('[data-erp-qualification-template]');
    var qualAdd = wizard.querySelector('[data-erp-qualification-add]');

    function nextQualIndex() {
        var rows = qualList ? qualList.querySelectorAll('[data-erp-qualification]') : [];
        var max = -1;

        Array.prototype.forEach.call(rows, function (row) {
            var index = parseInt(row.getAttribute('data-index'), 10);
            if (!isNaN(index) && index > max) {
                max = index;
            }
        });

        return max + 1;
    }

    function calculatePercentage(row) {
        var obtained = row.querySelector('[data-qual="marks_obtained"]');
        var max = row.querySelector('[data-qual="max_marks"]');
        var percentage = row.querySelector('[data-qual="percentage"]');
        var grade = row.querySelector('[data-qual="grade"]');
        var gradeLetter = row.querySelector('[data-qual="grade_letter"]');

        if (!obtained || !max || !percentage) {
            return;
        }

        var graded = (grade && grade.value.trim() !== '') || (gradeLetter && gradeLetter.value.trim() !== '');

        if (graded) {
            // A grade-based result keeps the grade the board issued; no derived
            // percentage is shown or submitted.
            percentage.readOnly = true;
            percentage.value = '';
            percentage.setAttribute('placeholder', 'Not used for a grade-based result');
            return;
        }

        percentage.removeAttribute('placeholder');
        percentage.readOnly = true;

        var o = parseFloat(obtained.value);
        var m = parseFloat(max.value);

        if (!isNaN(o) && !isNaN(m) && m > 0 && o <= m) {
            percentage.value = (Math.round((o / m) * 100 * 100) / 100).toFixed(2);
        } else {
            percentage.value = '';
        }
    }

    function wireQualRow(row) {
        ['marks_obtained', 'max_marks', 'grade', 'grade_letter'].forEach(function (field) {
            var input = row.querySelector('[data-qual="' + field + '"]');

            if (input) {
                input.addEventListener('input', function () {
                    markDirty();
                    calculatePercentage(row);
                });
            }
        });

        var remove = row.querySelector('[data-erp-qualification-remove]');

        if (remove) {
            remove.addEventListener('click', function (event) {
                event.preventDefault();
                markDirty();

                // An existing row carries a hidden id: removing it must tell the
                // server to delete that qualification, so the row is hidden and
                // flagged instead of simply being detached from the DOM.
                var id = row.querySelector('input[name$="[id]"]');

                if (id && id.value) {
                    row.setAttribute('hidden', 'hidden');
                    var flag = document.createElement('input');
                    flag.type = 'hidden';
                    flag.name = id.name.replace('[id]', '[remove]');
                    flag.value = '1';
                    row.appendChild(flag);
                } else {
                    row.parentNode.removeChild(row);
                }

                syncQualEmptyState();
            });
        }

        var level = row.querySelector('[data-qual="level"]');

        if (level) {
            level.addEventListener('change', function () { markDirty(); });
        }

        calculatePercentage(row);
    }

    var qualEmpty = wizard.querySelector('[data-erp-qualifications-empty]');

    function syncQualEmptyState() {
        if (!qualEmpty || !qualList) {
            return;
        }

        // Hidden rows are already flagged for deletion, so they do not count as
        // "a qualification has been added".
        var visible = Array.prototype.filter.call(
            qualList.querySelectorAll('[data-erp-qualification]'),
            function (row) { return !row.hasAttribute('hidden'); }
        );

        qualEmpty.hidden = visible.length > 0;
    }

    if (qualList) {
        Array.prototype.forEach.call(qualList.querySelectorAll('[data-erp-qualification]'), wireQualRow);

        if (qualAdd && qualTemplate) {
            qualAdd.addEventListener('click', function (event) {
                event.preventDefault();
                markDirty();

                var index = nextQualIndex();
                var html = qualTemplate.innerHTML.replace(/__INDEX__/g, String(index));
                var wrapper = document.createElement('div');
                wrapper.innerHTML = html.trim();
                var row = wrapper.firstElementChild;

                if (!row) {
                    return;
                }

                row.setAttribute('data-index', String(index));
                qualList.appendChild(row);
                wireQualRow(row);
                syncQualEmptyState();

                var first = row.querySelector('select, input');
                if (first) {
                    first.focus();
                }
            });
        }
    }

    syncQualEmptyState();

    // A removed (hidden) qualification must not block submission with its
    // required fields, so hidden rows are excluded by the browser automatically
    // once hidden inputs are disabled — which we do on submit.
    wizard.addEventListener('submit', function () {
        Array.prototype.forEach.call(wizard.querySelectorAll('[data-erp-qualification][hidden]'), function (row) {
            Array.prototype.forEach.call(row.querySelectorAll('input, select, textarea'), function (input) {
                if (input.name && input.name.indexOf('[remove]') === -1) {
                    input.disabled = true;
                }
            });
        });
    });

    /* ------------------------------------------------------------------
     * 4b. Document picker — the checkbox grid reveals its upload card
     *
     * Selection is a UI filter only: the server decides what is required from
     * the STORED files and the course configuration, so unticking a box can
     * never make a mandatory document disappear from the submission check. A
     * card that already holds a file is never hidden — deselecting must not
     * look like it discarded the upload.
     * ------------------------------------------------------------------ */

    var docToggles = Array.prototype.slice.call(wizard.querySelectorAll('[data-erp-doc-toggle]'));
    var docEmpty = wizard.querySelector('[data-erp-doc-empty]');

    function syncDocCards() {
        var anySelected = false;

        docToggles.forEach(function (toggle) {
            var id = toggle.getAttribute('data-erp-doc-toggle');
            var card = wizard.querySelector('[data-erp-doc-card="' + id + '"]');
            var selected = toggle.checked || toggle.disabled;

            if (selected) {
                anySelected = true;
            }

            if (!card) {
                return;
            }

            var attached = card.getAttribute('data-erp-doc-attached') === 'true';

            if (selected || attached) {
                card.removeAttribute('hidden');
            } else {
                card.setAttribute('hidden', 'hidden');
            }
        });

        if (docEmpty) {
            docEmpty.hidden = anySelected || docToggles.length === 0;
        }
    }

    docToggles.forEach(function (toggle) {
        // A selection toggle changes nothing on the server (uploads are their own
        // form), so it must not raise the "unsaved changes" guard.
        toggle.addEventListener('change', syncDocCards);
    });

    syncDocCards();

    /* ------------------------------------------------------------------
     * 5. Image preview
     * ------------------------------------------------------------------ */

    Array.prototype.forEach.call(wizard.querySelectorAll('[data-erp-image-input]'), function (input) {
        input.addEventListener('change', function () {
            markDirty();

            var targetId = input.getAttribute('data-erp-image-input');
            var preview = document.getElementById(targetId);

            if (!preview || !input.files || !input.files[0]) {
                return;
            }

            var file = input.files[0];

            if (!/^image\/(jpeg|png|webp)$/.test(file.type)) {
                return;
            }

            var url = URL.createObjectURL(file);
            preview.innerHTML = '';
            var image = document.createElement('img');
            image.src = url;
            image.alt = 'Selected image preview';
            image.onload = function () { URL.revokeObjectURL(url); };
            preview.appendChild(image);
        });
    });

    /* ------------------------------------------------------------------
     * 6. Mark dirty + idle draft auto-save
     * ------------------------------------------------------------------ */

    wizard.addEventListener('input', markDirty);
    wizard.addEventListener('change', markDirty);

    window.addEventListener('beforeunload', function (event) {
        if (!dirty) {
            return;
        }

        event.preventDefault();
        event.returnValue = '';
    });

    var draftForm = wizard.querySelector('[data-erp-step-form]');
    var state = document.querySelector('[data-erp-autosave-state]');

    if (!draftForm || !draftUrl) {
        return;
    }

    // An explicit save/navigation clears the guard.
    Array.prototype.forEach.call(wizard.querySelectorAll('[data-erp-save-intent]'), function (button) {
        button.addEventListener('click', function () {
            dirty = false;
        });
    });

    function autoSave() {
        if (!dirty) {
            return;
        }

        var formData = new FormData(draftForm);

        // A partially typed identity number is not a valid Aadhaar; leaving it
        // out of the auto-save keeps the last valid value instead of failing.
        ['aadhaar_number'].forEach(function (field) {
            var value = formData.get(field);

            if (typeof value === 'string' && value.replace(/\D/g, '').length !== 12) {
                formData.delete(field);
            }
        });

        fetch(draftUrl, {
            method: 'POST',
            body: formData,
            headers: {
                'X-CSRF-TOKEN': csrf,
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'text/html'
            },
            credentials: 'same-origin'
        }).then(function (response) {
            if (response.ok || (response.status >= 300 && response.status < 400)) {
                dirty = false;

                if (state) {
                    state.textContent = 'Draft saved automatically';
                }
            }
        }).catch(function () {
            // Auto-save is best effort; the explicit Save draft button remains.
        });
    }

    window.setInterval(autoSave, 60000);
})();
