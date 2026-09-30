<?php

namespace Tests\Feature\ConsolidatedReports;

use App\Http\Controllers\ConsolidatedReportController;
use App\Models\College;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Students\StudentTestHelpers;
use Tests\TestCase;

/**
 * REPORTS → Consolidated Reports — the read-only cross-module reporting screen.
 *
 * Covers: the exact thirteen reports and their order, the per-report filter
 * vocabulary, the dedicated `consolidated_reports.view` permission and its RBAC
 * independence from every operational and module-report permission, the seeded
 * admin grants, the single sidebar entry for the module (its thirteen reports
 * live in the page's own report switcher, never in the sidebar), the
 * switcher's active tab, the GET-only read path and the fallback behaviour of
 * the `report` parameter.
 */
class ConsolidatedReportsTest extends TestCase
{
    use ConsolidatedReportsTestHelpers;
    use StudentTestHelpers;

    private const VIEW = ['consolidated_reports.view'];

    /** Operational permissions of the modules the consolidated screen summarizes. */
    private const OPERATIONAL = [
        'students.view', 'student_enrollments.view', 'student_reports.view',
        'academic_sections.view', 'academic_attendance.view', 'academic_reports.view',
        'examinations.view', 'results.view', 'examination_reports.view',
        'fee_collections.view', 'fee_dues.view', 'finance_reports.view',
        'faculties.view', 'hr_reports.view',
        'books.view', 'library_transactions.view', 'library_reports.view',
        'vehicles.view', 'transport_routes.view', 'transport_reports.view',
        'hostels.view', 'hostel_allocations.view', 'hostel_reports.view',
        'inventory_items.view', 'inventory_current_stock.view', 'inventory_reports.view',
        'notices.view', 'communication_logs.view', 'communication_reports.view',
        'certificates.view', 'certificate_reports.view',
    ];

    /** @var array<string, string> */
    private const EXPECTED_REPORTS = [
        'dashboard' => 'College Dashboard Summary',
        'student_strength' => 'Student Strength Summary',
        'academic' => 'Academic Summary',
        'examination' => 'Examination Summary',
        'finance' => 'Fee / Finance Summary',
        'hr' => 'HR Summary',
        'library' => 'Library Summary',
        'transport' => 'Transport Summary',
        'hostel' => 'Hostel Summary',
        'inventory' => 'Inventory / Asset Summary',
        'communication' => 'Communication Summary',
        'certificate' => 'Certificate Summary',
        'management' => 'Management / MIS Reports',
    ];

    private function reporter(College $college): User
    {
        $user = $this->makeUserWithPermissions($college, self::VIEW);
        $this->asCollege($college, $user);

        return $user;
    }

    /* ------------------------------------------------------------------ *\
     * The thirteen reports and their filters
     * \* ------------------------------------------------------------------ */

    public function test_the_thirteen_consolidated_reports_keep_their_exact_names_and_order(): void
    {
        $this->assertSame(self::EXPECTED_REPORTS, ConsolidatedReportController::REPORTS);
        $this->assertSame(array_keys(self::EXPECTED_REPORTS), array_keys(ConsolidatedReportController::FILTERS));

        $college = $this->makeCollege('CREPLIST');
        $this->reporter($college);

        foreach (self::EXPECTED_REPORTS as $key => $label) {
            $this->get(route('consolidated-reports.index', ['report' => $key]))
                ->assertOk()
                ->assertViewHas('report', $key)
                ->assertViewHas('visible', ConsolidatedReportController::FILTERS[$key])
                ->assertSee($label);
        }

        // An unknown report key falls back to the first report, never to an error.
        $this->get(route('consolidated-reports.index', ['report' => 'not-a-report']))
            ->assertOk()
            ->assertViewHas('report', 'dashboard');

        // The default screen (no query string at all) is the College Dashboard.
        $this->get(route('consolidated-reports.index'))
            ->assertOk()
            ->assertViewHas('report', 'dashboard');
    }

    public function test_each_report_only_offers_the_filters_its_own_module_service_supports(): void
    {
        $this->assertSame([
            'dashboard' => ['academic_year_id', 'program_id'],
            'student_strength' => ['academic_year_id', 'program_id', 'section_id', 'student_status', 'enrollment_status'],
            'academic' => ['academic_year_id', 'academic_term_id', 'program_id', 'section_id'],
            'examination' => ['academic_year_id', 'academic_term_id', 'program_id', 'examination_id'],
            'finance' => ['academic_year_id', 'program_id'],
            'hr' => ['department_id', 'designation_id', 'from', 'to'],
            'library' => [],
            'transport' => [],
            'hostel' => [],
            'inventory' => ['threshold'],
            'communication' => ['from', 'to'],
            'certificate' => ['certificate_type_id', 'from', 'to'],
            'management' => ['academic_year_id', 'program_id'],
        ], ConsolidatedReportController::FILTERS);

        // The date windows say what date they mean.
        $this->assertSame('Joining date', ConsolidatedReportController::DATE_LABELS['hr']);
        $this->assertSame('Communication date', ConsolidatedReportController::DATE_LABELS['communication']);
        $this->assertSame('Request date', ConsolidatedReportController::DATE_LABELS['certificate']);

        $college = $this->makeCollege('CREPFILTER');
        $this->reporter($college);

        // A vocabulary is enforced where the report uses it ...
        $this->get(route('consolidated-reports.index', ['report' => 'student_strength', 'student_status' => 'bogus']))
            ->assertSessionHasErrors('student_status');

        // ... and a parameter the report cannot use is ignored, not judged.
        $this->get(route('consolidated-reports.index', ['report' => 'library', 'student_status' => 'bogus']))
            ->assertOk()
            ->assertViewHas('visible', []);

        // The Academic and Examination summaries publish a `sections` /
        // `examinations` payload of their own; the filter dropdowns must never
        // shadow it (they use the *Options keys), so each page renders its own
        // tables next to the filter form.
        $academic = $this->get(route('consolidated-reports.index', ['report' => 'academic']))->assertOk();
        $academic->assertSee('Section / class strength')->assertSee('Attendance register by status');
        $this->assertStringContainsString('name="section_id"', $academic->getContent());
        $this->assertStringNotContainsString('name="examination_id"', $academic->getContent());

        $examination = $this->get(route('consolidated-reports.index', ['report' => 'examination']))->assertOk();
        $examination->assertSee('Examination summary')->assertSee('Pass rate');
        $this->assertStringContainsString('name="examination_id"', $examination->getContent());

        // The inventory low-stock threshold is numeric only where it is offered.
        $this->get(route('consolidated-reports.index', ['report' => 'inventory', 'threshold' => 'abc']))
            ->assertSessionHasErrors('threshold');
        $this->get(route('consolidated-reports.index', ['report' => 'hostel', 'threshold' => 'abc']))
            ->assertOk();

        // The report switcher carries exactly the thirteen views in order and
        // marks the active one.
        $html = $this->get(route('consolidated-reports.index', ['report' => 'library']))->assertOk()->getContent();
        $navStart = strpos($html, 'aria-label="Consolidated report views"');
        $this->assertNotFalse($navStart, 'The report switcher must be present.');
        $nav = substr($html, $navStart, strpos($html, '</nav>', $navStart) - $navStart);
        $this->assertSame(13, substr_count($nav, 'href='));
        $previous = -1;
        foreach (self::EXPECTED_REPORTS as $key => $label) {
            $position = strpos($nav, $label);
            $this->assertNotFalse($position, "The switcher must contain '{$label}'.");
            $this->assertGreaterThan($previous, $position, "Label '{$label}' must keep its exact position.");
            $this->assertStringContainsString('report='.$key, $nav);
            $previous = $position;
        }
        $this->assertStringContainsString('aria-current="page"', $html);
        $this->assertStringContainsString('bg-indigo-600 text-white', $html);
    }

    /* ------------------------------------------------------------------ *\
     * RBAC
     * \* ------------------------------------------------------------------ */

    public function test_consolidated_reports_are_gated_by_their_own_permission(): void
    {
        $college = $this->makeCollege('CREPRBAC');

        // Guests are redirected to login.
        $this->get(route('consolidated-reports.index'))->assertRedirect(route('login'));

        // Every operational permission (and every module report permission)
        // together still does NOT open the consolidated screen.
        $operator = $this->makeUserWithPermissions($college, self::OPERATIONAL);
        foreach (array_keys(self::EXPECTED_REPORTS) as $report) {
            $this->asCollege($college, $operator)
                ->get(route('consolidated-reports.index', ['report' => $report]))
                ->assertForbidden();
        }
        $this->asCollege($college, $operator)->get(route('students.index'))->assertOk()
            ->assertDontSee(route('consolidated-reports.index'), false)
            ->assertDontSee('Consolidated Reports');
        $this->asCollege($college, $operator)->get(route('library-reports.index'))->assertOk()
            ->assertDontSee(route('consolidated-reports.index'), false);

        // The consolidated permission alone opens every consolidated view but
        // neither an operational screen nor any module report screen.
        $this->reporter($college);
        foreach (array_keys(self::EXPECTED_REPORTS) as $report) {
            $this->get(route('consolidated-reports.index', ['report' => $report]))->assertOk();
        }
        $this->get(route('students.index'))->assertForbidden();
        $this->get(route('library-transactions.index'))->assertForbidden();
        $this->get(route('library-reports.index'))->assertForbidden();
        $this->get(route('finance-reports.index'))->assertForbidden();
        $this->get(route('certificate-reports.index'))->assertForbidden();

        // A role held in one college is not a grant in another college.
        $other = $this->makeCollege('CREPRBAC2');
        $user = $this->makeUserWithPermissions($college, self::VIEW);
        $user->colleges()->attach($other->id);
        $this->asCollege($other, $user)->get(route('consolidated-reports.index'))->assertForbidden();
    }

    public function test_the_seeded_admin_roles_hold_the_consolidated_reports_permission(): void
    {
        $permission = Permission::query()->where('slug', 'consolidated_reports.view')->firstOrFail();
        $this->assertSame('consolidated_reports', $permission->module);
        $this->assertSame('view', $permission->action);

        $college = College::query()->where('code', 'DEMO')->firstOrFail();
        $super = Role::query()->whereNull('college_id')->where('slug', 'super-admin')->firstOrFail();
        $admin = Role::query()->where('college_id', $college->id)->where('slug', 'college-admin')->firstOrFail();

        $this->assertTrue($super->permissions()->whereKey($permission->id)->exists(), 'Super Admin must hold consolidated_reports.view.');
        $this->assertTrue($admin->permissions()->whereKey($permission->id)->exists(), 'College Admin must hold consolidated_reports.view.');

        // The consolidated permission is not one of the module permissions.
        $this->assertNotContains('consolidated_reports.view', self::OPERATIONAL);
    }

    /* ------------------------------------------------------------------ *\
     * Sidebar placement
     * \* ------------------------------------------------------------------ */

    public function test_the_reports_section_lists_one_consolidated_entry_without_the_child_links(): void
    {
        $college = $this->makeCollege('CREPMENU');
        $user = $this->makeUserWithPermissions($college, [...self::VIEW, 'library_reports.view']);
        $html = $this->asCollege($college, $user)->get(route('consolidated-reports.index'))->assertOk()->getContent();

        $sidebar = $this->sidebar($html);

        // REPORTS exists exactly once and Consolidated Reports is its last entry,
        // immediately before the next sidebar section.
        $this->assertSame(1, substr_count($sidebar, '>REPORTS<'));
        $reports = strpos($sidebar, '>REPORTS<');
        $entry = strpos($sidebar, route('consolidated-reports.index'), (int) $reports);
        $this->assertNotFalse($entry, 'The REPORTS section must link Consolidated Reports.');
        $this->assertStringContainsString('<span>Consolidated Reports</span>', substr($sidebar, $entry, 220));
        $this->assertStringContainsString('class="nav-link"', substr($sidebar, max(0, $entry - 60), 60));

        $entryEnd = strpos($sidebar, '</a>', (int) $entry) + 4;
        $platform = strpos($sidebar, '>ADMINISTRATION / SETTINGS<', $entryEnd);
        $platform = $platform === false ? strpos($sidebar, '</nav>', $entryEnd) : $platform;
        $this->assertNotFalse($platform, 'REPORTS must end at Administration / Settings or the sidebar boundary.');
        $this->assertStringNotContainsString('class="nav-link"', substr($sidebar, $entryEnd, $platform - $entryEnd));

        // The module is ONE sidebar entry: none of the thirteen reports is
        // linked (or even named) in the sidebar.
        $this->assertSame(0, substr_count($sidebar, 'report='), 'The sidebar must not link a single consolidated report.');
        $this->assertSame(1, substr_count($sidebar, 'Consolidated Reports'));
        foreach (self::EXPECTED_REPORTS as $key => $label) {
            $this->assertStringNotContainsString($label, $sidebar, "The sidebar must not list {$label}.");
            $this->assertStringNotContainsString("report={$key}", $sidebar);
        }

        // The thirteen reports stay available inside the page: the report
        // switcher still carries all of them, in the required order.
        $switcherStart = strpos($html, 'aria-label="Consolidated report views"');
        $this->assertNotFalse($switcherStart, 'The in-page report switcher must be present.');
        $switcher = substr($html, $switcherStart, strpos($html, '</nav>', $switcherStart) - $switcherStart);
        $this->assertSame(13, substr_count($switcher, 'report='));
        $previous = -1;
        foreach (self::EXPECTED_REPORTS as $key => $label) {
            $position = strpos($switcher, $label);
            $this->assertNotFalse($position, "The switcher must offer {$label}.");
            $this->assertGreaterThan($previous, $position, "{$label} must keep its exact position.");
            $previous = $position;
        }

        // A user without the permission never sees the module — not even the label.
        $without = $this->makeUserWithPermissions($college, ['library_reports.view']);
        $plain = $this->asCollege($college, $without)->get(route('library-reports.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString(route('consolidated-reports.index'), $plain);
        $this->assertStringNotContainsString('Consolidated Reports', $plain);

        // A user holding only the consolidated permission still sees REPORTS,
        // with the single module entry.
        $solo = $this->makeUserWithPermissions($college, self::VIEW);
        $soloSidebar = $this->sidebar($this->asCollege($college, $solo)->get(route('consolidated-reports.index'))->assertOk()->getContent());
        $this->assertStringContainsString('>REPORTS<', $soloSidebar);
        $this->assertSame(1, substr_count($soloSidebar, route('consolidated-reports.index')));
        $this->assertSame(0, substr_count($soloSidebar, 'report='));
    }

    /** The rendered sidebar, so module-entry assertions are scoped to it. */
    private function sidebar(string $html): string
    {
        $start = strpos($html, '<aside');
        $this->assertNotFalse($start, 'The layout must render a sidebar.');

        return substr($html, $start, strpos($html, '</aside>', $start) - $start);
    }

    /* ------------------------------------------------------------------ *\
     * Read-only
     * \* ------------------------------------------------------------------ */

    public function test_the_consolidated_screen_is_read_only_and_changes_no_record(): void
    {
        $college = $this->makeCollege('CREPREAD');
        $this->makeConsolidatedWorld($college, 'READ');
        $this->reporter($college);

        $tables = [
            'students', 'student_enrollments', 'examinations', 'exam_results',
            'fee_payments', 'student_fee_assignments', 'books', 'book_copies',
            'library_transactions', 'vehicles', 'transport_routes', 'hostel_beds',
            'hostel_allocations', 'inventory_items', 'notices', 'communication_logs',
            'certificates',
        ];
        $before = collect($tables)->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])->all();
        $touched = DB::table('students')->max('updated_at');

        foreach (array_keys(self::EXPECTED_REPORTS) as $report) {
            $this->get(route('consolidated-reports.index', ['report' => $report]))->assertOk();
        }

        foreach ($tables as $table) {
            $this->assertSame($before[$table], DB::table($table)->count(), "{$table} must not change.");
        }
        $this->assertSame($touched, DB::table('students')->max('updated_at'));

        // There is no write route for the module: the only route is a GET.
        $this->post('consolidated-reports')->assertStatus(405);
        $this->delete('consolidated-reports')->assertStatus(405);
    }
}
