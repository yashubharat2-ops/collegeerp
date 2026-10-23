import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { JSDOM } from 'jsdom';

const sidebarScript = readFileSync(new URL('../../public/js/erp-sidebar.js', import.meta.url), 'utf8');

function nestedGroup(id, label, childLabel) {
    return `
        <div class="nav-group nav-group--nested" data-nested-group="${id}" data-nav-open="false">
            <button type="button" class="nav-group__head nav-group__head--nested" id="nav-head-${id}"
                aria-expanded="false" aria-controls="nav-items-${id}">
                <div class="nav-group__label nav-group__label--nested">${label}</div>
                <span class="nav-group__chevron"></span>
            </button>
            <div class="nav-items nav-items--nested" id="nav-items-${id}" hidden>
                <a class="nav-link" href="/${id}">${childLabel}</a>
            </div>
        </div>`;
}

function topLevelGroup(id, label, items, open = true) {
    return `
        <li class="nav-group" data-nav-group="${id}" data-nav-open="${open}">
            <button type="button" class="nav-group__head" id="nav-head-${id}"
                aria-expanded="${open}" aria-controls="nav-items-${id}">
                <span class="nav-group__icon"></span>
                <div class="nav-group__label">${label}</div>
                <span class="nav-group__chevron"></span>
            </button>
            <div class="nav-items" id="nav-items-${id}" ${open ? '' : 'hidden'}>${items}</div>
        </li>`;
}

function createSidebar(t) {
    const admissionItems = `
        <a class="nav-link" href="/applications">Applications</a>
        ${nestedGroup('admission-transfers', 'Transfers', 'Transfer In')}
        ${nestedGroup('admission-settings', 'Settings', 'ID Card Setting')}`;
    const libraryItems = `
        ${nestedGroup('library-catalog', 'Catalog', 'Books')}
        ${nestedGroup('library-settings', 'Library Settings', 'Library Configuration')}`;
    const dom = new JSDOM(`<!doctype html>
        <html><body>
            <aside id="erp-sidebar" data-rail-default="false">
                <div class="erp-nav-search">
                    <input type="search" data-nav-search aria-label="Search menu items">
                    <button type="button" data-nav-search-clear hidden>Clear</button>
                </div>
                <div class="erp-nav__scroll">
                    <nav id="erp-nav" aria-label="College ERP modules">
                        <p data-nav-empty hidden>No matches</p>
                        <ul id="erp-nav-list">
                            ${topLevelGroup('admission', 'Admission', admissionItems)}
                            ${topLevelGroup('students', 'Students', '<a class="nav-link" href="/students">Students</a>', false)}
                            ${topLevelGroup('library', 'Library', libraryItems)}
                        </ul>
                    </nav>
                </div>
            </aside>
        </body></html>`, {
        url: 'http://localhost/dashboard',
        runScripts: 'outside-only',
    });

    t.after(() => dom.window.close());
    dom.window.matchMedia = () => ({
        matches: false,
        addEventListener() {},
        addListener() {},
    });
    dom.window.eval(sidebarScript);

    return dom.window;
}

function click(window, id) {
    window.document.getElementById(`nav-head-${id}`).click();
}

function assertOpen(window, id, open, nested = true) {
    const attribute = nested ? 'data-nested-group' : 'data-nav-group';
    const group = window.document.querySelector(`[${attribute}="${id}"]`);
    const head = group.querySelector('.nav-group__head');
    const items = group.querySelector('.nav-items');

    assert.equal(group.getAttribute('data-nav-open'), String(open), `${id} data-nav-open`);
    assert.equal(head.getAttribute('aria-expanded'), String(open), `${id} aria-expanded`);
    assert.equal(items.hasAttribute('hidden'), !open, `${id} hidden state`);
}

test('opening Transfers closes Settings without closing Admission', (t) => {
    const window = createSidebar(t);

    click(window, 'admission-settings');
    assertOpen(window, 'admission-settings', true);
    click(window, 'admission-transfers');

    assertOpen(window, 'admission-transfers', true);
    assertOpen(window, 'admission-settings', false);
    assertOpen(window, 'admission', true, false);
});

test('opening Settings closes Transfers without closing Admission', (t) => {
    const window = createSidebar(t);

    click(window, 'admission-transfers');
    assertOpen(window, 'admission-transfers', true);
    click(window, 'admission-settings');

    assertOpen(window, 'admission-settings', true);
    assertOpen(window, 'admission-transfers', false);
    assertOpen(window, 'admission', true, false);
});

test('clicking an expanded nested group closes it and preserves unrelated parent state', (t) => {
    const window = createSidebar(t);

    click(window, 'students');
    click(window, 'admission-transfers');
    click(window, 'admission-transfers');

    assertOpen(window, 'admission-transfers', false);
    assertOpen(window, 'admission-settings', false);
    assertOpen(window, 'admission', true, false);
    assertOpen(window, 'students', true, false);
});

test('ArrowRight uses the same accordion rule and ArrowLeft still collapses', (t) => {
    const window = createSidebar(t);
    const settingsHead = window.document.getElementById('nav-head-admission-settings');

    click(window, 'admission-transfers');
    const openEvent = new window.KeyboardEvent('keydown', {
        key: 'ArrowRight', bubbles: true, cancelable: true,
    });
    settingsHead.dispatchEvent(openEvent);

    assert.equal(openEvent.defaultPrevented, true);
    assertOpen(window, 'admission-settings', true);
    assertOpen(window, 'admission-transfers', false);

    const closeEvent = new window.KeyboardEvent('keydown', {
        key: 'ArrowLeft', bubbles: true, cancelable: true,
    });
    settingsHead.dispatchEvent(closeEvent);

    assert.equal(closeEvent.defaultPrevented, true);
    assertOpen(window, 'admission-settings', false);
    assertOpen(window, 'admission-transfers', false);
});

test('accordion siblings are scoped to their own parent module', (t) => {
    const window = createSidebar(t);

    click(window, 'admission-transfers');
    click(window, 'library-catalog');
    assertOpen(window, 'admission-transfers', true);
    assertOpen(window, 'library-catalog', true);

    click(window, 'library-settings');
    assertOpen(window, 'library-catalog', false);
    assertOpen(window, 'library-settings', true);
    assertOpen(window, 'admission-transfers', true);
    assertOpen(window, 'admission', true, false);
    assertOpen(window, 'library', true, false);
});

test('search reveals every match and clearing it restores the server accordion state', (t) => {
    const window = createSidebar(t);
    const search = window.document.querySelector('[data-nav-search]');

    // "in" matches both Transfer In and ID Card Setting. Filtering must not hide
    // one of those results merely because the interactive accordion is single-open.
    search.value = 'in';
    search.dispatchEvent(new window.Event('input', { bubbles: true }));

    assertOpen(window, 'admission-transfers', true);
    assertOpen(window, 'admission-settings', true);
    assert.equal(window.document.getElementById('erp-nav').getAttribute('data-nav-filtering'), 'true');

    window.document.querySelector('[data-nav-search-clear]').click();

    assertOpen(window, 'admission-transfers', false);
    assertOpen(window, 'admission-settings', false);
    assertOpen(window, 'admission', true, false);
    assert.equal(window.document.getElementById('erp-nav').hasAttribute('data-nav-filtering'), false);
});
