import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';

const script = readFileSync(new URL('../public/js/sidebar.js', import.meta.url), 'utf8');

function section(urls, currentLink = null) {
    const links = urls.map((href) => {
        const attributes = new Map(currentLink === href ? [['aria-current', 'page']] : []);
        const classes = new Set();
        return {
            href: `https://erp.test${href}`,
            getAttribute: (key) => attributes.get(key) ?? null,
            hasAttribute: (key) => attributes.has(key),
            setAttribute: (key, value) => attributes.set(key, value),
            classList: { add: (...names) => names.forEach((name) => classes.add(name)), contains: (name) => classes.has(name) },
        };
    });
    return { open: false, links, querySelectorAll: () => links };
}

function load(path, sections) {
    const handlers = {};
    const attributes = new Map();
    const label = { textContent: 'Hide sidebar' };
    const toggle = {
        setAttribute: (key, value) => attributes.set(key, value),
        getAttribute: (key) => attributes.get(key),
        querySelector: () => label,
        addEventListener: (key, callback) => { handlers[`toggle-${key}`] = callback; },
        focus: () => { toggle.focused = true; },
    };
    const sidebar = { hidden: false };
    vm.runInNewContext(script, {
        URL,
        window: {
            location: { href: `https://erp.test${path}` },
            addEventListener: (key, callback) => { handlers[key] = callback; },
        },
        document: {
            querySelectorAll: () => sections,
            getElementById: () => sidebar,
            querySelector: () => toggle,
        },
    });
    return { handlers, toggle, sidebar, label };
}

test('dashboard starts closed, even if the browser restores old open states', () => {
    const students = section(['/students']);
    const hostel = section(['/hostels/dashboard']);
    students.open = true;
    hostel.open = true;
    const { handlers } = load('/dashboard', [students, hostel]);
    assert.equal(students.open, false);
    assert.equal(hostel.open, false);
    hostel.open = true; // Native browser state restoration can occur after deferred JS.
    handlers.pageshow();
    assert.equal(hostel.open, false);
});

test('administration opens alone; a restored Hostel state never remains open', () => {
    const hostel = section(['/hostels/dashboard']);
    const reports = section(['/hostel-reports']);
    const admin = section(['/admin/users', '/admin/roles'], '/admin/roles');
    hostel.open = true;
    reports.open = true;
    const { handlers } = load('/admin/roles/3/edit', [hostel, reports, admin]);
    assert.equal(admin.open, true);
    assert.equal(hostel.open, false);
    assert.equal(reports.open, false);
    assert.equal(admin.links[1].getAttribute('aria-current'), 'page');
    assert.equal(admin.links[1].classList.contains('bg-white/10'), false); // Preserve server styling.
    hostel.open = true; // BFCache/native details restoration.
    handlers.pageshow();
    assert.equal(hostel.open, false);
    assert.equal(admin.open, true);
});

test('only the new route parent opens on navigation, and clicks do not close other sections', () => {
    const students = section(['/students']);
    const reports = section(['/student-reports', '/finance-reports']);
    load('/students/42/edit', [students, reports]);
    assert.equal(students.open, true);
    assert.equal(reports.open, false);
    reports.open = true; // User clicks Reports summary (native details toggle).
    assert.equal(students.open, true); // No JS accordion closes it on click.
    assert.equal(reports.links.length, 2);

    // A new navigation renders fresh sections; the previous module is closed.
    const nextStudents = section(['/students']);
    const nextReports = section(['/student-reports', '/finance-reports']);
    load('/student-reports?status=active', [nextStudents, nextReports]);
    assert.equal(nextStudents.open, false);
    assert.equal(nextReports.open, true);
    assert.equal(nextReports.links[0].getAttribute('aria-current'), 'page');
    assert.ok(nextReports.links[0].classList.contains('bg-white/10'));
});

test('filtered certificate links select the matching child, unlinked detail opens only its parent', () => {
    const certs = section(['/certificates/requests?type=TC', '/certificates/requests?type=BON']);
    load('/certificates/requests?type=BON&status=pending', [certs]);
    assert.equal(certs.open, true);
    assert.equal(certs.links[0].hasAttribute('aria-current'), false);
    assert.equal(certs.links[1].getAttribute('aria-current'), 'page');
    const detail = section(['/certificates/requests?type=TC']);
    load('/certificates/123', [detail]);
    assert.equal(detail.open, true);
    assert.equal(detail.links[0].hasAttribute('aria-current'), false);
    const unfiltered = section(['/certificates/requests?type=TC', '/certificates/requests?type=BON']);
    load('/certificates/requests', [unfiltered]);
    assert.equal(unfiltered.open, true);
    assert.equal(unfiltered.links[0].hasAttribute('aria-current'), false);
});

test('hidden RBAC sections stay absent, and the styled toggle works with click and shortcut', () => {
    const exams = section(['/exam-marks']); // Exam results/Hostel are not rendered for this user.
    const { handlers, sidebar, toggle, label } = load('/exam-marks', [exams]);
    assert.equal(exams.open, true);
    assert.equal(exams.links.length, 1);
    handlers['toggle-click']();
    assert.equal(sidebar.hidden, true);
    assert.equal(toggle.getAttribute('aria-expanded'), 'false');
    assert.equal(toggle.getAttribute('aria-label'), 'Show sidebar');
    assert.equal(label.textContent, 'Show sidebar');
    let prevented = false;
    handlers.keydown({ altKey: true, ctrlKey: true, key: 'z', preventDefault: () => { prevented = true; } });
    assert.equal(prevented, true);
    assert.equal(sidebar.hidden, false);
    assert.equal(toggle.getAttribute('aria-expanded'), 'true');
    assert.equal(toggle.focused, true);
});
