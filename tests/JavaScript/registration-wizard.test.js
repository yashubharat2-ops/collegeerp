import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { JSDOM } from 'jsdom';

const script = readFileSync(new URL('../../public/js/erp-registration-wizard.js', import.meta.url), 'utf8');

/**
 * A minimal step-1 / step-2 / step-3 page: enough markup for the wizard script's
 * progressive enhancements (same-as-permanent, guardian auto-fill, qualification
 * percentage, add/remove rows). The script is additive — the real form works
 * without it — so these tests only assert the conveniences.
 */
function createPage(t) {
    const dom = new JSDOM(`<!doctype html>
        <html><body>
            <div data-erp-wizard data-geo-url="/students/registration/geo/children" data-draft-url="/students/registration/step/1/draft">
                <form id="erp-step-form" data-erp-step-form>
                    <input name="first_name" value="Riya">
                    <input name="father_name" value="Suresh Verma">
                    <input name="father_phone" value="9222222222">
                    <input name="father_email" value="suresh@example.test">
                    <input name="father_occupation" value="Farmer">
                    <input data-erp-self="name" value="Riya Verma">
                    <input data-erp-self="phone" value="9111111111">
                    <input data-erp-self="email" value="riya@example.test">

                    <label class="erp-checkline">
                        <input type="checkbox" name="correspondence_same_as_permanent" value="1" data-erp-same-address checked>
                    </label>

                    <select name="country_id" data-geo-level="country" data-empty-label="— Select country —">
                        <option value="">— Select country —</option>
                        <option value="1" selected>India</option>
                    </select>
                    <select name="state_id" data-geo-level="state" data-empty-label="— Select state —">
                        <option value="">— Select state —</option>
                        <option value="2" selected>Madhya Pradesh</option>
                    </select>
                    <select name="district_id" data-geo-level="district" data-empty-label="— Select district —" disabled>
                        <option value="">— Select district —</option>
                    </select>
                    <div data-erp-correspondence hidden>
                        <input name="correspondence_address_line_1" disabled>
                    </div>

                    <select name="guardian_relation" data-erp-guardian-relation>
                        <option value="">— Not chosen yet —</option>
                        <option value="father">Father</option>
                        <option value="mother">Mother</option>
                        <option value="self">Self</option>
                        <option value="other">Other</option>
                    </select>
                    <p data-erp-guardian-note hidden></p>
                    <div class="erp-field"><input name="guardian_name" data-erp-guardian="name"></div>
                    <div class="erp-field"><input name="guardian_phone" data-erp-guardian="phone"></div>
                    <div class="erp-field"><input name="guardian_email" data-erp-guardian="email"></div>
                    <div class="erp-field"><input name="guardian_occupation" data-erp-guardian="occupation"></div>

                    <div data-erp-qualifications>
                        <div class="erp-qual-card" data-erp-qualification data-index="0">
                            <button type="button" data-erp-qualification-remove>Remove</button>
                            <select data-qual="level">
                                <option value="">— Select —</option>
                                <option value="class_10">Class 10</option>
                            </select>
                            <input data-qual="marks_obtained" value="430">
                            <input data-qual="max_marks" value="500">
                            <input data-qual="percentage" readonly>
                            <input data-qual="grade" value="">
                            <input data-qual="grade_letter" value="">
                        </div>
                    </div>
                    <template data-erp-qualification-template>
                        <div class="erp-qual-card" data-erp-qualification data-index="__INDEX__">
                            <button type="button" data-erp-qualification-remove>Remove</button>
                            <input data-qual="marks_obtained">
                            <input data-qual="max_marks">
                            <input data-qual="percentage" readonly>
                            <input data-qual="grade">
                            <input data-qual="grade_letter">
                        </div>
                    </template>
                    <button type="button" data-erp-qualification-add>Add qualification</button>
                    <p data-erp-qualifications-empty hidden>No qualification added yet.</p>

                    {{-- Step 4: the compact document picker. --}}
                    <ul data-erp-doc-picker>
                        <li>
                            <input type="checkbox" id="doc-type-1" value="1" data-erp-doc-toggle="1" checked>
                            <input type="checkbox" id="doc-type-2" value="2" data-erp-doc-toggle="2">
                            <input type="checkbox" id="doc-type-3" value="3" data-erp-doc-toggle="3">
                        </ul>

                        <div class="erp-doc-card" data-erp-doc-card="1">
                            <input type="file" name="file">
                        </div>
                        <div class="erp-doc-card" data-erp-doc-card="2" hidden>
                            <input type="file" name="file">
                        </div>
                        <div class="erp-doc-card erp-doc-card--attached" data-erp-doc-card="3" data-erp-doc-attached="true" hidden>
                            <input type="file" name="file">
                        </div>

                        <p data-erp-doc-empty hidden>Tick a document type above to attach its file.</p>
                </form>
            </div>
        </body></html>`, {
        url: 'http://localhost/students/registration/step/3',
        runScripts: 'outside-only',
    });

    t.after(() => dom.window.close());

    dom.window.fetch = () => Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ data: [] }) });
    dom.window.eval(script);

    return dom.window;
}

test('"Same as permanent address" hides and disables the correspondence block', (t) => {
    const window = createPage(t);
    const toggle = window.document.querySelector('[data-erp-same-address]');
    const block = window.document.querySelector('[data-erp-correspondence]');
    const field = block.querySelector('input');

    assert.equal(block.hidden, true);
    assert.equal(field.disabled, true);

    toggle.checked = false;
    toggle.dispatchEvent(new window.Event('change', { bubbles: true }));

    assert.equal(block.hidden, false);
    assert.equal(field.disabled, false);
});

test('choosing Father fills the guardian block from the father and locks the inputs', (t) => {
    const window = createPage(t);
    const relation = window.document.querySelector('[data-erp-guardian-relation]');

    relation.value = 'father';
    relation.dispatchEvent(new window.Event('change', { bubbles: true }));

    assert.equal(window.document.querySelector('[name="guardian_name"]').value, 'Suresh Verma');
    assert.equal(window.document.querySelector('[name="guardian_phone"]').value, '9222222222');
    assert.equal(window.document.querySelector('[name="guardian_email"]').value, 'suresh@example.test');
    assert.equal(window.document.querySelector('[name="guardian_occupation"]').value, 'Farmer');
    assert.equal(window.document.querySelector('[name="guardian_name"]').readOnly, true);
});

test('choosing Self uses the student\'s own details and choosing Other unlocks the fields', (t) => {
    const window = createPage(t);
    const relation = window.document.querySelector('[data-erp-guardian-relation]');

    relation.value = 'self';
    relation.dispatchEvent(new window.Event('change', { bubbles: true }));

    assert.equal(window.document.querySelector('[name="guardian_name"]').value, 'Riya Verma');
    assert.equal(window.document.querySelector('[name="guardian_occupation"]').value, 'Student');

    relation.value = 'other';
    relation.dispatchEvent(new window.Event('change', { bubbles: true }));

    assert.equal(window.document.querySelector('[name="guardian_name"]').readOnly, false);
    // The values already on screen are preserved, not blanked.
    assert.equal(window.document.querySelector('[name="guardian_name"]').value, 'Riya Verma');
});

test('percentage is derived from a valid marks pair and skipped for a grade-based result', (t) => {
    const window = createPage(t);
    const row = window.document.querySelector('[data-erp-qualification]');
    const percentage = row.querySelector('[data-qual="percentage"]');

    assert.equal(percentage.value, '86.00');

    const obtained = row.querySelector('[data-qual="marks_obtained"]');
    obtained.value = '600';
    obtained.dispatchEvent(new window.Event('input', { bubbles: true }));
    assert.equal(percentage.value, '', 'obtained > max must not produce a percentage');

    obtained.value = '450';
    obtained.dispatchEvent(new window.Event('input', { bubbles: true }));
    assert.equal(percentage.value, '90.00');

    const grade = row.querySelector('[data-qual="grade"]');
    grade.value = 'A1';
    grade.dispatchEvent(new window.Event('input', { bubbles: true }));
    assert.equal(percentage.value, '', 'a grade-based result keeps no derived percentage');
});

test('changing a parent clears the child selects and reports an empty directory', async (t) => {
    const window = createPage(t);
    const document = window.document;
    const requested = [];

    window.fetch = (url) => {
        requested.push(url);

        return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ data: [] }) });
    };

    const country = document.querySelector('[data-geo-level="country"]');
    const state = document.querySelector('[data-geo-level="state"]');
    const district = document.querySelector('[data-geo-level="district"]');

    // A resumed draft arrives with its saved chain already selected.
    assert.equal(state.value, '2');
    assert.equal(state.options.length, 2);

    country.value = '';
    country.dispatchEvent(new window.Event('change', { bubbles: true }));

    assert.equal(state.options.length, 1, 'the stale state option is cleared with its parent');
    assert.equal(state.value, '', 'the child selection is reset, never kept');
    assert.equal(district.disabled, true, 'grandchildren stay unavailable');

    await new Promise((resolve) => window.setTimeout(resolve, 0));

    assert.equal(requested.length, 0, 'a cleared parent asks for nothing');
});

test('loading an empty child level says so instead of showing a blank select', async (t) => {
    const window = createPage(t);
    const document = window.document;

    window.fetch = () => Promise.resolve({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ data: [] }),
    });

    const country = document.querySelector('[data-geo-level="country"]');
    const state = document.querySelector('[data-geo-level="state"]');

    country.value = '1';
    country.dispatchEvent(new window.Event('change', { bubbles: true }));

    await new Promise((resolve) => window.setTimeout(resolve, 0));

    assert.equal(state.disabled, false, 'the level is usable even when the directory is empty');
    assert.match(state.options[0].textContent, /No entries for/);
});

test('the document picker reveals and hides one upload card per ticked type', (t) => {
    const window = createPage(t);
    const card = (id) => window.document.querySelector(`[data-erp-doc-card="${id}"]`);
    const toggle = (id) => window.document.querySelector(`[data-erp-doc-toggle="${id}"]`);

    // Selected on load -> visible; not selected -> hidden.
    assert.equal(card(1).hidden, false);
    assert.equal(card(2).hidden, true);

    toggle(2).checked = true;
    toggle(2).dispatchEvent(new window.Event('change', { bubbles: true }));
    assert.equal(card(2).hidden, false, 'ticking a type shows its upload card');

    toggle(2).checked = false;
    toggle(2).dispatchEvent(new window.Event('change', { bubbles: true }));
    assert.equal(card(2).hidden, true, 'un-ticking hides the empty upload card');
});

test('an already attached document is never hidden by un-ticking its type', (t) => {
    const window = createPage(t);
    const toggle = window.document.querySelector('[data-erp-doc-toggle="3"]');
    const card = window.document.querySelector('[data-erp-doc-card="3"]');

    assert.equal(card.hidden, false, 'a card holding a file is shown even when its type starts un-ticked');

    toggle.checked = true;
    toggle.dispatchEvent(new window.Event('change', { bubbles: true }));
    toggle.checked = false;
    toggle.dispatchEvent(new window.Event('change', { bubbles: true }));

    assert.equal(card.hidden, false, 'the staged file stays reachable until it is explicitly removed');
});

test('the "tick a type" hint disappears once any type is selected', (t) => {
    const window = createPage(t);
    const hint = window.document.querySelector('[data-erp-doc-empty]');
    const toggles = [...window.document.querySelectorAll('[data-erp-doc-toggle]')];

    toggles.forEach((toggle) => { toggle.checked = false; });
    toggles.forEach((toggle) => toggle.dispatchEvent(new window.Event('change', { bubbles: true })));
    assert.equal(hint.hidden, false, 'with nothing selected the hint is shown');

    toggles[0].checked = true;
    toggles[0].dispatchEvent(new window.Event('change', { bubbles: true }));
    assert.equal(hint.hidden, true, 'with one type selected the hint is gone');
});

test('the empty qualification hint follows the number of rows', (t) => {
    const window = createPage(t);
    const hint = window.document.querySelector('[data-erp-qualifications-empty]');
    const add = window.document.querySelector('[data-erp-qualification-add]');
    const list = window.document.querySelector('[data-erp-qualifications]');

    // One row exists in the fixture, so the hint starts hidden.
    assert.equal(hint.hidden, true);

    add.click();
    assert.equal(hint.hidden, true, 'adding keeps it hidden');

    // Remove both rows (they carry no stored id, so they are detached).
    [...list.querySelectorAll('[data-erp-qualification-remove]')].forEach((button) => button.click());
    assert.equal(hint.hidden, false, 'removing every row brings the hint back');
});

test('a qualification row can be added and removed before saving', (t) => {
    const window = createPage(t);
    const list = window.document.querySelector('[data-erp-qualifications]');
    const add = window.document.querySelector('[data-erp-qualification-add]');

    add.click();

    const rows = list.querySelectorAll('[data-erp-qualification]');
    assert.equal(rows.length, 2);
    assert.equal(rows[1].getAttribute('data-index'), '1');

    rows[1].querySelector('[data-erp-qualification-remove]').click();
    assert.equal(list.querySelectorAll('[data-erp-qualification]').length, 1);
});
