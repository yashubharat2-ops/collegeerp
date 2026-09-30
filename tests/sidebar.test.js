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
    vm.runInNewContext(script, {
        URL,
        window: { location: { href: `https://erp.test${path}` } },
        document: { querySelectorAll: () => sections },
    });
}

test('dashboard has all sections closed; active route opens only its parent on refresh', () => {
    const students = section(['/students', '/student-enrollments']);
    const reports = section(['/student-reports', '/finance-reports']);
    load('/dashboard', [students, reports]);
    assert.equal(students.open, false);
    assert.equal(reports.open, false);
    load('/student-reports?status=active', [students, reports]);
    assert.equal(students.open, false);
    assert.equal(reports.open, true);
    assert.equal(reports.links[0].getAttribute('aria-current'), 'page');
    assert.ok(reports.links[0].classList.contains('bg-white/10'));
});

test('detail pages use the closest child URL and preserve existing active styling', () => {
    const students = section(['/students', '/student-enrollments']);
    const admin = section(['/admin/users', '/admin/roles'], '/admin/roles');
    load('/students/42/edit', [students]);
    assert.equal(students.open, true);
    assert.equal(students.links[0].getAttribute('aria-current'), 'page');
    load('/admin/roles/3/edit', [admin]);
    assert.equal(admin.open, true);
    assert.equal(admin.links[1].getAttribute('aria-current'), 'page');
    assert.equal(admin.links[1].classList.contains('bg-white/10'), false); // Server styling is untouched.
});

test('filtered certificate links select the matching child and unlinked detail opens its group', () => {
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

test('opening a section does not close another or invent RBAC-hidden links', () => {
    const platform = section(['/campuses']);
    const exams = section(['/exam-marks']); // Exam results are hidden by RBAC.
    platform.open = true; // Native details user interaction, independent of the script.
    load('/exam-marks', [platform, exams]);
    assert.equal(platform.open, true);
    assert.equal(exams.open, true);
    assert.equal(exams.links.length, 1);
    assert.equal(exams.links[0].getAttribute('aria-current'), 'page');
});
