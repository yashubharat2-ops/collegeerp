<?php

namespace Tests\Feature\Layout;

use App\Models\{College, Permission};
use Illuminate\Support\Facades\Route;
use Tests\Feature\Departments\DepartmentTestHelpers;
use Tests\TestCase;

/**
 * The shape of the rebuilt navigation tree.
 *
 * Accompanies the Platform → Academics consolidation: `Platform` is no longer a
 * top-level group, its seven destinations live inside `Academics` under three
 * sub-group labels, and the slash-joined labels are gone.
 *
 * What this file guards, and why each part exists:
 *
 *  1. Platform is not a group any more — in the source and in the rendered sidebar.
 *  2. Every destination it used to own is still there, unchanged, under Academics,
 *     and nothing else was added to that group. The row order of every other group
 *     is pinned too, because a merge is exactly the kind of edit that quietly
 *     shuffles the rest of the tree.
 *  3. A sub-group label is a <p>: not a link, not a group, no id. That is the
 *     property every other navigation test in this repository depends on, since
 *     they count `class="nav-link"` and scan for `nav-group__head` to find group
 *     boundaries.
 *  4. A label never renders without a row under it, for any permission set.
 *  5. No label reads as two destinations joined by a slash.
 *  6. No two rows offer the same destination.
 *  7. Every destination the sidebar names is a route that still exists.
 *  8. The skip link is first, points at a focusable landmark, and is styled by the
 *     static stylesheet.
 *  9. Active state is still server-rendered, so it survives a refresh and works
 *     with JavaScript disabled.
 */
class SidebarStructureTest extends TestCase
{
    use DepartmentTestHelpers;

    /** The module groups that must render, in document order. */
    private const MODULE_GROUPS = [
        'hr', 'admissions', 'students', 'certificates', 'academics', 'examinations',
        'finance', 'transport', 'library', 'hostel', 'communication', 'inventory', 'reports',
    ];

    /**
     * The seven rows the old top-level `Platform` group carried. These are the exact
     * route names it declared before the move — no route was renamed, added or
     * dropped by it.
     */
    private const MOVED_FROM_PLATFORM = [
        'campuses.index',
        'programs.index',
        'academic-years.index',
        'academic-terms.index',
        'sections.index',
        'subjects.index',
        'faculty-subject-assignments.index',
    ];

    /** The merged Academics group: two institute rows, four structure rows, seven operation rows. */
    private const ACADEMICS_ROWS = [
        'campuses.index',
        'programs.index',
        'academic-years.index',
        'academic-terms.index',
        'sections.index',
        'subjects.index',
        'academic-subject-enrollments.index',
        'faculty-subject-assignments.index',
        'academic-sections.index',
        'academic-timetables.index',
        'academic-attendance.index',
        'academic-calendar.index',
        'academic-workload.index',
    ];

    private const ACADEMICS_LABELS = ['Institute setup', 'Academic structure', 'Academic operations'];

    /**
     * Every label that used to join two concepts with " / ", mapped to its new
     * wording. A rename that silently reverted would otherwise only be caught by
     * whichever per-module label test happens to cover the same string.
     *
     * `Grades & Pass/Fail` is deliberately not here: "Pass/Fail" is one compound
     * term, not two destinations sharing a row.
     *
     * @var array<string, string>
     */
    private const DE_SLASHED = [
        'Sections / Batches' => 'Sections',
        'Staff / Employee' => 'Staff &amp; Employees',
        'Staff Salary / Payroll' => 'Salary &amp; Payroll',
        'Merit / Selection' => 'Merit &amp; Selection',
        'Bulk Registration / Import' => 'Bulk Registration &amp; Import',
        'Class / Section Management' => 'Classes &amp; Sections',
        'Grade / Pass-Fail' => 'Grades &amp; Pass/Fail',
        'Due / Outstanding Fees' => 'Outstanding Fees',
        'Fee Discounts / Concessions' => 'Discounts &amp; Concessions',
        'Authors / Publishers' => 'Authors &amp; Publishers',
        'Issue / Return' => 'Issue &amp; Return',
        'Fines / Penalties' => 'Fines &amp; Penalties',
        'Buildings / Blocks' => 'Buildings &amp; Blocks',
        'Notices / Announcements' => 'Notices &amp; Announcements',
        'SMS / Email Templates' => 'SMS &amp; Email Templates',
        'SMS / Email Logs' => 'SMS &amp; Email Logs',
        'Delivery / Read Tracking' => 'Delivery &amp; Read Tracking',
        'Items / Assets' => 'Items &amp; Assets',
        'Goods Receipt / Stock In' => 'Goods Receipt &amp; Stock In',
        'Item Issue / Allocation' => 'Item Issue &amp; Allocation',
        'Stock / Transaction Reports' => 'Stock &amp; Transaction Reports',
        'Inventory / Asset Reports' => 'Inventory &amp; Asset Reports',
    ];

    /** A dashboard rendered for a viewer holding every permission in the system. */
    private function dashboardForFullAccess(College $college): string
    {
        $user = $this->makeUserWithPermissions($college, Permission::query()->pluck('slug')->all());

        return $this->aside($this->asCollege($college, $user)->get(route('dashboard'))->assertOk()->getContent());
    }

    /** A dashboard rendered for a viewer holding exactly one permission. */
    private function dashboardFor(College $college, array $permissions): string
    {
        $user = $this->makeUserWithPermissions($college, $permissions);

        return $this->aside($this->asCollege($college, $user)->get(route('dashboard'))->assertOk()->getContent());
    }

    /** Everything between the sidebar's opening tag and its close. */
    private function aside(string $html): string
    {
        $start = strpos($html, '<aside');
        $this->assertNotFalse($start, 'The layout must render the sidebar.');
        $end = strpos($html, '</aside>', $start);
        $this->assertNotFalse($end, 'The sidebar must close.');

        return substr($html, $start, $end - $start);
    }

    /** One group's markup: from its label until the next group's label. */
    private function group(string $aside, string $label): string
    {
        $start = strpos($aside, 'nav-group__label">'.$label.'<');
        $this->assertNotFalse($start, "The sidebar must render a {$label} group.");
        $next = strpos($aside, 'nav-group__label">', $start + 1);

        return substr($aside, $start, $next === false ? null : $next - $start);
    }

    /**
     * One group split by its sub-group labels.
     *
     * @return array<string, string> label => the markup of the rows under it
     */
    private function sections(string $group): array
    {
        $found = [];
        $parts = explode('<p class="erp-nav__sub-label" data-nav-sub-label>', $group);
        array_shift($parts);

        foreach ($parts as $part) {
            $label = strstr($part, '</p>', true);
            $found[$label] = substr($part, strpos($part, '</p>') + 4);
        }

        return $found;
    }

    /** Every `/path` a nav-link in this markup points at, with any host stripped. */
    private function hrefs(string $markup): array
    {
        preg_match_all('/<a class="nav-link" href="([^"]+)"/', $markup, $matches);

        return array_map([$this, 'pathFromHref'], $matches[1]);
    }

    /**
     * The `/path` from a rendered href, whatever host it carries.
     *
     * `route()` renders an absolute URL — `http://localhost/students` in this suite,
     * the configured `APP_URL` elsewhere — so an assertion may never compare a
     * rendered href against the output of `parse_url(route(...), PHP_URL_PATH)` by
     * string concatenation. Every comparison in this file goes through here, so the
     * absolute/relative detail cannot decide whether a test passes.
     */
    private function pathFromHref(string $href): string
    {
        return (string) parse_url($href, PHP_URL_PATH);
    }

    /**
     * The one `<a>` ELEMENT that both links to `$href` and contains that label in a
     * `<span>` — returned as the element, so a caller can inspect attributes and the
     * label, not just one or the other.
     *
     * The element has to be matched whole: `<span>Campuses</span>` sits *between* the
     * opening tag and `</a>`, so matching the two facts against the opening tag alone
     * can never succeed. Requiring both in the same element is stricter than either
     * half — it fails if the row was repointed, if it lost its label, or if it is gone.
     * Reading the element (rather than the byte stream around it) also means the
     * result does not depend on the order or spacing of the attributes Blade emits.
     */
    private function row(string $label, string $href, string $markup): string
    {
        $path = $this->pathFromHref($href);
        preg_match_all('/<a\s[^>]*>.*?<\/a>/s', $markup, $elements);

        $matches = array_values(array_filter(
            $elements[0],
            function (string $element) use ($label, $path): bool {
                $opening = $this->openingTagOf($element);
                $linked = preg_match('/href="([^"]+)"/', $opening, $target) === 1
                    && $this->pathFromHref($target[1]) === $path;

                return $linked && preg_match('/<span>\s*'.preg_quote($label, '/').'\s*<\/span>/', $element) === 1;
            }
        ));

        $this->assertCount(
            1,
            $matches,
            "Exactly one navigation row must link to {$path} and carry the label \"{$label}\"."
        );

        return $matches[0];
    }

    /** The opening `<a …>` tag of an element returned by row(). */
    private function openingTagOf(string $element): string
    {
        $this->assertSame(1, preg_match('/^<a\s[^>]*>/', $element, $matches), 'An anchor element must open with an `<a …>` tag.');

        return $matches[0];
    }

    /** The raw `href` of an element returned by row()/currentRow(). */
    private function hrefOf(string $element): string
    {
        $this->assertSame(1, preg_match('/href="([^"]+)"/', $element, $matches), 'A navigation row must carry an href.');

        return $matches[1];
    }

    /** The one `<a>` element that is marked as the current page. */
    private function currentRow(string $markup): string
    {
        preg_match_all('/<a\s[^>]*aria-current="page"[^>]*>.*?<\/a>/s', $markup, $matches);

        $this->assertCount(1, $matches[0], 'Exactly one row must be marked as the current page.');

        return $matches[0][0];
    }

    /**
     * Every piece of text the sidebar renders as a label — row labels and
     * sub-group labels both — in document order.
     *
     * @return string[]
     */
    private function labels(string $aside): array
    {
        preg_match_all('/<span>([^<]+)<\/span>|<p class="erp-nav__sub-label" data-nav-sub-label>([^<]+)<\/p>/', $aside, $matches);

        return array_values(array_filter(array_merge($matches[1], $matches[2])));
    }

    /** The `/path` a named route resolves to. */
    private function path(string $routeName): string
    {
        return (string) parse_url(route($routeName), PHP_URL_PATH);
    }

    /** @return string[] */
    private function paths(array $routeNames): array
    {
        return array_map(fn (string $name): string => $this->path($name), $routeNames);
    }

    /* 1 · Platform is gone ------------------------------------------------ */

    public function test_platform_is_no_longer_a_top_level_group(): void
    {
        $source = (string) file_get_contents(resource_path('views/layouts/sidebar.blade.php'));
        $aside = $this->dashboardForFullAccess($this->makeCollege('STRUCT1'));

        $this->assertStringNotContainsString('<x-nav.group id="platform"', $source, 'Platform must not come back as a group in the source.');
        $this->assertStringNotContainsString('nav-group__label">Platform<', $aside, 'Platform must not be a group heading any more.');
        $this->assertStringNotContainsString('data-nav-group="platform"', $aside, 'The old group id is gone with it.');
        $this->assertSame(0, substr_count($aside, '>Platform</div>'), 'No menu heading may read "Platform".');
    }

    public function test_the_group_list_is_exactly_the_thirteen_modules_plus_settings(): void
    {
        $aside = $this->dashboardForFullAccess($this->makeCollege('STRUCT2'));
        preg_match_all('/<li class="nav-group" data-nav-group="([a-z-]+)"/', $aside, $matches);
        $ids = $matches[1];

        foreach (self::MODULE_GROUPS as $id) {
            $this->assertContains($id, $ids, "The {$id} group must still render.");
        }

        // Settings is the one group whose rows are gated by policies rather than
        // permission slugs, so whether it renders depends on the actor — but nothing
        // else may appear, and in particular no group may be duplicated.
        $this->assertSame([], array_diff($ids, array_merge(self::MODULE_GROUPS, ['administration-settings'])), 'No group other than the thirteen modules and Settings may render.');
        $this->assertSame($ids, array_values(array_unique($ids)), 'A module group must never render twice.');
        $this->assertSame(count($ids), substr_count($aside, '<button type="button" class="nav-group__head"'), 'Every group renders exactly one head button.');
    }

    /* 2 · Its destinations all live under Academics ----------------------- */

    public function test_every_platform_destination_is_rendered_inside_academics(): void
    {
        $aside = $this->dashboardForFullAccess($this->makeCollege('STRUCT3'));
        $academics = $this->group($aside, 'Academics');

        $this->assertSame(13, substr_count($academics, 'class="nav-link"'), 'Academics must hold the six rows it always had plus the seven Platform rows.');
        $this->assertSame(
            $this->paths(self::ACADEMICS_ROWS),
            $this->hrefs($academics),
            'The merged Academics group must render exactly these destinations, in this order, and nothing else.'
        );

        foreach (self::MOVED_FROM_PLATFORM as $name) {
            $this->assertTrue(Route::has($name), "{$name} must still exist: the move changed the menu, not the route.");
        }
    }

    public function test_the_moved_rows_keep_their_own_labels_and_routes(): void
    {
        $aside = $this->dashboardForFullAccess($this->makeCollege('STRUCT4'));
        $academics = $this->group($aside, 'Academics');

        foreach ([
            'Campuses' => 'campuses.index',
            'Programs' => 'programs.index',
            'Academic Years' => 'academic-years.index',
            'Academic Terms' => 'academic-terms.index',
            'Sections' => 'sections.index',
            'Subjects' => 'subjects.index',
            'Faculty–Subject Assignments' => 'faculty-subject-assignments.index',
        ] as $label => $name) {
            $row = $this->row($label, route($name), $academics);

            $this->assertStringContainsString('<span>'.$label.'</span>', $row, "{$label} must keep its label.");
            $this->assertSame($this->path($name), $this->pathFromHref($this->hrefOf($row)), "{$label} must keep its route.");
            $this->assertStringContainsString('class="nav-link"', $row, "{$label} must render as a navigation row.");
        }
    }

    public function test_the_consolidation_invented_no_destination(): void
    {
        $aside = $this->dashboardForFullAccess($this->makeCollege('STRUCT5'));
        $academics = $this->group($aside, 'Academics');

        // No second "Departments" row: departments.index is the same controller
        // action the HR group already offers as "Staff Departments", so adding it
        // here would be a duplicate destination.
        $this->assertStringNotContainsString($this->path('departments.index'), $academics, 'Departments is already reachable as HR → Staff Departments.');
        $this->assertStringNotContainsString('>Departments</span>', $academics);

        // And no invented "Classes": there is no Classes route to link to, so the
        // operational screen keeps its single, honest label instead.
        $this->assertFalse(Route::has('classes.index'), 'There is no Classes route to link, and none may be invented.');
        $this->assertStringContainsString('>Classes &amp; Sections</span>', $academics);
        $this->assertSame(
            $this->path('academic-sections.index'),
            $this->pathFromHref($this->hrefOf($this->row('Classes &amp; Sections', route('academic-sections.index'), $academics))),
            'That row still points at the Academic Sections screen.'
        );
        $this->assertStringContainsString('>Sections</span>', $academics, 'The setup-level Sections row keeps its own clean label.');
        $this->assertSame(
            $this->path('sections.index'),
            $this->pathFromHref($this->hrefOf($this->row('Sections', route('sections.index'), $academics))),
            'The setup-level Sections row keeps its own destination.'
        );
        $this->assertNotSame($this->path('sections.index'), $this->path('academic-sections.index'), 'Sections and Classes & Sections stay two distinct destinations.');
    }

    /* 3 · A sub-group label is not a link and not a group ----------------- */

    public function test_a_sub_group_label_adds_no_link_and_no_group(): void
    {
        $aside = $this->dashboardForFullAccess($this->makeCollege('STRUCT6'));

        $this->assertStringContainsString('<p class="erp-nav__sub-label" data-nav-sub-label>', $aside, 'The sub-group labels must render.');

        // The exact markup of a label: a <p>, never an <a>, never a button, no id.
        // This is what keeps `substr_count($group, 'class="nav-link"')` and the
        // `nav-group__head` group-boundary scans in every other navigation test true.
        //
        // The "must not wrap a link" check reads the label ELEMENT, not a span of the
        // byte stream. A whole-string regex of the form
        // /erp-nav__sub-label[^>]*>.*?<\/a>/s is a false positive generator: `.*?`
        // runs past the label's own `</p>`, through the following labels and rows,
        // and closes on the first `</a>` it meets — which in this markup is the
        // very next navigation row. The structural form below can only match if the
        // label element itself begins with `<a` or contains an `<a`, which is what
        // the requirement actually forbids.
        $this->assertSame(
            substr_count($aside, 'data-nav-sub-label>'),
            preg_match_all('/<p class="erp-nav__sub-label" data-nav-sub-label>/', $aside),
            'Every sub-group label must render as a <p> with exactly this markup — not an <a>, not a <button>.'
        );
        $this->assertSame(
            0,
            preg_match('/<a\s[^>]*erp-nav__sub-label/', $aside),
            'A sub-group label must never itself be a link.'
        );
        // Reads the label ELEMENT and nothing beyond its closing tag: the tempered
        // dot cannot run past `</p>`, so a later navigation row can never satisfy it.
        $this->assertSame(
            0,
            preg_match('/<p class="erp-nav__sub-label" data-nav-sub-label>(?:(?!<\/p>).)*<a\s/s', $aside),
            'A sub-group label must never contain a link.'
        );
        $this->assertSame(0, substr_count($aside, 'nav-group__head erp-nav__sub-label'), 'A sub-group label is not a collapsible group.');
        $this->assertSame(0, substr_count($aside, 'erp-nav__sub-label" data-nav-sub-label id='), 'A sub-group label takes no id.');
        $this->assertSame(0, substr_count($aside, '<button class="erp-nav__sub-label'), 'A sub-group label is not interactive.');

        // Labels are a presentation detail of the group they sit in, so the count is
        // asserted per module rather than as one global number: the merged Academics
        // group is the only group whose label count changed.
        $counts = array_intersect_key($this->labelCounts($aside), array_flip(self::MODULE_GROUPS));
        $this->assertSame(
            ['hr' => 0, 'admissions' => 0, 'students' => 2, 'certificates' => 0, 'academics' => 3, 'examinations' => 4,
             'finance' => 3, 'transport' => 0, 'library' => 2, 'hostel' => 0, 'communication' => 0, 'inventory' => 5, 'reports' => 5],
            $counts,
            'Each module renders only the sub-group labels it needs.'
        );
        $this->assertSame(24, array_sum($counts), '24 sub-group labels across the module groups.');
    }

    public function test_the_academics_group_is_split_by_three_labels_in_order(): void
    {
        $aside = $this->dashboardForFullAccess($this->makeCollege('STRUCT7'));
        $sections = $this->sections($this->group($aside, 'Academics'));

        $this->assertSame(self::ACADEMICS_LABELS, array_keys($sections), 'The three sub-group labels must render in this order.');

        $this->assertSame($this->paths(['campuses.index', 'programs.index']), $this->hrefs($sections['Institute setup']));
        $this->assertSame($this->paths(['academic-years.index', 'academic-terms.index', 'sections.index', 'subjects.index']), $this->hrefs($sections['Academic structure']));
        $this->assertSame($this->paths([
            'academic-subject-enrollments.index',
            'faculty-subject-assignments.index',
            'academic-sections.index',
            'academic-timetables.index',
            'academic-attendance.index',
            'academic-calendar.index',
            'academic-workload.index',
        ]), $this->hrefs($sections['Academic operations']));
    }

    public function test_no_group_repeats_one_of_its_own_labels(): void
    {
        $aside = $this->dashboardForFullAccess($this->makeCollege('STRUCT8'));

        preg_match_all('/data-nav-group="([a-z-]+)"(.*?)(?=<li class="nav-group"|$)/s', $aside, $matches, PREG_SET_ORDER);

        $seen = 0;
        foreach ($matches as [, $id, $body]) {
            preg_match_all('/data-nav-sub-label>([^<]+)</', $body, $labels);
            $this->assertSame($labels[1], array_values(array_unique($labels[1])), "The {$id} group must not repeat a sub-group label.");
            if (in_array($id, self::MODULE_GROUPS, true)) {
                $seen += count($labels[1]);
            }
        }

        $this->assertSame(24, $seen, 'Every module sub-group label belongs to a group.');
    }

    /* 4 · Row order is preserved outside the merged group ----------------- */

    public function test_rows_keep_their_original_relative_order(): void
    {
        // The merge is the only edit that moved rows, and it moved exactly one row
        // set: the seven Platform destinations, of which Faculty–Subject Assignments
        // now sits beside Student Subject Enrollment. Every other group keeps the
        // order its rows were in before — a consolidation that quietly reshuffles
        // the rest of the tree is a regression nobody would notice by hand.
        $aside = $this->dashboardForFullAccess($this->makeCollege('STRUCT9'));

        $orders = [
            'Fees' => [
                'fee-structures.index', 'fee-categories.index', 'student-fee-assignments.index',
                'fee-collections.index', 'receipts.index', 'fee-dues.index', 'fee-concessions.index',
                'refunds.index', 'fee-reports.index',
            ],
            'Examinations' => [
                'examinations.index', 'exam-schedules.index', 'exam-attendance.index', 'exam-marks.index',
                'results.index', 'result-calculation.index', 'grade-scales.index', 'result-publishing.index',
                'marksheets.index', 'grade-cards.index', 'exam-reports.index', 'student-result-history.index',
            ],
            'Library' => [
                'library.dashboard', 'books.index', 'book-categories.index', 'authors.index',
                'book-copies.index', 'library-members.index', 'library-transactions.index',
                'library-renewals.index', 'library-fines.index',
            ],
            'Inventory' => [
                'inventory.dashboard', 'inventory-categories.index', 'inventory-items.index', 'inventory-vendors.index',
                'inventory-purchase-orders.index', 'inventory-goods-receipts.index', 'inventory-stock-adjustments.index',
                'inventory-transactions.index', 'inventory-issues.index', 'inventory-assignments.index',
                'inventory-asset-returns.index', 'inventory-maintenances.index', 'inventory-current-stock.index',
                'inventory-low-stock.index', 'inventory-asset-register.index', 'inventory-stock-reports.index',
            ],
            'Reports' => [
                'student-reports.index', 'academic-reports.index', 'examination-reports.index', 'finance-reports.index',
                'inventory-reports.index', 'hr-reports.index', 'library-reports.index', 'transport-reports.index',
                'hostel-reports.index', 'communication-reports.index', 'certificate-reports.index', 'consolidated-reports.index',
            ],
            'Students' => [
                'students.index', 'students.import.index', 'student-enrollments.index', 'student-academic-records.index',
                'student-documents.index', 'student-id-cards.index', 'student-promotions.index', 'student-transfers.index',
                'student-history.index',
            ],
            'Transport' => [
                'transport.dashboard', 'vehicles.index', 'vehicle-documents.index', 'transport-drivers.index',
                'transport-routes.index', 'transport-stops.list', 'transport-assignments.index', 'transport-fees.index',
            ],
            'Hostel' => [
                'hostels.dashboard', 'hostels.index', 'hostel-buildings.index', 'hostel-rooms.index', 'hostel-beds.index',
                'hostel-allocations.index', 'hostel-fees.index', 'hostel-attendance.index',
            ],
            'Communication' => [
                'communication.dashboard', 'notices.index', 'circulars.index', 'notifications.index',
                'communication-templates.index', 'communication-logs.index', 'communication-tracking.index',
            ],
        ];

        foreach ($orders as $label => $routes) {
            $this->assertSame(
                $this->paths($routes),
                $this->hrefs($this->group($aside, $label)),
                "{$label} must keep its rows in their original order. (authors.index stands in for the Authors & Publishers row, which resolves to it for a viewer holding authors.view.)"
            );
        }
    }

    public function test_the_students_group_keeps_every_destination_it_had(): void
    {
        // Students stays an independent top-level module. Nine rows, two sub-labels,
        // nothing merged away and nothing added.
        $aside = $this->dashboardForFullAccess($this->makeCollege('STRUCT10'));
        $students = $this->group($aside, 'Students');

        $this->assertSame(9, substr_count($students, 'class="nav-link"'), 'Every Students destination must still render.');
        $this->assertSame(2, substr_count($students, 'data-nav-sub-label>'), 'Students keeps its two sub-group labels.');
        $this->assertSame(
            $this->path('students.index'),
            $this->pathFromHref($this->hrefOf($this->row('Students', route('students.index'), $students))),
            'The Students row must keep its destination.'
        );
        $this->assertSame(
            $this->paths(['student-enrollments.index', 'student-academic-records.index', 'student-documents.index', 'student-id-cards.index', 'student-promotions.index', 'student-transfers.index', 'student-history.index']),
            array_slice($this->hrefs($students), 2),
            'The lifecycle rows are unchanged, and still in the order they were in.'
        );
    }

    /* 5 · A label never renders without a row ----------------------------- */

    public function test_a_restricted_viewer_never_sees_a_label_with_nothing_under_it(): void
    {
        // One permission per group, each of which belongs to a LATER section of its
        // group: the earlier sections must disappear entirely rather than leaving a
        // heading above nothing.
        $cases = [
            'library_fines.view' => ['Library', 'Circulation', ['Catalogue']],
            'fee_reports.view' => ['Fees', 'Reports', ['Fee setup', 'Collections & dues']],
            'result_publishing.view' => ['Examinations', 'Result processing', ['Exam setup', 'Exam conduct', 'Documents & reports']],
            'consolidated_reports.view' => ['Reports', 'Executive', ['Academic', 'Finance', 'Operations', 'Outreach & records']],
            'inventory_low_stock.view' => ['Inventory', 'Stock levels', ['Setup', 'Procurement', 'Assets in use', 'Audit']],
        ];

        $college = $this->makeCollege('STRUCT11');

        foreach ($cases as $permission => [$groupLabel, $kept, $hidden]) {
            $group = $this->group($this->dashboardFor($college, [$permission]), $groupLabel);

            $this->assertSame(1, substr_count($group, 'class="nav-link"'), "{$permission} alone must render exactly one row.");
            $this->assertSame(1, substr_count($group, 'data-nav-sub-label>'), "{$permission} alone must render exactly one label.");
            $this->assertStringContainsString('data-nav-sub-label>'.$kept.'</p>', $group, "{$kept} must stay: it still heads a visible row.");

            foreach ($hidden as $gone) {
                $this->assertStringNotContainsString('data-nav-sub-label>'.$gone.'</p>', $group, "{$gone} must not render for {$permission}: nothing would be under it.");
            }
        }
    }

    public function test_a_group_with_no_permitted_row_renders_no_labels_at_all(): void
    {
        $aside = $this->dashboardFor($this->makeCollege('STRUCT12'), ['students.view']);

        $this->assertStringNotContainsString('data-nav-group="library"', $aside, 'A viewer without library permissions sees neither the group nor its labels.');
        $this->assertStringNotContainsString('data-nav-sub-label>Catalogue</p>', $aside);
        $this->assertStringNotContainsString('data-nav-sub-label>Circulation</p>', $aside);
        $this->assertStringNotContainsString('data-nav-sub-label>Fee setup</p>', $aside);
        $this->assertStringNotContainsString('data-nav-sub-label>Exam setup</p>', $aside);
        $this->assertStringNotContainsString('data-nav-sub-label>Executive</p>', $aside);
        $this->assertStringNotContainsString('data-nav-sub-label>Stock levels</p>', $aside);
    }

    public function test_the_merged_group_keeps_the_permission_gate_it_had(): void
    {
        // Neither the old Platform group nor the old Academics group carried a
        // permission gate: their rows were ungated before this change and they are
        // ungated now. This test exists so that fact is explicit rather than
        // accidental — the consolidation must not tighten or loosen visibility, and
        // the rows' own page-level authorization is unchanged.
        $aside = $this->dashboardFor($this->makeCollege('STRUCT12B'), ['students.view']);

        $this->assertStringContainsString('data-nav-group="academics" data-nav-open=', $aside, 'The Academics group renders ungated, exactly as Platform did.');
        $this->assertStringContainsString('data-nav-sub-label>Institute setup</p>', $aside);
        $this->assertSame(13, substr_count($this->group($aside, 'Academics'), 'class="nav-link"'), 'Every merged row still renders for a viewer without academic permissions, as all thirteen did before the move.');
    }

    /* 6 · No slash-joined label is left ----------------------------------- */

    public function test_no_navigation_label_joins_two_concepts_with_a_slash(): void
    {
        $aside = $this->dashboardForFullAccess($this->makeCollege('STRUCT13'));
        $labels = $this->labels($aside);

        $joined = array_values(array_filter($labels, fn (string $label): bool => str_contains($label, ' / ')));

        $this->assertGreaterThan(150, count($labels), 'Every row and sub-group label must be inspected.');
        $this->assertSame([], $joined, 'No sidebar label may combine two concepts with " / ".');
        $this->assertContains('Grades &amp; Pass/Fail', $labels, 'The one compound slash ("Pass/Fail") is intentional and still present.');
    }

    public function test_every_previously_slashed_label_was_replaced_by_its_new_wording(): void
    {
        $aside = $this->dashboardForFullAccess($this->makeCollege('STRUCT14'));
        $labels = $this->labels($aside);

        foreach (self::DE_SLASHED as $old => $new) {
            $this->assertStringNotContainsString($old, $aside, "The old label \"{$old}\" must be gone from the navigation.");
            $this->assertContains($new, $labels, "The navigation must render \"{$new}\" in its place.");
        }
    }

    /* 7 · No duplicated destination -------------------------------------- */

    public function test_no_two_rows_offer_the_same_destination(): void
    {
        $aside = $this->dashboardForFullAccess($this->makeCollege('STRUCT15'));

        preg_match_all('/<a class="nav-(?:link|dashboard)" href="([^"]+)"/', $aside, $matches);

        $this->assertGreaterThan(120, count($matches[1]), 'A full-permission viewer must see the whole navigation tree.');
        $this->assertSame($matches[1], array_values(array_unique($matches[1])), 'Two menu rows must never offer the same URL.');
    }

    /* 8 · Every destination is a route that exists ----------------------- */

    public function test_every_route_named_in_the_navigation_source_still_exists(): void
    {
        $source = (string) file_get_contents(resource_path('views/layouts/sidebar.blade.php'))
            .(string) file_get_contents(resource_path('views/administration/partials/navigation.blade.php'));

        preg_match_all('/\broute="([a-z0-9.\-]+)"/i', $source, $attributes);
        preg_match_all("/route\(\s*'([a-z0-9.\-]+)'/i", $source, $calls);
        preg_match_all("/'route'\s*=>\s*'([a-z0-9.\-]+)'/i", $source, $configured);

        $names = array_values(array_unique(array_merge($attributes[1], $calls[1], $configured[1])));
        $this->assertGreaterThan(120, count($names), 'The navigation source must still name the whole module tree.');
        foreach (['admin.users.index', 'admin.roles.index', 'admin.permissions.index', 'admin.system-settings.index'] as $admin) {
            $this->assertContains($admin, $names, 'The Settings rows must still be picked up by this scan.');
        }
        $this->assertNotContains('classes.index', $names, 'No row may be added for a route that does not exist.');

        foreach ($names as $name) {
            $this->assertTrue(Route::has($name), "The sidebar points at {$name}, which is not a registered route.");
        }
    }

    /* 9 · The skip link --------------------------------------------------- */

    public function test_the_skip_link_is_the_first_focusable_element_and_its_target_exists(): void
    {
        $college = $this->makeCollege('STRUCT16');
        $user = $this->makeUserWithPermissions($college, ['students.view']);
        $html = $this->asCollege($college, $user)->get(route('dashboard'))->assertOk()->getContent();

        $skip = '<a class="erp-skip-link" href="#erp-main">Skip to main content</a>';
        $this->assertStringContainsString($skip, $html, 'The shell must offer a skip link.');

        // First anchor in the document, and before the sidebar: one Tab reaches it.
        $this->assertSame(strpos($html, $skip), strpos($html, '<a '), 'The skip link must be the first link on the page.');
        $this->assertLessThan(strpos($html, '<aside'), strpos($html, $skip), 'It must come before the navigation.');
        $this->assertSame(0, preg_match('/<(button|input|select|textarea)[^>]*>/', substr($html, 0, strpos($html, $skip))), 'Nothing focusable may precede it.');

        // Its target is a real landmark that can hold focus.
        $this->assertMatchesRegularExpression('/<main[^>]*\bid="erp-main"[^>]*>/', $html, 'The target must exist.');
        $this->assertMatchesRegularExpression('/<main[^>]*\btabindex="-1"[^>]*>/', $html, 'A tabindex of -1 is what lets focus move there.');
    }

    public function test_the_shell_chrome_is_styled_by_a_static_stylesheet(): void
    {
        $css = (string) file_get_contents(public_path('css/erp-ui.css'));
        $layout = (string) file_get_contents(resource_path('views/layouts/app.blade.php'));

        $this->assertStringContainsString('.erp-skip-link', $css, 'The skip link must be styled by the static layer, not by a build output.');
        $this->assertStringContainsString('erp-ui.css', $layout, 'The layout must link that stylesheet.');

        // Static chrome must load before the bundle so the utilities still win.
        $this->assertStringContainsString("asset('css/erp-ui.css')", $layout, 'The layout links the static sheet by path, not through Vite.');
        $static = strpos($layout, "<link rel=\"stylesheet\" href=\"{{ asset('css/erp-ui.css') }}");
        $vite = strpos($layout, "@vite(['resources/css/app.css'");
        $this->assertNotFalse($static);
        $this->assertNotFalse($vite);
        $this->assertLessThan($vite, $static, 'The static stylesheet must be linked before @vite, so a missing build cannot hide the shell.');
    }

    /* 10 · Active state is still server-rendered -------------------------- */

    public function test_the_active_row_and_its_open_group_are_still_rendered_by_the_server(): void
    {
        $college = $this->makeCollege('STRUCT17');
        $user = $this->makeUserWithPermissions($college, Permission::query()->pluck('slug')->all());
        $aside = $this->aside($this->asCollege($college, $user)->get(route('academic-timetables.index'))->assertOk()->getContent());

        // Read the element, not a concatenation of its attributes: Blade emits
        // `<a class="nav-link" href="…" aria-current="page">` with the attribute
        // order and spacing the component decides, and it renders the href as an
        // absolute URL. Both details are formatting, and neither may decide whether
        // this test passes.
        $current = $this->currentRow($aside);
        $this->assertStringContainsString('class="nav-link"', $this->openingTagOf($current), 'The current row must be a navigation row.');
        $this->assertSame(
            $this->path('academic-timetables.index'),
            $this->pathFromHref($this->hrefOf($current)),
            'The current row must be the Timetable row, marked with aria-current so it works without JavaScript.'
        );
        $this->assertStringContainsString('<span>Timetable</span>', $current, 'And that row is the one labelled Timetable.');
        $this->assertStringContainsString('data-nav-group="academics" data-nav-open="true" data-nav-active="true"', $aside, 'Its group is open, and flagged active, server-side.');
        $this->assertSame(0, substr_count($aside, 'erp-nav__sub-label" data-nav-sub-label aria-current'), 'A sub-group label is not a destination and must never be aria-current.');
    }

    /* 11 · The rendered-row anatomy -------------------------------------- */

    /**
     * The markup shape every other assertion in this file is written against.
     *
     * `<x-nav.link>` emits one single-line anchor per row. If that ever changes,
     * this test says so in one place, in the component's own words, instead of
     * letting a dozen sibling assertions fail with a diff that does not name the
     * cause. It also pins the two details that naively written assertions get
     * wrong: `route()` renders an ABSOLUTE url (so no assertion may compare a
     * rendered href to a bare path), and `aria-current` is appended after `href`
     * (so no assertion may assume the attribute order).
     */
    public function test_a_navigation_row_renders_as_one_anchor_with_an_absolute_href(): void
    {
        $college = $this->makeCollege('STRUCT19');
        $user = $this->makeUserWithPermissions($college, Permission::query()->pluck('slug')->all());
        $aside = $this->aside($this->asCollege($college, $user)->get(route('academic-timetables.index'))->assertOk()->getContent());

        $link = (string) file_get_contents(resource_path('views/components/nav/link.blade.php'));

        // The shape of the anchor, spelled out in the component itself.
        $this->assertStringContainsString(
            '<a class="nav-link" href="{{ $href ?? route($route, $params) }}"',
            $link,
            'A row is a single anchor whose href comes from route(); other assertions in this file are written against that shape.'
        );
        $this->assertStringContainsString('@if ($current) aria-current="page"@endif', $link, 'The current-page marker follows the href, so attribute order is not up for assumption.');
        $this->assertSame(0, substr_count($link, "<a\n"), 'A row must stay on one line: the markup contract is asserted by tag-level patterns.');

        // Every rendered row is an anchor element with an absolute href, the row
        // class, aria-current only when it is the current page, and one label span.
        preg_match_all('/<a\s[^>]*class="nav-link"[^>]*>.*?<\/a>/s', $aside, $rows);
        $this->assertGreaterThan(100, count($rows[0]), 'The full navigation must render.');

        $current = 0;
        foreach ($rows[0] as $row) {
            $opening = $this->openingTagOf($row);
            $href = $this->hrefOf($opening);
            $this->assertMatchesRegularExpression('#^https?://#', $href, "route() renders an absolute URL, but got \"{$href}\".");
            $this->assertSame(1, substr_count($row, '<span>'), 'Every row carries exactly one label span.');
            $this->assertSame(1, substr_count($row, '</a>'), 'A row is one anchor, not a nested one.');
            if (str_contains($opening, 'aria-current="page"')) {
                $current++;
            }
        }
        $this->assertSame(1, $current, 'On a page that owns a row, exactly one row is current.');

        // And the one thing a bare-path comparison would silently miss: the path
        // component still has to be the route's own path.
        $timetable = $this->row('Timetable', route('academic-timetables.index'), $aside);
        $this->assertSame($this->path('academic-timetables.index'), $this->pathFromHref($this->hrefOf($timetable)));
    }

    /* 12 · The rails the rest of the suite stands on ---------------------- */

    public function test_the_sidebar_still_renders_exactly_one_landmark_and_its_heading(): void
    {
        $college = $this->makeCollege('STRUCT18');
        $user = $this->makeUserWithPermissions($college, ['students.view']);
        $html = $this->asCollege($college, $user)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, '<aside'), 'The sidebar is one landmark.');
        $this->assertSame(1, substr_count($html, 'aria-label="College ERP navigation"'), 'It keeps its accessible name.');
        $this->assertStringContainsString('class="erp-sidebar no-print"', $html, 'It keeps its no-print hook, so the navigation never lands on paper.');
    }

    /** @return array<string, int> group id => number of sub-group labels inside it */
    private function labelCounts(string $aside): array
    {
        preg_match_all('/data-nav-group="([a-z-]+)"(.*?)(?=<li class="nav-group"|$)/s', $aside, $matches, PREG_SET_ORDER);

        $counts = [];
        foreach ($matches as [, $id, $body]) {
            $counts[$id] = substr_count($body, 'data-nav-sub-label>');
        }

        return $counts;
    }
}
