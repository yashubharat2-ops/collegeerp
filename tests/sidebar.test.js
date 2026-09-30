import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';

const script = readFileSync(new URL('../public/js/sidebar.js', import.meta.url), 'utf8');

function classes() {
    const values = new Set();
    return {
        add: (...names) => names.forEach((name) => values.add(name)),
        contains: (name) => values.has(name),
        toggle: (name, force) => { if (force) values.add(name); else values.delete(name); },
    };
}

function section(urls, currentLink = null, label = 'Module') {
    const attrs = new Map();
    const summary = {
        title: '',
        querySelector: () => ({ textContent: label }),
        removeAttribute: (key) => { if (key === 'title') summary.title = ''; },
    };
    const links = urls.map((href) => {
        const attributes = new Map(currentLink === href ? [['aria-current', 'page']] : []);
        return {
            href: `https://erp.test${href}`,
            textContent: href.replace(/[/?=-]/g, ' '),
            hidden: false,
            getAttribute: (key) => attributes.get(key) ?? null,
            hasAttribute: (key) => attributes.has(key),
            setAttribute: (key, value) => attributes.set(key, value),
            classList: classes(),
        };
    });
    return {
        open: false, links, summary,
        querySelectorAll: () => links,
        querySelector: (selector) => selector === 'summary' ? summary : { textContent: label },
        setAttribute: (key, value) => attrs.set(key, value),
        removeAttribute: (key) => attrs.delete(key),
        hasAttribute: (key) => attrs.has(key),
    };
}

function load(path, sections, { isDesktop = true } = {}) {
    const handlers = {};
    const attributes = new Map();
    const text = { textContent: 'Collapse sidebar' };
    const toggle = {
        setAttribute: (key, value) => attributes.set(key, value),
        getAttribute: (key) => attributes.get(key),
        hasAttribute: (key) => attributes.has(key),
        querySelector: () => text,
        addEventListener: (key, callback) => { handlers[`toggle-${key}`] = callback; },
        focus: () => { toggle.focused = true; },
    };
    const sidebar = { hidden: true, classList: classes(), addEventListener: (key, callback) => { handlers[`sidebar-${key}`] = callback; } };
    const backdrop = { hidden: true, addEventListener: (key, callback) => { handlers[`backdrop-${key}`] = callback; } };
    const desktop = { matches: isDesktop, addEventListener: (key, callback) => { handlers[`desktop-${key}`] = callback; } };
    const search = { value: '', addEventListener: (key, callback) => { handlers[`search-${key}`] = callback; } };
    const dashboard = { hidden: false, textContent: 'Dashboard' };
    const noResults = { hidden: true };
    vm.runInNewContext(script, {
        URL,
        window: {
            location: { href: `https://erp.test${path}` },
            matchMedia: () => desktop,
            addEventListener: (key, callback) => { handlers[key] = callback; },
        },
        document: {
            querySelectorAll: (selector) => selector === '[data-sidebar-toggle]' ? [toggle] : sections,
            getElementById: () => sidebar,
            querySelector: (selector) => ({
                '[data-sidebar-backdrop]': backdrop,
                '[data-sidebar-search]': search,
                '.sidebar-dashboard': dashboard,
                '[data-sidebar-no-results]': noResults,
            })[selector] ?? null,
            body: { classList: classes() },
        },
    });
    return { handlers, toggle, sidebar, backdrop, desktop, text, search, dashboard, noResults };
}

test('dashboard starts closed, even if the browser restores old open states', () => {
    const students = section(['/students']);
    const hostel = section(['/hostels/dashboard']);
    students.open = true;
    hostel.open = true;
    const { handlers } = load('/dashboard', [students, hostel]);
    assert.equal(students.open, false);
    assert.equal(hostel.open, false);
    hostel.open = true;
    handlers.pageshow();
    assert.equal(hostel.open, false);
});

test('administration alone opens and gets an active parent, not a restored Hostel', () => {
    const hostel = section(['/hostels/dashboard']);
    const reports = section(['/hostel-reports']);
    const admin = section(['/admin/users', '/admin/roles'], '/admin/roles');
    hostel.open = true;
    const { handlers } = load('/admin/roles/3/edit', [hostel, reports, admin]);
    assert.equal(admin.open, true);
    assert.equal(admin.hasAttribute('data-current-section'), true);
    assert.equal(hostel.open, false);
    assert.equal(reports.open, false);
    assert.equal(admin.links[1].getAttribute('aria-current'), 'page');
    hostel.open = true;
    handlers.pageshow();
    assert.equal(hostel.open, false);
    assert.equal(admin.open, true);
});

test('navigating opens only the new route parent; clicking another section leaves both available', () => {
    const students = section(['/students']);
    const reports = section(['/student-reports', '/finance-reports']);
    load('/students/42/edit', [students, reports]);
    assert.equal(students.open, true);
    assert.equal(reports.open, false);
    reports.open = true; // Native summary toggling is independent.
    assert.equal(students.open, true);
    const nextStudents = section(['/students']);
    const nextReports = section(['/student-reports', '/finance-reports']);
    load('/student-reports?status=active', [nextStudents, nextReports]);
    assert.equal(nextStudents.open, false);
    assert.equal(nextReports.open, true);
    assert.equal(nextReports.links[0].getAttribute('aria-current'), 'page');
    assert.ok(nextReports.links[0].classList.contains('bg-indigo-600'));
});

test('filtered certificate links choose the matching child without mis-highlighting unlinked pages', () => {
    const certs = section(['/certificates/requests?type=TC', '/certificates/requests?type=BON']);
    load('/certificates/requests?type=BON&status=pending', [certs]);
    assert.equal(certs.links[0].hasAttribute('aria-current'), false);
    assert.equal(certs.links[1].getAttribute('aria-current'), 'page');
    const detail = section(['/certificates/requests?type=TC']);
    load('/certificates/123', [detail]);
    assert.equal(detail.open, true);
    assert.equal(detail.links[0].hasAttribute('aria-current'), false);
});

test('desktop collapse keeps modules available; clicking an icon expands its child links', () => {
    const exams = section(['/exam-marks'], null, 'Examinations'); // Other RBAC-hidden sections are not present.
    const { handlers, sidebar, toggle, text } = load('/exam-marks', [exams]);
    assert.equal(exams.summary.title, '');
    handlers['toggle-click']();
    assert.equal(sidebar.hidden, false); // Clear legacy persisted [hidden] state.
    assert.equal(sidebar.classList.contains('is-collapsed'), true);
    assert.equal(exams.summary.title, 'Examinations');
    assert.equal(exams.links.length, 1);
    assert.equal(toggle.getAttribute('aria-expanded'), 'false');
    assert.equal(text.textContent, 'Expand sidebar');
    const summary = { parentElement: exams };
    let prevented = false;
    handlers['sidebar-click']({ target: { closest: () => summary }, preventDefault: () => { prevented = true; } });
    assert.equal(prevented, true);
    assert.equal(sidebar.classList.contains('is-collapsed'), false);
    assert.equal(exams.summary.title, '');
    assert.equal(exams.open, true);
});

test('mobile drawer toggles, backdrop and Escape close it, shortcut remains accessible', () => {
    const { handlers, sidebar, backdrop, toggle, text, desktop } = load('/dashboard', [], { isDesktop: false });
    assert.equal(sidebar.inert, true);
    assert.equal(backdrop.hidden, true);
    assert.equal(toggle.getAttribute('aria-label'), 'Show sidebar');
    handlers['toggle-click']();
    assert.equal(sidebar.classList.contains('is-mobile-open'), true);
    assert.equal(sidebar.inert, false);
    assert.equal(backdrop.hidden, false);
    assert.equal(text.textContent, 'Hide sidebar');
    handlers.keydown({ key: 'Escape', altKey: false, ctrlKey: false });
    assert.equal(backdrop.hidden, true);
    assert.equal(toggle.focused, true);
    let prevented = false;
    handlers.keydown({ key: 'z', altKey: true, ctrlKey: true, preventDefault: () => { prevented = true; } });
    assert.equal(prevented, true);
    assert.equal(backdrop.hidden, false);
    handlers['backdrop-click']();
    assert.equal(backdrop.hidden, true);
    desktop.matches = true;
    handlers['desktop-change']();
    assert.equal(sidebar.classList.contains('is-mobile-open'), false);
    assert.equal(sidebar.inert, false);
    assert.equal(toggle.getAttribute('aria-label'), 'Collapse sidebar');
});

test('menu search filters only rendered links, opens matching modules, and resets on clear', () => {
    const platform = section(['/campuses', '/programs'], null, 'Platform');
    const students = section(['/students', '/student-history'], null, 'Students');
    const { handlers, search, dashboard, noResults } = load('/dashboard', [platform, students]);
    search.value = 'campuses';
    handlers['search-input']();
    assert.equal(platform.hidden, false);
    assert.equal(platform.open, true);
    assert.equal(platform.links[0].hidden, false);
    assert.equal(platform.links[1].hidden, true);
    assert.equal(students.hidden, true);
    assert.equal(dashboard.hidden, true);
    search.value = 'missing';
    handlers['search-input']();
    assert.equal(noResults.hidden, false);
    handlers.pageshow(); // Browser back/forward also clears stale search and opens the active parent.
    assert.equal(search.value, '');
    assert.equal(platform.hidden, false);
    search.value = '';
    handlers['search-input']();
    assert.equal(platform.hidden, false);
    assert.equal(platform.open, false);
    assert.equal(students.hidden, false);
    assert.equal(dashboard.hidden, false);
});

test('sidebar retains navy background and 16px search icon without a Vite build', () => {
    const css = readFileSync(new URL('../public/css/sidebar.css', import.meta.url), 'utf8');
    const layout = readFileSync(new URL('../resources/views/layouts/app.blade.php', import.meta.url), 'utf8');
    assert.match(css, /#app-sidebar\s*\{[^}]*background:\s*#111c30/s);
    assert.match(css, /#app-sidebar \.sidebar-search svg\s*\{[^}]*width:\s*16px;\s*height:\s*16px/s);
    assert.match(css, /#app-sidebar \.sidebar-search input\s*\{[^}]*height:\s*40px/s);
    assert.match(layout, /@endif<link rel="stylesheet" href="\/css\/sidebar\.css">/);
    assert.doesNotMatch(css, /(?:^|\})\s*svg\s*\{/);
});
