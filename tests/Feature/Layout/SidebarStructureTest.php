<?php

namespace Tests\Feature\Layout;

use App\Models\{College, Permission};
use Illuminate\Support\Facades\Route;
use Tests\Feature\Departments\DepartmentTestHelpers;
use Tests\TestCase;

/**
 * The shape of the rebuilt navigation tree.
 *
 * Covers the single-college Platform → Academics consolidation and the flat
 * module-link layout: every retained module destination remains in order,
 * without subgroup headings, and Campuses is not a sidebar destination.
 *
 * What this file guards, and why each part exists:
 *
 *  1. Platform is not a group any more — in the source and in the rendered sidebar.
 *  2. The six academic destinations retained from it are unchanged under
 *     Academics, and nothing else was added to that group. The row order of every
 *     other group is pinned too, because a merge is exactly the kind of edit that
 *     quietly shuffles the rest of the tree.
 *  3. No subgroup headings render in the shared sidebar.
 *  4. No label reads as two destinations joined by a slash.
 *  5. No two rows offer the same destination.
 *  6. Every destination the sidebar names is a route that still exists.
 *  7. The skip link is first, points at a focusable landmark, and is styled by the
 *     static stylesheet.
 *  8. Active state is still server-rendered, so it survives a refresh and works
 *     with JavaScript disabled.
 */
class SidebarStructureTest extends TestCase
{
    use DepartmentTestHelpers;

    /** The module groups that must render, in document order (Students registration + Central Approval). */
    private const MODULE_GROUPS = [
        'approval-management', 'students', 'certificates', 'academics', 'examinations', 'finance',
        'transport', 'library', 'hostel', 'communication', 'inventory', 'hr', 'reports',
    ];

    /**
     * The six academic rows retained from the old top-level `Platform` group.
     * Their route names and destinations are unchanged. Campuses is deliberately
     * absent from the single-college sidebar, but its route and data model remain.
     */
    private const MOVED_FROM_PLATFORM = [
        'programs.index',
        'academic-years.index',
        'academic-terms.index',
        'sections.index',
        'subjects.index',
        'faculty-subject-assignments.index',
    ];

    /** The Academics group: five structure rows and six operation rows. */
    private const ACADEMICS_ROWS = [
        'programs.index',
        'academic-years.index',
        'academic-terms.index',
        'sections.index',
        'subjects.index',
        'academic-subject-enrollments.index',
        'faculty-subject-assignments.index',
        'academic-timetables.index',
        'academic-attendance.index',
        'academic-calendar.index',
        'academic-workload.index',
    ];

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
        // Merit / Selection was removed with the standalone Admission sidebar group.
        'Bulk Registration / Import' => 'Bulk Registration &amp; Import',
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
     * The element has to be matched whole: `<span>Programs</span>` sits *between* the
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

    /**
     * The opening `<li …>` tag of one module group.
     *
     * Matched with `[^>]*` between the attributes rather than by spelling the tag
     * out, because the whitespace inside it is NOT a contract: `group.blade.php`
     * emits `… data-nav-open="{{ … }}" @if ($open) data-nav-active="true" @endif>`,
     * and Blade preserves the literal text around a conditional, so an open group
     * renders with the condition's own leading space AND the space that preceded
     * `@if` — two spaces, where a hand-written expectation would assume one.
     */
    private function groupTag(string $markup, string $id): string
    {
        preg_match_all('/<li\s[^>]*\bdata-nav-group="'.preg_quote($id, '/').'"[^>]*>/', $markup, $matches);

        $this->assertCount(1, $matches[0], "The sidebar must render exactly one {$id} group element.");

        return $matches[0][0];
    }

    /** The one `<a>` element that is marked as the current page. */
    private function currentRow(string $markup): string
    {
        preg_match_all('/<a\s[^>]*aria-current="page"[^>]*>.*?<\/a>/s', $markup, $matches);

        $this->assertCount(1, $matches[0], 'Exactly one row must be marked as the current page.');

        return $matches[0][0];
    }

    /** Every navigation-row label in document order. */
    private function labels(string $aside): array
    {
        preg_match_all('/<span>([^<]+)<\/span>/', $aside, $matches);

        return $matches[1];
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

    /* 2 · Its retained destinations live under Academics ----------------- */

    public function test_every_retained_platform_destination_is_rendered_inside_academics(): void
    {
        $aside = $this->dashboardForFullAccess($this->makeCollege('STRUCT3'));
        $academics = $this->group($aside, 'Academics');

        $this->assertSame(11, substr_count($academics, 'class="nav-link"'), 'Academics keeps its six existing operation rows plus the five retained academic structure rows.');
        $this->assertSame(
            $this->paths(self::ACADEMICS_ROWS),
            $this->hrefs($academics),
            'The merged Academics group must render exactly these destinations, in this order, and nothing else.'
        );

        foreach (self::MOVED_FROM_PLATFORM as $name) {
            $this->assertTrue(Route::has($name), "{$name} must still exist: the move changed the menu, not the route.");
        }
    }

    public function test_campuses_is_not_a_sidebar_destination(): void
    {
        $source = (string) file_get_contents(resource_path('views/layouts/sidebar.blade.php'));
        $aside = $this->dashboardForFullAccess($this->makeCollege('STRUCT3A'));
        $campusPath = $this->path('campuses.index');

        $this->assertStringNotContainsString('label="Campuses"', $source, 'The Campus row must not remain anywhere in the sidebar source.');
        $this->assertStringNotContainsString('<span>Campuses</span>', $aside, 'Campuses must not be rendered in any sidebar group.');
        $this->assertNotContains($campusPath, $this->hrefs($aside), 'The Campus URL must not be offered by another sidebar row.');
        $this->assertTrue(Route::has('campuses.index'), 'Hiding the sidebar row must not delete the existing Campus route or its authorization boundary.');
    }

    public function test_the_moved_rows_keep_their_own_labels_and_routes(): void
    {
        $aside = $this->dashboardForFullAccess($this->makeCollege('STRUCT4'));
        $academics = $this->group($aside, 'Academics');

        foreach ([
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

        // The Academic Sections destination remains functional, but is no longer
        // duplicated as a separate sidebar option.
        $this->assertFalse(Route::has('classes.index'), 'There is no Classes route to link to, and none may be invented.');
        $this->assertTrue(Route::has('academic-sections.index'), 'The Academic Sections route remains available without a sidebar entry.');
        $this->assertStringNotContainsString('>Classes &amp; Sections</span>', $academics, 'The unneeded menu row is absent.');
        $this->assertNotContains($this->path('academic-sections.index'), $this->hrefs($academics), 'The Academic Sections route is not a sidebar destination.');
        $this->assertStringContainsString('>Sections</span>', $academics, 'The setup-level Sections row keeps its own clean label.');
        $this->assertSame(
            $this->path('sections.index'),
            $this->pathFromHref($this->hrefOf($this->row('Sections', route('sections.index'), $academics))),
            'The setup-level Sections row keeps its own destination.'
        );
        $this->assertNotSame($this->path('sections.index'), $this->path('academic-sections.index'), 'The standalone Sections screen remains distinct from Academic Sections.');
    }

    /* 3 · Admission is no longer a standalone sidebar module ----------------- */

    public function test_admission_module_is_not_a_standalone_sidebar_group(): void
    {
        $aside = $this->dashboardForFullAccess($this->makeCollege('STRUCT6'));

        $this->assertStringNotContainsString('data-nav-group="admission"', $aside, 'Admission must not be a top-level sidebar group.');
        $this->assertStringNotContainsString('nav-group__label">Admission<', $aside, 'The standalone Admission module label must be gone.');
        $this->assertStringNotContainsString('data-nested-group="admission-register-legacy"', $aside, 'No legacy Admission submenu may remain in primary navigation.');
        $this->assertStringNotContainsString('data-nested-group="admission-transfers"', $aside);
        $this->assertStringNotContainsString('data-nested-group="admission-settings"', $aside);

        foreach (['Applications', 'Applicants', 'Enquiries', 'Admission Documents', 'Merit &amp; Selection', 'Final Admissions', 'Status Types'] as $label) {
            $this->assertStringNotContainsString('>'.$label.'</span>', $aside, $label.' must not remain as a sidebar navigation row.');
        }

        // The underlying legacy routes/controllers remain registered.
        foreach ([
            'admission-applications.index', 'admission-applicants.index', 'admission-enquiries.index',
            'admission-documents.index', 'admission-merit-lists.index', 'admissions.index',
            'admission.dashboard', 'admission.status-types.index', 'admission-reports.index',
        ] as $routeName) {
            $this->assertTrue(Route::has($routeName), "{$routeName} must remain registered for legacy workflows.");
        }
    }

    public function test_nested_group_headers_use_the_standard_child_row_style_contract(): void
    {
        $css = (string) file_get_contents(public_path('css/erp-sidebar.css'));

        $this->assertSame(1, preg_match('/^\.nav-group--nested\s*\{(?<style>[^}]*)\}/m', $css, $group));
        $this->assertStringContainsString('margin: 0;', $group['style'], 'Nested groups use the parent gap instead of adding vertical margins.');

        $this->assertSame(1, preg_match('/\.nav-group--nested \.nav-group__head--nested\s*\{(?<style>[^}]*)\}/', $css, $header));
        $this->assertStringContainsString('height: var(--erp-nav-child-h);', $header['style']);
        $this->assertStringContainsString('padding: 0 var(--erp-nav-pad-x) 0 0;', $header['style']);
        $this->assertStringContainsString('font-size: 13px;', $header['style']);
        $this->assertStringContainsString('border-radius: 10px;', $header['style']);
        $this->assertStringNotContainsString('background', $header['style'], 'The resting surface comes from the standard transparent header, not a permanent pressed background.');

        $this->assertSame(1, preg_match('/\.nav-group--nested \.nav-group__label--nested\s*\{(?<style>[^}]*)\}/', $css, $label));
        $this->assertStringContainsString('color: inherit;', $label['style'], 'Nested labels brighten on hover exactly like standard child links.');
        $this->assertStringContainsString('font-weight: 400;', $label['style']);
        $this->assertStringContainsString('.nav-group[data-nav-active="true"]:not(.nav-group--nested) > .nav-group__head', $css, 'Only top-level modules use the active parent background.');
    }

    public function test_legacy_admission_routes_stay_authorized_without_a_sidebar_group(): void
    {
        $college = $this->makeCollege('STRUCT6B');
        $viewer = $this->makeUserWithPermissions($college, ['admission_applicants.view']);
        $outsider = $this->makeUserWithPermissions($college, ['students.view']);

        $html = $this->asCollege($college, $viewer)
            ->get(route('admission-applicants.index'))
            ->assertOk()
            ->getContent();
        $aside = $this->aside($html);

        $this->assertStringNotContainsString('data-nav-group="admission"', $aside, 'A legacy route may render, but it must not resurrect the Admission menu.');
        $this->assertStringNotContainsString('data-nav-active="true"', $aside, 'No removed Admission group should claim active state.');

        $this->asCollege($college, $outsider)
            ->get(route('admission-applicants.index'))
            ->assertForbidden();

        $this->assertTrue(Route::has('admission-applicants.index'));
        $this->assertTrue(Route::has('admission-enquiries.index'));
    }

    public function test_academics_destinations_render_flat_in_their_existing_order(): void
    {
        $aside = $this->dashboardForFullAccess($this->makeCollege('STRUCT7'));
        $academics = $this->group($aside, 'Academics');

        $this->assertSame(
            $this->paths(self::ACADEMICS_ROWS),
            $this->hrefs($academics),
            'All eleven remaining Academics destinations stay directly under the module in the established order.'
        );
        $this->assertStringNotContainsString('data-nav-sub-label', $academics);
    }

    /* 4 · Row order is preserved outside the merged group ----------------- */

    public function test_rows_keep_their_original_relative_order(): void
    {
        // The merge is the only edit that moved rows, and it moved exactly one row
        // set: the six retained Platform destinations, of which Faculty–Subject
        // Assignments now sits beside Student Subject Enrollment. Every other group keeps the
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
                'students.registration.create', 'students.index', 'students.import.index', 'student-enrollments.index',
                'student-academic-records.index', 'student-documents.index', 'admission-document-types.index',
                'student-id-cards.index', 'student-promotions.index', 'student-transfers.index', 'student-history.index',
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
        // Students is now the primary registration module. It keeps all nine
        // original lifecycle destinations and adds Registration + Document Types.
        $aside = $this->dashboardForFullAccess($this->makeCollege('STRUCT10'));
        $students = $this->group($aside, 'Students');

        $this->assertSame(11, substr_count($students, 'class="nav-link"'), 'Every Students destination must still render.');
        $this->assertStringNotContainsString('data-nav-sub-label', $students, 'Students has no subgroup headings.');
        $this->assertSame(
            $this->path('students.registration.create'),
            $this->hrefs($students)[0],
            'Registration is the primary Students entry point.'
        );
        $this->assertSame(
            $this->path('students.index'),
            $this->pathFromHref($this->hrefOf($this->row('Student List', route('students.index'), $students))),
            'The Student List row keeps its destination.'
        );
        $this->assertSame(
            $this->paths([
                'students.import.index', 'student-enrollments.index', 'student-academic-records.index',
                'student-documents.index', 'admission-document-types.index', 'student-id-cards.index',
                'student-promotions.index', 'student-transfers.index', 'student-history.index',
            ]),
            array_slice($this->hrefs($students), 2),
            'The lifecycle rows remain, with Document Types exposed through Students.'
        );
    }

    public function test_admission_group_is_removed_but_legacy_routes_remain_registered(): void
    {
        // Students owns registration, list, profile and lifecycle. Admission
        // routes remain for in-flight/historical workflows and reports.
        $aside = $this->dashboardForFullAccess($this->makeCollege('STRUCT10A'));

        $this->assertStringNotContainsString('data-nav-group="admission"', $aside, 'The Admission group must be removed from the sidebar.');
        $this->assertStringContainsString('data-nav-group="students"', $aside, 'Students remains the primary student module.');
        $this->assertStringContainsString($this->path('students.registration.create'), $aside, 'Students → Registration remains reachable.');
        $this->assertStringContainsString($this->path('students.index'), $aside, 'Students → Student List remains reachable.');

        foreach ([
            'admission-applications.index', 'admission-applicants.index', 'admission-enquiries.index',
            'admission-documents.index', 'admission-merit-lists.index', 'admissions.index',
            'admission.dashboard', 'admission.status-types.index', 'admission-reports.index',
        ] as $routeName) {
            $this->assertTrue(Route::has($routeName), "{$routeName} must remain registered.");
            $this->assertStringNotContainsString($this->path($routeName), $aside, "{$routeName} must not be linked from primary navigation.");
        }
    }

    public function test_approval_management_group_exists(): void
    {
        $aside = $this->dashboardForFullAccess($this->makeCollege('STRUCT10A2'));
        $group = $this->group($aside, 'Approval Management');
        $this->assertSame(4, substr_count($group, 'class="nav-link"'), 'Approval Management must have its 4 children.');
        $this->assertStringContainsString('Pending Approvals', $group);
        $this->assertStringContainsString('Approval History', $group);
        $this->assertStringContainsString('My Requests', $group);
        $this->assertStringContainsString('Approval Settings', $group);
    }

    public function test_hr_is_the_last_module_group_before_reports(): void
    {
        // The HR group keeps its id, its permission gate and every one of its seven
        // rows. Only its position in the tree moved: from first to last, immediately
        // before Reports.
        $aside = $this->dashboardForFullAccess($this->makeCollege('STRUCT10B'));

        $inventory = strpos($aside, 'nav-group__label">Inventory<');
        $hr = strpos($aside, 'nav-group__label">Human Resource (HR)<');
        $reports = strpos($aside, 'nav-group__label">Reports<');

        $this->assertNotFalse($inventory);
        $this->assertNotFalse($hr);
        $this->assertNotFalse($reports);
        $this->assertTrue($inventory < $hr, 'HR must no longer sit above Inventory, which now opens the module groups.');
        $this->assertTrue($hr < $reports, 'HR must sit immediately before Reports.');

        $this->assertStringContainsString('data-nav-group="hr" data-nav-open=', $aside, 'The HR group keeps its id and its open-state attribute.');
        $this->assertSame(7, substr_count($this->group($aside, 'Human Resource (HR)'), 'class="nav-link"'), 'All seven HR rows moved with the group.');
    }

    /* 5 · Permission filtering survives flat module rows ----------------- */

    public function test_individual_permissions_still_expose_only_the_matching_flat_link(): void
    {
        $cases = [
            'library_fines.view' => ['Library', 'library-fines.index'],
            'fee_reports.view' => ['Fees', 'fee-reports.index'],
            'result_publishing.view' => ['Examinations', 'result-publishing.index'],
            'consolidated_reports.view' => ['Reports', 'consolidated-reports.index'],
            'inventory_low_stock.view' => ['Inventory', 'inventory-low-stock.index'],
        ];

        $college = $this->makeCollege('STRUCT11');

        foreach ($cases as $permission => [$groupLabel, $routeName]) {
            $group = $this->group($this->dashboardFor($college, [$permission]), $groupLabel);

            $this->assertSame(1, substr_count($group, 'class="nav-link"'), "{$permission} alone must render exactly its authorized row.");
            $this->assertSame($this->paths([$routeName]), $this->hrefs($group), "{$permission} must keep its original destination.");
            $this->assertStringNotContainsString('data-nav-sub-label', $group, 'Permission filtering must not require subgroup headings.');
        }
    }

    public function test_a_group_with_no_permitted_row_stays_hidden(): void
    {
        $aside = $this->dashboardFor($this->makeCollege('STRUCT12'), ['students.view']);

        $this->assertStringNotContainsString('data-nav-group="library"', $aside, 'A viewer without library permissions sees no Library group.');
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
        $this->assertStringNotContainsString('data-nav-sub-label', $this->group($aside, 'Academics'));
        $this->assertSame(11, substr_count($this->group($aside, 'Academics'), 'class="nav-link"'), 'All eleven remaining rows render for a viewer without academic permissions, as they were ungated before the move.');
    }

    public function test_moved_academic_destinations_keep_their_page_level_authorization(): void
    {
        $college = $this->makeCollege('STRUCT12C');
        $routes = [
            'programs.index' => 'programs.view',
            'academic-years.index' => 'academic-years.view',
            'academic-terms.index' => 'academic_terms.view',
            'sections.index' => 'sections.view',
            'subjects.index' => 'subjects.view',
            'faculty-subject-assignments.index' => 'faculty_subject_assignments.view',
        ];

        foreach ($routes as $routeName => $permission) {
            $outsider = $this->makeUserWithPermissions($college, ['students.view']);
            $viewer = $this->makeUserWithPermissions($college, [$permission]);

            $this->asCollege($college, $outsider)
                ->get(route($routeName))
                ->assertForbidden();
            $this->asCollege($college, $viewer)
                ->get(route($routeName))
                ->assertOk();
        }
    }

    /* 6 · No slash-joined label is left ----------------------------------- */

    public function test_no_navigation_label_joins_two_concepts_with_a_slash(): void
    {
        $aside = $this->dashboardForFullAccess($this->makeCollege('STRUCT13'));
        $labels = $this->labels($aside);

        $joined = array_values(array_filter($labels, fn (string $label): bool => str_contains($label, ' / ')));

        $this->assertGreaterThan(100, count($labels), 'Every navigation-row label must be inspected.');
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

        $criticalStart = strpos($layout, '{{-- Critical shell fallback inline');
        $criticalEnd = strpos($layout, "@if (config('app.debug'))", $criticalStart);
        $this->assertNotFalse($criticalStart);
        $this->assertNotFalse($criticalEnd);
        $critical = substr($layout, $criticalStart, $criticalEnd - $criticalStart);
        $this->assertStringNotContainsString('\\n', $critical, 'Literal backslash-n bytes close <head> early and turn the critical selectors into `n .erp-app-*` selectors.');
        $this->assertStringContainsString("<style>\n", $critical, 'The critical stylesheet starts on a real new line and stays inside <head>.');
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
        // The group element carries the open/active state. Each attribute is
        // asserted on its own, and the element is matched with `[^>]*` between
        // attributes, so the test does not depend on how much whitespace separates
        // them. `group.blade.php` renders
        //
        //   <li class="nav-group" data-nav-group="academics" data-nav-open="true"  data-nav-active="true" >
        //
        // with the doubled space, and the trailing one, that Blade leaves around
        // `@if ($open) … @endif`. That spacing is a rendering detail; the three
        // attributes are the contract. Spelling them out as one exact string is
        // what made this assertion fail on correct markup.
        $group = $this->groupTag($aside, 'academics');

        $this->assertStringContainsString('data-nav-group="academics"', $group, 'The element must be the Academics group.');
        $this->assertStringContainsString('data-nav-open="true"', $group, 'Its group is open server-side, so the current row is visible with JavaScript disabled.');
        $this->assertStringContainsString('data-nav-active="true"', $group, 'Its group is flagged active server-side.');
        $this->assertStringNotContainsString('data-nav-open="false"', $group, 'An open group must not also render as closed.');
        $this->assertStringNotContainsString('data-nav-active="false"', $group, 'The active flag is boolean-present: it is omitted, never rendered as false.');

        // And the flag stays unique — exactly the group that owns the current page,
        // which is the property that makes the highlight correct on a fresh load.
        $this->assertSame(1, substr_count($aside, 'data-nav-active="true"'), 'Exactly one group may be flagged active: the one holding the current page.');

        // Pin the component shape that produces that spacing, so the reason this
        // assertion is whitespace-tolerant is discoverable by whoever reads it next
        // — and it does not get "simplified" back into an exact-string match.
        $groupTemplate = (string) file_get_contents(resource_path('views/components/nav/group.blade.php'));
        $this->assertStringContainsString('data-nav-open="{{ $open ? \'true\' : \'false\' }}"', $groupTemplate,
            'The template decides the spacing of these attributes itself.');
        $this->assertStringContainsString('@if ($open) data-nav-active="true" @endif>', $groupTemplate,
            'An open group appends the active flag inline, which is why its spacing may not be asserted as one string.');

        $this->assertStringNotContainsString('data-nav-sub-label', $aside, 'No subgroup element can ever be marked as a destination.');
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

    public function test_one_server_rendered_sidebar_supports_desktop_and_mobile(): void
    {
        $college = $this->makeCollege('STRUCT18A');
        $user = $this->makeUserWithPermissions($college, ['students.view']);
        $html = $this->asCollege($college, $user)->get(route('dashboard'))->assertOk()->getContent();
        $aside = $this->aside($html);

        // There is one server-rendered navigation tree, not separate desktop and
        // mobile copies that could drift or duplicate destinations.
        $this->assertSame(1, substr_count($html, 'id="erp-sidebar"'));
        $this->assertSame(1, substr_count($html, 'id="erp-nav-list"'));
        $this->assertStringContainsString('data-nav-rail-toggle', $aside, 'Desktop keeps its collapsible icon-rail control.');
        $this->assertStringContainsString('data-nav-drawer-close', $aside, 'Mobile keeps its drawer close control.');
        $this->assertStringContainsString('data-nav-drawer-open', $html, 'The top bar keeps its mobile drawer trigger.');
        $this->assertStringContainsString('data-nav-overlay', $html, 'The mobile drawer keeps its scrim.');

        $css = (string) file_get_contents(public_path('css/erp-sidebar.css'));
        $this->assertMatchesRegularExpression('/@media \(min-width: 1024px\)\s*\{\s*\.erp-sidebar\s*\{[^}]*position: sticky;/s', $css, 'Desktop uses the in-layout sticky sidebar.');
        $this->assertMatchesRegularExpression('/@media \(max-width: 1023\.98px\)\s*\{\s*\.erp-sidebar\s*\{[^}]*position: fixed;/s', $css, 'Mobile uses the off-canvas sidebar.');
        $this->assertMatchesRegularExpression('/html\[data-nav-drawer="open"\] \.erp-sidebar\s*\{\s*transform: none;/s', $css, 'The open mobile drawer is visible.');

        $js = (string) file_get_contents(public_path('js/erp-sidebar.js'));
        $this->assertStringContainsString("var DESKTOP = '(min-width: 1024px)';", $js);
        $this->assertStringContainsString('function setRail(', $js, 'Desktop rail behaviour remains connected.');
        $this->assertStringContainsString('function drawerOpenState(', $js, 'Mobile drawer behaviour remains connected.');
        $this->assertStringContainsString('function syncViewport(', $js, 'Breakpoint crossings clean up drawer and rail state.');
    }

    public function test_the_sidebar_still_renders_exactly_one_landmark_and_its_heading(): void
    {
        $college = $this->makeCollege('STRUCT18');
        $user = $this->makeUserWithPermissions($college, ['students.view']);
        $html = $this->asCollege($college, $user)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, '<aside'), 'The sidebar is one landmark.');
        $this->assertSame(1, substr_count($html, 'aria-label="College ERP navigation"'), 'It keeps its accessible name.');
        $this->assertStringContainsString('class="erp-sidebar no-print"', $html, 'It keeps its no-print hook, so the navigation never lands on paper.');
    }

}
