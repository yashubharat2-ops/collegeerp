<?php

namespace Tests\Feature\FinanceReports;

use App\Domain\Finance\Services\FeeCollectionService;
use App\Domain\Finance\Services\FeeConcessionService;
use App\Domain\Finance\Services\FeeDuesService;
use App\Domain\Finance\Services\FeeRefundService;
use App\Domain\Hostel\Services\HostelFeeService;
use App\Domain\Transport\Services\TransportFeeService;
use App\Http\Controllers\FinanceReportController;
use App\Models\AuditLog;
use App\Models\College;
use App\Models\Department;
use App\Models\FeeConcession;
use App\Models\FeePayment;
use App\Models\FeeRefund;
use App\Models\FeeStructureItem;
use App\Models\Hostel;
use App\Models\HostelAllocation;
use App\Models\HostelBed;
use App\Models\HostelBuilding;
use App\Models\HostelFeeAssignment;
use App\Models\HostelFeeStructure;
use App\Models\HostelRoom;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentAcademicRecord;
use App\Models\StudentEnrollment;
use App\Models\StudentFeeAssignment;
use App\Models\StudentTransportAssignment;
use App\Models\StudentTransportFeeAssignment;
use App\Models\TransportFeeStructure;
use App\Models\TransportRoute;
use App\Models\TransportStop;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Finance\FeeStructureTestHelpers;
use Tests\TestCase;

/**
 * Finance Reports — the read-only reporting layer over the existing Finance /
 * Fees module and the transport + hostel fee charges recorded through it.
 *
 * Covers: the single view permission and its RBAC independence (operational
 * Finance permissions and the pre-existing fee_reports.view stay separate),
 * sidebar placement under REPORTS as the fourth and last report module, the
 * exact eleven report views and their order, tenant isolation including forged
 * foreign filter ids, the filters driven by the existing Finance / Transport /
 * Hostel relationships, the financial figures (every one read from the same
 * ledger / services the operational screens use), soft-deleted, cancelled,
 * rejected and inactive records, deterministic pagination, GET-only routes and
 * query counts that never grow with row counts (N+1 protection).
 *
 * Fixtures reuse the Finance helper lineage and the REAL services
 * (StudentFeeAssignmentService, FeeCollectionService, FeeConcessionService,
 * FeeRefundService, TransportFeeService, HostelFeeService) — never hand-written
 * fee arithmetic.
 */
class FinanceReportsTest extends TestCase
{
    use FeeStructureTestHelpers;

    private const VIEW = ['finance_reports.view'];

    /** Tables the read-only reports must never touch. */
    private const TABLES = [
        'fee_payments', 'fee_concessions', 'fee_refunds', 'student_fee_assignments',
        'fee_structures', 'fee_structure_items', 'fee_categories',
        'student_transport_fee_assignments', 'hostel_fee_assignments',
    ];

    /* ------------------------------------------------------------------ *
     * Permission / RBAC
     * ------------------------------------------------------------------ */

    public function test_the_dedicated_permission_is_read_only_and_separate_from_finance_permissions(): void
    {
        $permission = Permission::where('slug', 'finance_reports.view')->firstOrFail();
        $this->assertSame('view', $permission->action);
        $this->assertSame('finance_reports', $permission->module);
        $this->assertTrue(Role::where('slug', 'college-admin')->firstOrFail()->permissions()->whereKey($permission->id)->exists());
        $this->assertTrue(Role::where('slug', 'super-admin')->firstOrFail()->permissions()->whereKey($permission->id)->exists());
        $this->assertFalse(Permission::whereIn('slug', [
            'finance_reports.create', 'finance_reports.update', 'finance_reports.delete', 'finance_reports.export',
        ])->exists());
        $this->assertFalse(Schema::hasTable('finance_reports'));
        $this->seed(); // idempotent
        $this->assertSame(1, Permission::where('slug', 'finance_reports.view')->count());

        // Kept separate from the pre-existing Fee Reports permission.
        $legacy = Permission::where('slug', 'fee_reports.view')->firstOrFail();
        $this->assertNotSame($legacy->id, $permission->id);

        $college = $this->makeCollege('FINRPERM');
        $this->get(route('finance-reports.index'))->assertRedirect(route('login'));

        // Operational Finance permissions do NOT grant the reports.
        $operator = $this->makeUserWithPermissions($college, [
            'fee_structures.view', 'fee_categories.view', 'student_fee_assignments.view',
            'fee_collections.view', 'receipts.view', 'fee_dues.view',
            'fee_concessions.view', 'refunds.view', 'fee_reports.view',
            'transport_fees.view', 'hostel_fees.view',
        ]);
        $this->asCollege($college, $operator)->get(route('fee-dues.index'))->assertOk()
            ->assertDontSee('href="'.route('finance-reports.index').'"', false);
        foreach (array_keys(FinanceReportController::REPORTS) as $report) {
            $this->asCollege($college, $operator)->get(route('finance-reports.index', ['report' => $report]))->assertForbidden();
        }

        // The report permission is independent of the operational screens.
        $reporter = $this->reporter($college);
        $this->get(route('fee-dues.index'))->assertForbidden();
        $this->get(route('fee-reports.index'))->assertForbidden();
        foreach (FinanceReportController::REPORTS as $key => $label) {
            $this->get(route('finance-reports.index', ['report' => $key]))
                ->assertOk()->assertViewHas('report', $key)->assertSee($label);
        }

        // Fee Reports stays behind its own permission.
        $legacyOnly = $this->makeUserWithPermissions($college, ['fee_reports.view']);
        $this->asCollege($college, $legacyOnly)->get(route('fee-reports.index'))->assertOk()
            ->assertDontSee('href="'.route('finance-reports.index').'"', false);
        $this->asCollege($college, $legacyOnly)->get(route('finance-reports.index'))->assertForbidden();

        // A role in one college is not a grant in another college.
        $other = $this->makeCollege('FINRPERM2');
        $reporter->colleges()->attach($other->id);
        $this->asCollege($other, $reporter)->get(route('finance-reports.index'))->assertForbidden();
    }

    /* ------------------------------------------------------------------ *
     * Sidebar placement + exactly 11 entries
     * ------------------------------------------------------------------ */

    public function test_reports_menu_keeps_its_place_and_lists_the_eleven_finance_reports_in_order(): void
    {
        $college = $this->makeCollege('FINRMENU');
        $user = $this->makeUserWithPermissions($college, [
            'inventory_dashboard.view', 'student_reports.view', 'academic_reports.view',
            'examination_reports.view', 'finance_reports.view', 'fee_reports.view',
        ]);
        $html = $this->asCollege($college, $user)->get(route('finance-reports.index'))->assertOk()->getContent();

        $inventory = strpos($html, '>Inventory / Asset Management<');
        $reports = strpos($html, 'nav-group__label">Reports<');
        $student = strpos($html, 'href="'.route('student-reports.index').'"');
        $academic = strpos($html, 'href="'.route('academic-reports.index').'"');
        $examination = strpos($html, 'href="'.route('examination-reports.index').'"');
        $finance = strpos($html, 'href="'.route('finance-reports.index').'"');
        $platform = strpos($html, 'nav-group__label">Administration / Settings<', (int) $reports);
        $platform = $platform === false ? strpos($html, '</nav>', (int) $reports) : $platform;
        $this->assertNotFalse($inventory);
        $this->assertTrue(
            $inventory < $reports && $reports < $student && $student < $academic
            && $academic < $examination && $examination < $finance && $finance < $platform
        );
        $this->assertSame(1, substr_count($html, 'nav-group__label">Reports<'));

        // Four report links live between the Reports group heading and Administration /
        // Settings, and the Finance Reports child is the last one. Rows inside a module
        // are text-only in this sidebar (the 18px icons belong to the module rows), so
        // what is pinned is the nav-group structure plus the row, not an emoji glyph.
        $menu = substr($html, $reports, $platform - $reports);
        $this->assertSame(4, substr_count($menu, 'class="nav-link"'));
        $this->assertStringStartsWith('nav-group__label">Reports</div>', $menu, 'Reports is a collapsible module group heading, not a link.');
        $this->assertStringContainsString('>Finance Reports</span>', $menu, 'Finance Reports keeps its row in the Reports group.');
        $this->assertStringNotContainsString(
            'class="nav-link"',
            substr($menu, (int) strpos($menu, 'href="'.route('finance-reports.index').'"') + 1),
            'Finance Reports is the last row of the section.'
        );
        $this->assertStringContainsString('id="nav-items-reports"', $html, 'The group owns a nav-items container.');
        $head = strrpos($html, '<button type="button" class="nav-group__head" id="nav-head-reports"');
        $this->assertNotFalse($head, 'The heading is rendered by a nav-group head button.');
        $this->assertLessThan($reports, (int) $head, 'The head button precedes its label inside the same group.');
        $this->assertMatchesRegularExpression(
            '/<li class="nav-group"[^>]*data-nav-group="reports"[^>]*data-nav-open="true"[^>]*data-nav-active="true"[^>]*>/',
            $html,
            'The group that owns the current route renders open and active.'
        );

        // The REPORTS heading itself stays plain (no link, no reordering).
        $this->assertStringNotContainsString('<a', substr($html, $reports - 80, 80));

        // Fee Reports stays in the Finance / Fees group, not under REPORTS.
        $this->assertStringNotContainsString('href="'.route('fee-reports.index').'"', $menu);

        // The view switcher carries exactly the eleven entries, in the fixed order.
        $navStart = strpos($html, 'aria-label="Finance report views"');
        $this->assertNotFalse($navStart, 'The report view navigation must be present.');
        $nav = substr($html, $navStart, strpos($html, '</nav>', $navStart) - $navStart);
        $this->assertSame(11, substr_count($nav, 'href='));
        $this->assertSame(11, count(FinanceReportController::REPORTS));
        $previous = -1;
        foreach (FinanceReportController::REPORTS as $key => $label) {
            $position = strpos($nav, $label);
            $this->assertNotFalse($position, "The nav must contain '{$label}'.");
            $this->assertGreaterThan($previous, $position, "Label '{$label}' must keep its exact position in the order.");
            $this->assertStringContainsString('report='.$key, $nav);
            $previous = $position;
        }

        // A user without the permission sees no Finance Reports link at all.
        $outsider = $this->makeUserWithPermissions($college, ['student_reports.view']);
        $this->asCollege($college, $outsider)->get(route('student-reports.index'))->assertOk()
            ->assertDontSee('href="'.route('finance-reports.index').'"', false)
            ->assertDontSee('>Finance Reports<', false);
    }

    /* ------------------------------------------------------------------ *
     * Empty states + invalid filters
     * ------------------------------------------------------------------ */

    public function test_empty_results_invalid_filters_and_irrelevant_filters_are_handled_gracefully(): void
    {
        $college = $this->makeCollege('FINREMPTY');
        $this->reporter($college);

        foreach (array_keys(FinanceReportController::REPORTS) as $report) {
            $page = $this->get(route('finance-reports.index', ['report' => $report]))
                ->assertOk()->assertSee('No ');

            if ($report === 'summary') {
                $page->assertViewHas('summary', fn (array $summary) => $summary['fee_value_total'] === 0.0
                    && $summary['outstanding_total'] === 0.0
                    && $summary['collections']['payments'] === 0
                    && $summary['reconciliation']['balanced'] === true);
            } else {
                $page->assertViewHas('rows', fn ($rows) => $rows->total() === 0);
            }
        }

        $this->get(route('finance-reports.index', ['report' => 'not_a_report']))
            ->assertOk()->assertViewHas('report', 'collection');

        // Date windows and vocabularies are validated per report.
        $this->get(route('finance-reports.index', ['report' => 'collection', 'from' => '2026-10-20', 'to' => '2026-09-01']))
            ->assertSessionHasErrors('to');
        $this->get(route('finance-reports.index', ['report' => 'collection', 'fee_type' => 'made-up']))
            ->assertSessionHasErrors('fee_type');
        $this->get(route('finance-reports.index', ['report' => 'collection', 'payment_mode' => 'made-up']))
            ->assertSessionHasErrors('payment_mode');
        $this->get(route('finance-reports.index', ['report' => 'concession', 'type' => 'made-up']))
            ->assertSessionHasErrors('type');
        $this->get(route('finance-reports.index', ['report' => 'concession', 'status' => 'made-up']))
            ->assertSessionHasErrors('status');
        $this->get(route('finance-reports.index', ['report' => 'due', 'status' => 'made-up']))
            ->assertSessionHasErrors('status');
        $this->get(route('finance-reports.index', ['report' => 'structure', 'academic_year_id' => 'abc']))
            ->assertSessionHasErrors('academic_year_id');

        // Filter keys a report does not use are ignored, not enforced.
        $this->get(route('finance-reports.index', [
            'report' => 'summary', 'hostel_id' => 999999, 'payment_mode' => 'made-up', 'status' => 'made-up',
        ]))
            ->assertOk()
            ->assertViewHas('filters', fn (array $filters) => $filters['hostel_id'] === null
                && $filters['payment_mode'] === null
                && $filters['status'] === null);
        $this->get(route('finance-reports.index', ['report' => 'collection', 'fee_structure_id' => 999999]))
            ->assertOk()
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 0);
    }

    /* ------------------------------------------------------------------ *
     * Tenant isolation + forged foreign ids
     * ------------------------------------------------------------------ */

    public function test_every_report_is_tenant_scoped_even_with_foreign_filter_ids(): void
    {
        $a = $this->makeCollege('FINTA');
        $b = $this->makeCollege('FINTB');
        $worldA = $this->world($a, 'ALPH');
        $worldB = $this->world($b, 'BRAV');
        $this->reporter($a);

        $ownTotals = [
            'collection' => 3, 'due' => 1, 'ledger' => 1, 'structure' => 1, 'assignment' => 1,
            'concession' => 1, 'refund' => 1, 'receipt' => 3, 'transport' => 1, 'hostel' => 1,
        ];
        foreach ($ownTotals as $report => $total) {
            $this->get(route('finance-reports.index', ['report' => $report]))
                ->assertOk()
                ->assertViewHas('rows', fn ($rows) => $rows->total() === $total)
                ->assertDontSee($worldB['student']->student_number)
                ->assertDontSee($worldB['structure']->name);
        }

        // Dropdowns list only this college's master data.
        $this->get(route('finance-reports.index', ['report' => 'collection']))
            ->assertViewHas('students', fn ($list) => $list->pluck('id')->all() === [$worldA['student']->id])
            ->assertViewHas('academicYears', fn ($list) => $list->pluck('id')->all() === [$worldA['ctx']['year']->id])
            ->assertViewHas('programs', fn ($list) => $list->pluck('id')->all() === [$worldA['ctx']['prog']->id])
            ->assertViewHas('sections', fn ($list) => $list->pluck('id')->all() === [$worldA['section']->id])
            ->assertViewHas('feeStructures', fn ($list) => $list->pluck('id')->all() === [$worldA['structure']->id]);
        $this->get(route('finance-reports.index', ['report' => 'structure']))
            ->assertViewHas('academicTerms', fn ($list) => $list->pluck('id')->all() === [$worldA['ctx']['term']->id])
            ->assertViewHas('departments', fn ($list) => $list->pluck('id')->all() === [$worldA['department']->id])
            ->assertViewHas('feeCategories', fn ($list) => $list->pluck('id')->all() === [$worldA['category']->id]);
        $this->get(route('finance-reports.index', ['report' => 'transport']))
            ->assertViewHas('routes', fn ($list) => $list->pluck('id')->all() === [$worldA['route']->id])
            ->assertViewHas('stops', fn ($list) => $list->pluck('id')->all() === [$worldA['stop']->id]);
        $this->get(route('finance-reports.index', ['report' => 'hostel']))
            ->assertViewHas('hostels', fn ($list) => $list->pluck('id')->all() === [$worldA['hostel']->id]);

        // Another college's IDs never widen or leak results on any report.
        $foreign = [
            'academic_year_id' => $worldB['ctx']['year']->id,
            'academic_term_id' => $worldB['ctx']['term']->id,
            'department_id' => $worldB['department']->id,
            'program_id' => $worldB['ctx']['prog']->id,
            'section_id' => $worldB['section']->id,
            'student_id' => $worldB['student']->id,
            'fee_structure_id' => $worldB['structure']->id,
            'fee_category_id' => $worldB['category']->id,
            'route_id' => $worldB['route']->id,
            'stop_id' => $worldB['stop']->id,
            'hostel_id' => $worldB['hostel']->id,
        ];
        foreach (FinanceReportController::FILTERS as $report => $keys) {
            foreach (array_intersect_key($foreign, array_flip($keys)) as $key => $id) {
                $this->get(route('finance-reports.index', ['report' => $report, $key => $id]))
                    ->assertOk()
                    ->assertDontSee($worldB['student']->student_number);
            }
        }

        // The summary report of college A never includes college B money.
        $this->get(route('finance-reports.index', ['report' => 'summary', 'academic_year_id' => $worldB['ctx']['year']->id]))
            ->assertOk()
            ->assertViewHas('summary', fn (array $summary) => $summary['fee_value_total'] === 0.0
                && $summary['outstanding_total'] === 0.0
                && $summary['collections']['payments'] === 0);
    }

    /* ------------------------------------------------------------------ *
     * Financial correctness — the existing ledger is the single source
     * ------------------------------------------------------------------ */

    public function test_financial_reports_reconcile_with_the_existing_fee_ledger_and_services(): void
    {
        $college = $this->makeCollege('FINRMONEY');
        $world = $this->world($college, 'MONEY');
        $this->reporter($college);

        // The ledger of the assignment, straight from the module's own service.
        $ledger = $this->ledgerOf($college, $world['assignment']);
        $this->assertSame(26500.00, $ledger['assigned']);
        $this->assertSame(1500.00, $ledger['concession']);
        $this->assertSame(10000.00, $ledger['paid']);
        $this->assertSame(2000.00, $ledger['refunded']);
        $this->assertSame(8000.00, $ledger['net_collected']);
        $this->assertSame(17000.00, $ledger['outstanding']);
        $this->assertSame('partial', $ledger['status']);

        // Due / Outstanding: the rows and totals are that service's own output.
        $totals = $this->withTenant($college, fn () => app(FeeDuesService::class)->totals([]));
        $this->get(route('finance-reports.index', ['report' => 'due']))
            ->assertOk()
            ->assertViewHas('totals', fn (array $shown) => $shown == $totals
                && $shown['assigned'] === 26500.00
                && $shown['concession'] === 1500.00
                && $shown['paid'] === 10000.00
                && $shown['refunded'] === 2000.00
                && $shown['net_collected'] === 8000.00
                && $shown['outstanding'] === 17000.00)
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1
                && $rows->first()->ledger == $ledger);

        // Student fee ledger: the same figures, aggregated per student.
        $this->get(route('finance-reports.index', ['report' => 'ledger']))
            ->assertOk()
            ->assertViewHas('totals', fn (array $shown) => $shown['assigned'] === 26500.00
                && $shown['concession'] === 1500.00
                && $shown['net_collected'] === 8000.00
                && $shown['outstanding'] === 17000.00
                && $shown['outstanding_assignments'] === 1)
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1
                && $rows->first()['outstanding'] === 17000.0
                && $rows->first()['status'] === 'partial'
                && $rows->first()['student']->is($world['student']));

        // Fee structure report: the configured value is the fee structure's own
        // ACTIVE-component total, not a second calculation.
        $this->assertSame(26500.0, $this->structureTotal($world['structure']));
        $this->get(route('finance-reports.index', ['report' => 'structure']))
            ->assertOk()
            ->assertViewHas('totals', fn (array $shown) => $shown['structures'] === 1
                && $shown['fee_heads'] === 3
                && $shown['configured_value'] === 26500.00
                && $shown['assignments'] === 1)
            ->assertViewHas('rows', fn ($rows) => $rows->first()->configured_total === 26500.0
                && (int) $rows->first()->items_count === 3);

        // Fee assignment report: immutable snapshot + live ledger of the set.
        $this->get(route('finance-reports.index', ['report' => 'assignment']))
            ->assertOk()
            ->assertViewHas('totals', fn (array $shown) => $shown['assignments'] === 1
                && $shown['assigned'] === 26500.00
                && $shown['concession'] === 1500.00
                && $shown['net_collected'] === 8000.00
                && $shown['outstanding'] === 17000.00)
            ->assertViewHas('rows', fn ($rows) => (float) $rows->first()->assigned_amount === 26500.0);

        // Discounts and refunds: the module's invalid statuses are excluded from
        // the effective totals while the recorded totals keep the audit trail.
        $this->get(route('finance-reports.index', ['report' => 'concession']))
            ->assertOk()
            ->assertViewHas('totals', fn (array $shown) => $shown['concessions'] === 1
                && $shown['recorded'] === 1500.00
                && $shown['effective'] === 1500.00);
        $this->get(route('finance-reports.index', ['report' => 'refund']))
            ->assertOk()
            ->assertViewHas('totals', fn (array $shown) => $shown['refunds'] === 1
                && $shown['recorded'] === 2000.00
                && $shown['effective'] === 2000.00);

        // Collections and receipts: the money actually recorded through Finance.
        $this->get(route('finance-reports.index', ['report' => 'collection']))
            ->assertOk()
            ->assertViewHas('totals', fn (array $shown) => $shown['payments'] === 3
                && $shown['total'] === 12500.00
                && collect($shown['modes'])->pluck('mode')->all() === ['bank_transfer', 'cash', 'upi']);
        $this->get(route('finance-reports.index', ['report' => 'receipt']))
            ->assertOk()
            ->assertViewHas('totals', fn (array $shown) => $shown['receipts'] === 3
                && $shown['issued'] === 12500.00
                && $shown['refunded'] === 2000.00
                && $shown['net'] === 10500.00)
            ->assertViewHas('rows', fn ($rows) => (float) $rows->firstWhere('id', $world['payment']->id)->refunded_amount === 2000.0);

        // Financial summary reconciles with the ledger, the collections, the
        // discounts, the refunds and the transport / hostel charges.
        $this->get(route('finance-reports.index', ['report' => 'summary']))
            ->assertOk()
            ->assertViewHas('summary', function (array $summary) use ($totals): bool {
                $reconciliation = $summary['reconciliation'];

                return $summary['student_fees'] == $totals
                    && $summary['fee_value_total'] === 32700.50
                    && $summary['net_collected'] === 10500.00
                    && $summary['outstanding_total'] === 20700.50
                    && $summary['collections']['payments'] === 3
                    && $summary['collections']['total'] === 12500.00
                    && $summary['concessions']['effective'] === 1500.00
                    && $summary['refunds']['effective'] === 2000.00
                    && $summary['transport_fees']['outstanding'] === 700.50
                    && $summary['hostel_fees']['outstanding'] === 3000.00
                    && $reconciliation['assigned'] === 26500.00
                    && $reconciliation['concession'] === 1500.00
                    && $reconciliation['net_collected'] === 8000.00
                    && $reconciliation['outstanding'] === 17000.00
                    && $reconciliation['difference'] === 0.00
                    && $reconciliation['balanced'] === true
                    && $summary['collections']['by_type'] === [
                        ['fee_type' => 'hostel', 'payments' => 1, 'total' => 2000.00],
                        ['fee_type' => 'transport', 'payments' => 1, 'total' => 500.00],
                        ['fee_type' => 'tuition', 'payments' => 1, 'total' => 10000.00],
                    ];
            });

        // Every figure above is a read of existing rows: nothing was written.
        $this->assertSame(3, FeePayment::withoutGlobalScopes()->where('college_id', $college->id)->count());
        $this->assertSame(1, FeeConcession::withoutGlobalScopes()->where('college_id', $college->id)->count());
        $this->assertSame(1, FeeRefund::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    /* ------------------------------------------------------------------ *
     * Existing Finance / Transport / Hostel relationships + filters
     * ------------------------------------------------------------------ */

    public function test_filters_read_existing_finance_transport_and_hostel_relationships(): void
    {
        $college = $this->makeCollege('FINRFILT');
        $world = $this->world($college, 'FILT');
        $this->reporter($college);
        $studentNumber = $world['student']->student_number;

        // Fee collection: fee type, mode, student, structure and date window.
        $this->get(route('finance-reports.index', ['report' => 'collection', 'fee_type' => 'tuition']))
            ->assertViewHas('rows', fn ($r) => $r->total() === 1)
            ->assertViewHas('totals', fn ($t) => $t['total'] === 10000.00);
        $this->get(route('finance-reports.index', ['report' => 'collection', 'fee_type' => 'transport']))
            ->assertViewHas('rows', fn ($r) => $r->total() === 1)
            ->assertViewHas('totals', fn ($t) => $t['total'] === 500.00);
        $this->get(route('finance-reports.index', ['report' => 'collection', 'payment_mode' => 'cash']))
            ->assertViewHas('rows', fn ($r) => $r->total() === 1);
        $this->get(route('finance-reports.index', ['report' => 'collection', 'student_id' => $world['student']->id]))
            ->assertViewHas('rows', fn ($r) => $r->total() === 3);
        $this->get(route('finance-reports.index', ['report' => 'collection', 'fee_structure_id' => $world['structure']->id]))
            ->assertViewHas('rows', fn ($r) => $r->total() === 1);
        $this->get(route('finance-reports.index', ['report' => 'collection', 'from' => '2026-08-15', 'to' => '2026-08-15']))
            ->assertViewHas('rows', fn ($r) => $r->total() === 1);
        $this->get(route('finance-reports.index', ['report' => 'collection', 'section_id' => $world['section']->id]))
            ->assertViewHas('rows', fn ($r) => $r->total() === 3);
        $this->get(route('finance-reports.index', ['report' => 'collection', 'search' => $studentNumber]))
            ->assertViewHas('rows', fn ($r) => $r->total() === 3);
        $this->get(route('finance-reports.index', ['report' => 'collection', 'search' => (string) $world['payment']->payment_number]))
            ->assertViewHas('rows', fn ($r) => $r->total() === 1);
        $this->get(route('finance-reports.index', ['report' => 'collection', 'search' => 'ZZZ-NOBODY']))
            ->assertViewHas('rows', fn ($r) => $r->total() === 0);

        // Due / Outstanding and Student Fee Ledger: the derived status vocabulary.
        $this->get(route('finance-reports.index', ['report' => 'due', 'status' => 'partial']))
            ->assertViewHas('rows', fn ($r) => $r->total() === 1);
        $this->get(route('finance-reports.index', ['report' => 'due', 'status' => 'paid']))
            ->assertViewHas('rows', fn ($r) => $r->total() === 0);
        $this->get(route('finance-reports.index', ['report' => 'due', 'status' => 'due']))
            ->assertViewHas('rows', fn ($r) => $r->total() === 0);
        $this->get(route('finance-reports.index', ['report' => 'due', 'program_id' => $world['ctx']['prog']->id]))
            ->assertViewHas('rows', fn ($r) => $r->total() === 1);
        $this->get(route('finance-reports.index', ['report' => 'ledger', 'search' => $studentNumber]))
            ->assertViewHas('rows', fn ($r) => $r->total() === 1);
        $this->get(route('finance-reports.index', ['report' => 'ledger', 'search' => 'ZZZ-NOBODY']))
            ->assertViewHas('rows', fn ($r) => $r->total() === 0);
        $this->get(route('finance-reports.index', ['report' => 'ledger', 'status' => 'partial']))
            ->assertViewHas('rows', fn ($r) => $r->total() === 1);

        // Fee structure: category, term, department and status filters.
        $this->get(route('finance-reports.index', ['report' => 'structure', 'fee_category_id' => $world['category']->id]))
            ->assertViewHas('rows', fn ($r) => $r->total() === 1)
            // The tile counts every head of the matched structures (3), not just
            // the heads carrying the filtered category.
            ->assertViewHas('totals', fn ($t) => $t['fee_heads'] === 3);
        $this->get(route('finance-reports.index', ['report' => 'structure', 'academic_term_id' => $world['ctx']['term']->id]))
            ->assertViewHas('rows', fn ($r) => $r->total() === 1);
        $this->get(route('finance-reports.index', ['report' => 'structure', 'department_id' => $world['department']->id]))
            ->assertViewHas('rows', fn ($r) => $r->total() === 1);
        $this->get(route('finance-reports.index', ['report' => 'structure', 'status' => 'inactive']))
            ->assertViewHas('rows', fn ($r) => $r->total() === 0);
        $this->get(route('finance-reports.index', ['report' => 'structure', 'search' => 'FS-FILT']))
            ->assertViewHas('rows', fn ($r) => $r->total() === 1);

        // Fee assignment: status, assigned date and student search.
        $this->get(route('finance-reports.index', ['report' => 'assignment', 'status' => 'active']))
            ->assertViewHas('rows', fn ($r) => $r->total() === 1);
        $this->get(route('finance-reports.index', ['report' => 'assignment', 'status' => 'cancelled']))
            ->assertViewHas('rows', fn ($r) => $r->total() === 0);
        $this->get(route('finance-reports.index', ['report' => 'assignment', 'from' => '2026-08-10', 'to' => '2026-08-10']))
            ->assertViewHas('rows', fn ($r) => $r->total() === 1);
        $this->get(route('finance-reports.index', ['report' => 'assignment', 'from' => '2026-09-01']))
            ->assertViewHas('rows', fn ($r) => $r->total() === 0);
        $this->get(route('finance-reports.index', ['report' => 'assignment', 'search' => $studentNumber]))
            ->assertViewHas('rows', fn ($r) => $r->total() === 1);

        // Discount / concession: type, status, structure and reason search.
        $this->get(route('finance-reports.index', ['report' => 'concession', 'type' => 'fixed']))
            ->assertViewHas('rows', fn ($r) => $r->total() === 1);
        $this->get(route('finance-reports.index', ['report' => 'concession', 'type' => 'percentage']))
            ->assertViewHas('rows', fn ($r) => $r->total() === 0);
        $this->get(route('finance-reports.index', ['report' => 'concession', 'status' => 'pending']))
            ->assertViewHas('rows', fn ($r) => $r->total() === 1);
        $this->get(route('finance-reports.index', ['report' => 'concession', 'fee_structure_id' => $world['structure']->id]))
            ->assertViewHas('rows', fn ($r) => $r->total() === 1);
        $this->get(route('finance-reports.index', ['report' => 'concession', 'search' => 'Merit']))
            ->assertViewHas('rows', fn ($r) => $r->total() === 1);

        // Refund: status, date window and refund-number search.
        $this->get(route('finance-reports.index', ['report' => 'refund', 'status' => 'pending']))
            ->assertViewHas('rows', fn ($r) => $r->total() === 1);
        $this->get(route('finance-reports.index', ['report' => 'refund', 'status' => 'rejected']))
            ->assertViewHas('rows', fn ($r) => $r->total() === 0);
        $this->get(route('finance-reports.index', ['report' => 'refund', 'from' => '2026-08-20', 'to' => '2026-08-20']))
            ->assertViewHas('rows', fn ($r) => $r->total() === 1);
        $this->get(route('finance-reports.index', ['report' => 'refund', 'from' => '2026-09-01']))
            ->assertViewHas('rows', fn ($r) => $r->total() === 0);
        $this->get(route('finance-reports.index', ['report' => 'refund', 'search' => $world['refund']->refund_number]))
            ->assertViewHas('rows', fn ($r) => $r->total() === 1);

        // Receipt: receipt-number search and fee type.
        $this->get(route('finance-reports.index', ['report' => 'receipt', 'search' => $world['payment']->payment_number]))
            ->assertViewHas('rows', fn ($r) => $r->total() === 1);
        $this->get(route('finance-reports.index', ['report' => 'receipt', 'fee_type' => 'tuition']))
            ->assertViewHas('rows', fn ($r) => $r->total() === 1);

        // Transport fee: route, stop, status, year and student search.
        $this->get(route('finance-reports.index', ['report' => 'transport', 'route_id' => $world['route']->id]))
            ->assertViewHas('rows', fn ($r) => $r->total() === 1);
        $this->get(route('finance-reports.index', ['report' => 'transport', 'stop_id' => $world['stop']->id]))
            ->assertViewHas('rows', fn ($r) => $r->total() === 1);
        $this->get(route('finance-reports.index', ['report' => 'transport', 'status' => 'active']))
            ->assertViewHas('rows', fn ($r) => $r->total() === 1);
        $this->get(route('finance-reports.index', ['report' => 'transport', 'status' => 'cancelled']))
            ->assertViewHas('rows', fn ($r) => $r->total() === 0);
        $this->get(route('finance-reports.index', ['report' => 'transport', 'academic_year_id' => $world['ctx']['year']->id]))
            ->assertViewHas('rows', fn ($r) => $r->total() === 1);
        $this->get(route('finance-reports.index', ['report' => 'transport', 'search' => $studentNumber]))
            ->assertViewHas('rows', fn ($r) => $r->total() === 1);
        $this->get(route('finance-reports.index', ['report' => 'transport', 'search' => 'ZZZ-NOBODY']))
            ->assertViewHas('rows', fn ($r) => $r->total() === 0);

        // Hostel fee: hostel, status, year and the enrollment's term record.
        $this->get(route('finance-reports.index', ['report' => 'hostel', 'hostel_id' => $world['hostel']->id]))
            ->assertViewHas('rows', fn ($r) => $r->total() === 1);
        $this->get(route('finance-reports.index', ['report' => 'hostel', 'status' => 'active']))
            ->assertViewHas('rows', fn ($r) => $r->total() === 1);
        $this->get(route('finance-reports.index', ['report' => 'hostel', 'status' => 'cancelled']))
            ->assertViewHas('rows', fn ($r) => $r->total() === 0);
        $this->get(route('finance-reports.index', ['report' => 'hostel', 'academic_year_id' => $world['ctx']['year']->id]))
            ->assertViewHas('rows', fn ($r) => $r->total() === 1);
        $this->get(route('finance-reports.index', ['report' => 'hostel', 'academic_term_id' => $world['ctx']['term']->id]))
            ->assertViewHas('rows', fn ($r) => $r->total() === 1);

        // Financial summary follows the year / program filters.
        $this->get(route('finance-reports.index', ['report' => 'summary', 'academic_year_id' => $world['ctx']['year']->id, 'program_id' => $world['ctx']['prog']->id]))
            ->assertViewHas('summary', fn (array $summary) => $summary['student_fees']['assignments'] === 1
                && $summary['fee_value_total'] === 32700.50);
    }

    /* ------------------------------------------------------------------ *
     * Transport + Hostel fee integration
     * ------------------------------------------------------------------ */

    public function test_transport_and_hostel_fee_reports_reflect_the_existing_finance_integration(): void
    {
        $college = $this->makeCollege('FINRINT');
        $world = $this->world($college, 'INT');
        $this->reporter($college);

        // The charges themselves are the module's own rows, snapshotted.
        $this->assertSame(1200.50, (float) $world['transportFee']->amount);
        $this->assertSame(5000.00, (float) $world['hostelFee']->assigned_amount);

        // The ledger figures come from the transport / hostel services.
        $transportLedger = $this->withTenant($college, fn () => app(TransportFeeService::class)->summaryFor($world['transportFee']->fresh()));
        $hostelLedger = $this->withTenant($college, fn () => app(HostelFeeService::class)->summaryFor($world['hostelFee']->fresh()));

        $this->get(route('finance-reports.index', ['report' => 'transport']))
            ->assertOk()
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1
                && $rows->first()->ledger == $transportLedger
                && $rows->first()->ledger['paid'] === 500.0
                && $rows->first()->ledger['outstanding'] === 700.5
                && $rows->first()->studentTransportAssignment->transportRoute->is($world['route']))
            ->assertViewHas('totals', fn (array $totals) => $totals['assigned'] === 1200.50
                && $totals['paid'] === 500.00
                && $totals['net_collected'] === 500.00
                && $totals['outstanding'] === 700.50
                && $totals['outstanding_assignments'] === 1);

        $this->get(route('finance-reports.index', ['report' => 'hostel']))
            ->assertOk()
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1
                && $rows->first()->ledger == $hostelLedger
                && $rows->first()->ledger['paid'] === 2000.0
                && $rows->first()->ledger['outstanding'] === 3000.0
                && $rows->first()->hostelAllocation->hostel->is($world['hostel']))
            ->assertViewHas('totals', fn (array $totals) => $totals['assigned'] === 5000.00
                && $totals['paid'] === 2000.00
                && $totals['outstanding'] === 3000.00);

        // Both charges appear in the shared collection / receipt reports.
        $this->get(route('finance-reports.index', ['report' => 'collection', 'fee_type' => 'transport']))
            ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 1
                && (float) $rows->first()->amount === 500.0
                && (int) $rows->first()->transport_fee_assignment_id === (int) $world['transportFee']->id);
        $this->get(route('finance-reports.index', ['report' => 'receipt', 'fee_type' => 'hostel']))
            ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 1
                && (float) $rows->first()->amount === 2000.0);

        // And in the financial summary.
        $this->get(route('finance-reports.index', ['report' => 'summary']))
            ->assertOk()
            ->assertViewHas('summary', fn (array $summary) => $summary['transport_fees']['assigned'] === 1200.50
                && $summary['transport_fees']['outstanding'] === 700.50
                && $summary['hostel_fees']['assigned'] === 5000.00
                && $summary['hostel_fees']['outstanding'] === 3000.00
                && collect($summary['collections']['by_type'])->firstWhere('fee_type', 'transport')['total'] === 500.00
                && collect($summary['collections']['by_type'])->firstWhere('fee_type', 'hostel')['total'] === 2000.00);
    }

    /* ------------------------------------------------------------------ *
     * Soft deletes, cancellations, rejections, inactive rows
     * ------------------------------------------------------------------ */

    public function test_soft_deleted_cancelled_rejected_and_inactive_records_are_respected(): void
    {
        $college = $this->makeCollege('FINRSOFT');
        $world = $this->world($college, 'SOFT');
        $actor = $this->actor($college);
        $this->reporter($college);

        // A cancelled collection stops counting as money collected and leaves
        // the fee ledger — but stays visible in the audit trail of the table.
        $second = $this->collectFee($college, $actor, $world['assignment'], 1000, ['payment_date' => '2026-08-16']);
        $this->withTenant($college, fn () => app(FeeCollectionService::class)->cancel($second->fresh(), $actor, 'Reversed entry'));
        $this->get(route('finance-reports.index', ['report' => 'collection']))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 3)
            ->assertViewHas('totals', fn ($totals) => $totals['total'] === 12500.00);
        $this->get(route('finance-reports.index', ['report' => 'due']))
            ->assertViewHas('rows', fn ($rows) => $rows->first()->ledger['paid'] === 10000.0);

        // A rejected concession stops reducing the payable amount.
        $this->withTenant($college, fn () => app(FeeConcessionService::class)->create($world['assignment']->fresh(), [
            'type' => FeeConcession::TYPE_FIXED,
            'value' => 2500,
            'reason' => 'Rejected request',
            'status' => FeeConcession::STATUS_REJECTED,
        ], $actor));
        $this->get(route('finance-reports.index', ['report' => 'concession']))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 2)
            ->assertViewHas('totals', fn ($totals) => $totals['recorded'] === 4000.00
                && $totals['effective'] === 1500.00
                && $totals['by_status'] == ['pending' => 1, 'rejected' => 1]);
        $this->get(route('finance-reports.index', ['report' => 'due']))
            ->assertViewHas('rows', fn ($rows) => $rows->first()->ledger['concession'] === 1500.0);

        // A cancelled transport fee charge carries no payable balance unless the
        // status filter asks for it explicitly.
        $cancelled = StudentTransportFeeAssignment::create([
            'college_id' => $college->id,
            'student_transport_assignment_id' => $world['transportAssignment']->id,
            'transport_fee_structure_id' => $world['transportStructure']->id,
            'academic_year_id' => $world['ctx']['year']->id,
            'amount' => 1200.50,
            'effective_from' => '2026-10-01',
            'status' => StudentTransportFeeAssignment::STATUS_CANCELLED,
        ]);
        $this->get(route('finance-reports.index', ['report' => 'transport']))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1
                && (int) $rows->first()->id === (int) $world['transportFee']->id)
            ->assertViewHas('totals', fn ($totals) => $totals['outstanding'] === 700.50);
        $this->get(route('finance-reports.index', ['report' => 'transport', 'status' => 'cancelled']))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1
                && (int) $rows->first()->id === (int) $cancelled->id);

        // Soft-deleted rows disappear from every report that reads them.
        $world['hostelFee']->delete();
        $world['structure']->delete();
        $world['concession']->delete();
        $this->get(route('finance-reports.index', ['report' => 'hostel']))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 0);
        $this->get(route('finance-reports.index', ['report' => 'structure']))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 0)
            ->assertViewHas('totals', fn ($totals) => $totals['configured_value'] === 0.00);
        $this->get(route('finance-reports.index', ['report' => 'concession']))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1)
            ->assertViewHas('totals', fn ($totals) => $totals['effective'] === 0.00 && $totals['recorded'] === 2500.00);
        $this->get(route('finance-reports.index', ['report' => 'summary']))
            ->assertViewHas('summary', fn (array $summary) => $summary['hostel_fees']['assignments'] === 0
                && $summary['outstanding_total'] === 19200.50);

        // An inactive fee head is never charged: the configured value keeps the
        // ACTIVE-component rule of the fee structure.
        $this->assertSame(26500.0, $this->structureTotal($world['structure']));
    }

    /* ------------------------------------------------------------------ *
     * Deterministic pagination
     * ------------------------------------------------------------------ */

    public function test_lists_paginate_deterministically_and_keep_filters(): void
    {
        $college = $this->makeCollege('FINRPAGE');
        $world = $this->world($college, 'PAGE');
        $this->reporter($college);

        // 21 identical extra collections: the newest dates first, id as tiebreak.
        $extraIds = [];
        for ($i = 1; $i <= 21; $i++) {
            $extraIds[] = FeePayment::create([
                'college_id' => $college->id,
                'student_fee_assignment_id' => $world['assignment']->id,
                'student_enrollment_id' => $world['enrollment']->id,
                'fee_structure_id' => $world['structure']->id,
                'payment_number' => 'PAGE-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'payment_date' => '2026-08-25',
                'payment_mode' => FeePayment::MODE_CASH,
                'amount' => 10,
                'status' => FeePayment::STATUS_COMPLETED,
            ])->id;
        }

        $query = ['report' => 'collection', 'program_id' => $world['ctx']['prog']->id, 'fee_type' => 'tuition'];
        $page = $this->get(route('finance-reports.index', $query))->assertOk();
        $rows = $page->viewData('rows');
        $this->assertSame(22, $rows->total(), 'Filtered collections should be 22.');
        $this->assertSame(20, $rows->perPage(), 'Reports paginate 20 rows per page.');
        $this->assertSame(array_slice(array_reverse($extraIds), 0, 20), $rows->getCollection()->pluck('id')->all(), 'Newest payment date first, id as tiebreak.');
        $this->assertSame(20, $rows->count(), 'The first page is full.');
        $this->assertNotNull($rows->nextPageUrl(), 'A second page exists.');
        $this->assertStringContainsString('report=collection', $rows->nextPageUrl());
        $this->assertStringContainsString('program_id='.$world['ctx']['prog']->id, $rows->nextPageUrl());
        $this->assertStringContainsString('fee_type=tuition', $rows->nextPageUrl());
        $page->assertSee('Showing 1–20 of 22');
        $this->get(route('finance-reports.index', [...$query, 'page' => 2]))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 22
                && $rows->getCollection()->pluck('id')->all() === [$extraIds[0], $world['payment']->id]);

        // 21 more fee structures: the structure report paginates 20 per page too.
        for ($i = 1; $i <= 21; $i++) {
            $this->makeFeeStructure($college, $world['ctx'], [
                'name' => sprintf('Paged Plan %02d', $i),
                'code' => sprintf('FS-PG-%02d', $i),
            ]);
        }
        $this->get(route('finance-reports.index', ['report' => 'structure']))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 22
                && $rows->perPage() === 20
                && $rows->count() === 20)
            ->assertSee('Showing 1–20 of 22');
        $this->get(route('finance-reports.index', ['report' => 'structure', 'page' => 2]))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 22 && $rows->count() === 2);

        // 21 more students with an assignment each: the ledger report paginates
        // students by name, 20 per page, keeping the filters on every page.
        for ($i = 1; $i <= 21; $i++) {
            $student = Student::create([
                'college_id' => $college->id,
                'student_number' => sprintf('STU-PG-%02d', $i),
                'first_name' => 'Paged',
                'last_name' => sprintf('Learner %02d', $i),
                'status' => 'active',
            ]);
            $enrollment = StudentEnrollment::create([
                'college_id' => $college->id,
                'student_id' => $student->id,
                'academic_year_id' => $world['ctx']['year']->id,
                'program_id' => $world['ctx']['prog']->id,
                'enrollment_number' => sprintf('ENR-PG-%02d', $i),
                'enrollment_date' => '2026-08-05',
                'status' => 'active',
            ]);
            StudentFeeAssignment::create([
                'college_id' => $college->id,
                'student_enrollment_id' => $enrollment->id,
                'fee_structure_id' => $world['structure']->id,
                'assigned_amount' => 26500,
                'assigned_at' => '2026-08-10',
                'status' => StudentFeeAssignment::STATUS_ACTIVE,
            ]);
        }
        $this->get(route('finance-reports.index', ['report' => 'ledger', 'program_id' => $world['ctx']['prog']->id]))
            ->assertOk()
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 22
                && $rows->perPage() === 20
                && $rows->count() === 20
                && str_contains($rows->nextPageUrl(), 'report=ledger')
                && str_contains($rows->nextPageUrl(), 'program_id='.$world['ctx']['prog']->id))
            ->assertSee('Showing 1–20 of 22');
        $this->get(route('finance-reports.index', ['report' => 'ledger', 'program_id' => $world['ctx']['prog']->id, 'page' => 2]))
            ->assertOk()
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 22 && $rows->count() === 2);
    }

    /* ------------------------------------------------------------------ *
     * GET-only + read-only
     * ------------------------------------------------------------------ */

    public function test_report_routes_never_write_and_expose_only_get(): void
    {
        $college = $this->makeCollege('FINRREAD');
        $this->world($college, 'READ');
        $this->reporter($college);

        $before = $this->snapshot();
        foreach (array_keys(FinanceReportController::REPORTS) as $report) {
            $this->get(route('finance-reports.index', ['report' => $report]))->assertOk();
        }
        $this->assertSame($before, $this->snapshot());

        $this->post(route('finance-reports.index'), ['report' => 'summary'])->assertStatus(405);
        $this->put(route('finance-reports.index'))->assertStatus(405);
        $this->patch(route('finance-reports.index'))->assertStatus(405);
        $this->delete(route('finance-reports.index'))->assertStatus(405);
        $this->get('/finance-reports/create')->assertNotFound();
        $this->get('/finance-reports/1/edit')->assertNotFound();
        $this->assertSame($before, $this->snapshot());

        $methods = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'finance-reports'))
            ->flatMap(fn ($route) => $route->methods())->unique()->sort()->values()->all();
        $this->assertSame(['GET', 'HEAD'], $methods);
    }

    /* ------------------------------------------------------------------ *
     * Query-count / N+1 protection
     * ------------------------------------------------------------------ */

    public function test_query_count_does_not_grow_with_rows_on_any_report(): void
    {
        $college = $this->makeCollege('FINRNPLUS');
        $world = $this->world($college, 'NPL');
        $this->reporter($college);

        $logs = fn (): array => collect(array_keys(FinanceReportController::REPORTS))
            ->mapWithKeys(fn (string $report) => [$report => $this->queryLogFor(route('finance-reports.index', ['report' => $report]))])
            ->all();
        $small = $logs();

        $this->growWorld($college, $world, 21);

        $grown = $logs();

        // Growing the tables may make Laravel SKIP an eager-load query whose
        // foreign keys are all null on the page, but it must never ADD one:
        // every report reads its page with a fixed number of queries.
        foreach (array_keys(FinanceReportController::REPORTS) as $report) {
            $before = $small[$report];
            $after = $grown[$report];
            $added = array_values(array_diff($after, $before));

            $this->assertLessThanOrEqual(
                count($before),
                count($after),
                sprintf(
                    "Report [%s] ran %d queries before growth and %d after; a page must not cost more queries as rows grow.\nNew queries after growth:\n%s",
                    $report,
                    count($before),
                    count($after),
                    implode("\n", array_slice($added, 0, 12)),
                ),
            );
        }

        // One row and a full page of identically shaped collections cost the same
        // number of queries: nothing is loaded per row.
        foreach (['collection', 'receipt'] as $report) {
            $one = $this->queryLogFor(route('finance-reports.index', ['report' => $report, 'search' => 'GROW-001']));
            $full = $this->queryLogFor(route('finance-reports.index', ['report' => $report, 'search' => 'GROW-']));

            $this->assertSame(
                count($one),
                count($full),
                sprintf('Report [%s] must not run a query per row (one row: %d queries, a full page: %d).', $report, count($one), count($full)),
            );
        }
    }

    /* ----------------------------------------------------------------- helpers */

    private function reporter(College $college): User
    {
        $user = $this->makeUserWithPermissions($college, self::VIEW);
        $this->asCollege($college, $user);

        return $user;
    }

    /** An actor allowed to operate the Finance / Transport / Hostel fee screens. */
    private function actor(College $college): User
    {
        return $this->makeUserWithPermissions($college, [
            'fee_structures.view', 'student_fee_assignments.view', 'fee_collections.view', 'fee_collections.create',
            'fee_dues.view', 'fee_concessions.view', 'fee_concessions.create', 'refunds.view', 'refunds.create',
            'transport_fees.view', 'transport_fees.create',
            'hostel_fees.view', 'hostel_fees.collect',
        ]);
    }

    private function queryLogFor(string $url): array
    {
        DB::enableQueryLog();
        try {
            DB::flushQueryLog();
            $this->get($url)->assertOk();

            return array_map(fn (array $entry) => (string) $entry['query'], DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
    }

    private function snapshot(): array
    {
        return [
            ...collect(self::TABLES)->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])->all(),
            'payments_updated' => DB::table('fee_payments')->max('updated_at'),
            'audit_logs' => AuditLog::count(),
        ];
    }

    /**
     * One college with a complete Finance world: a three-head fee structure (one
     * head inactive), an assigned student with a collection, a concession and a
     * refund, plus a transport charge and a hostel charge collected through the
     * same Finance payment rows.
     *
     * @return array<string, mixed>
     */
    private function world(College $college, string $prefix): array
    {
        $ctx = $this->makeFinanceContext($college, $prefix);
        $department = Department::create(['college_id' => $college->id, 'name' => 'Dept '.$prefix, 'code' => 'D-'.$prefix, 'status' => 'active']);
        $ctx['prog']->update(['department_id' => $department->id]);
        $section = Section::create([
            'college_id' => $college->id,
            'academic_year_id' => $ctx['year']->id,
            'program_id' => $ctx['prog']->id,
            'name' => 'Sec '.$prefix,
            'code' => 'SEC-'.$prefix,
            'status' => 'active',
        ]);

        $category = $this->makeFeeCategory($college, ['name' => 'Tuition '.$prefix, 'code' => 'FC-'.$prefix]);
        $structure = $this->makeFeeStructure($college, $ctx, [
            'name' => 'Fee Plan '.$prefix,
            'code' => 'FS-'.$prefix,
        ], [
            ['name' => 'Tuition Fee', 'amount' => 25000, 'fee_category_id' => $category->id, 'sort_order' => 1],
            ['name' => 'Library Fee', 'amount' => 1500, 'sort_order' => 2],
            // Inactive heads are configured but never charged.
            ['name' => 'Sports Fee', 'amount' => 500, 'sort_order' => 3, 'status' => FeeStructureItem::STATUS_INACTIVE],
        ]);

        $actor = $this->actor($college);

        ['student' => $student, 'enrollment' => $enrollment] = $this->makeFinanceEnrollment($college, $ctx, $prefix, ['section_id' => $section->id]);
        $assignment = $this->assignFeeStructure($college, $actor, $enrollment, $structure);
        $payment = $this->collectFee($college, $actor, $assignment, 10000, ['reference_number' => 'REF-'.$prefix]);

        $concession = $this->withTenant($college, fn () => app(FeeConcessionService::class)->create(
            $assignment->fresh(),
            ['type' => FeeConcession::TYPE_FIXED, 'value' => 1500, 'reason' => 'Merit scholarship '.$prefix],
            $actor,
        ));
        $refund = $this->withTenant($college, fn () => app(FeeRefundService::class)->create(
            $payment->fresh(),
            ['refund_date' => '2026-08-20', 'amount' => 2000, 'reason' => 'Partial refund '.$prefix],
            $actor,
        ));

        // Transport charge collected through the shared Finance payment rows.
        $route = new TransportRoute(['name' => 'Route '.$prefix, 'code' => 'RT-'.$prefix, 'status' => 'active']);
        $route->college_id = $college->id;
        $route->save();

        $stop = new TransportStop(['name' => 'Stop '.$prefix, 'code' => 'ST-'.$prefix, 'sequence' => 1, 'status' => 'active']);
        $stop->route_id = $route->id;
        $stop->college_id = $college->id;
        $stop->save();
        $transportAssignment = StudentTransportAssignment::create([
            'college_id' => $college->id,
            'student_enrollment_id' => $enrollment->id,
            'academic_year_id' => $ctx['year']->id,
            'transport_route_id' => $route->id,
            'transport_stop_id' => $stop->id,
            'start_date' => '2026-09-01',
            'status' => StudentTransportAssignment::STATUS_ACTIVE,
        ]);
        $transportStructure = TransportFeeStructure::create([
            'college_id' => $college->id,
            'academic_year_id' => $ctx['year']->id,
            'name' => 'Transport Plan '.$prefix,
            'code' => 'TRF-'.$prefix,
            'amount' => 1200.50,
            'effective_from' => '2026-07-01',
            'status' => 'active',
        ]);
        $transportFee = $this->withTenant($college, fn () => app(TransportFeeService::class)->assignFee($college, [
            'student_transport_assignment_id' => $transportAssignment->id,
            'transport_fee_structure_id' => $transportStructure->id,
            'effective_from' => '2026-09-01',
        ], $actor));
        $transportPayment = $this->withTenant($college, fn () => app(FeeCollectionService::class)->collectTransportFee(
            $transportFee->fresh(),
            ['payment_date' => '2026-09-10', 'payment_mode' => FeePayment::MODE_UPI, 'amount' => 500],
            $actor,
        ));

        // Hostel charge collected through the same shared payment rows.
        $hostel = Hostel::create(['college_id' => $college->id, 'name' => 'Hostel '.$prefix, 'code' => 'H-'.$prefix, 'hostel_type' => 'mixed', 'gender' => 'any', 'status' => 'active']);
        $building = HostelBuilding::create(['college_id' => $college->id, 'hostel_id' => $hostel->id, 'name' => 'Block '.$prefix, 'code' => 'B-'.$prefix, 'floors' => 2, 'status' => 'active']);
        $room = HostelRoom::create(['college_id' => $college->id, 'hostel_id' => $hostel->id, 'building_id' => $building->id, 'room_number' => '1'.$prefix, 'floor' => 1, 'room_type' => 'Double', 'capacity' => 2, 'status' => 'active']);
        $bed = HostelBed::create(['college_id' => $college->id, 'hostel_id' => $hostel->id, 'building_id' => $building->id, 'room_id' => $room->id, 'bed_number' => 1, 'status' => 'available']);
        $allocation = HostelAllocation::create([
            'college_id' => $college->id,
            'student_enrollment_id' => $enrollment->id,
            'academic_year_id' => $ctx['year']->id,
            'hostel_id' => $hostel->id,
            'hostel_building_id' => $building->id,
            'hostel_room_id' => $room->id,
            'hostel_bed_id' => $bed->id,
            'allocation_date' => '2026-08-06',
            'status' => HostelAllocation::STATUS_ACTIVE,
        ]);
        $hostelStructure = HostelFeeStructure::create([
            'college_id' => $college->id,
            'academic_year_id' => $ctx['year']->id,
            'name' => 'Hostel Plan '.$prefix,
            'code' => 'HFS-'.$prefix,
            'amount' => 5000,
            'frequency' => 'yearly',
            'status' => 'active',
        ]);
        $hostelFee = $this->withTenant($college, fn () => app(HostelFeeService::class)->assignFee($college, [
            'hostel_allocation_id' => $allocation->id,
            'hostel_fee_structure_id' => $hostelStructure->id,
            'effective_from' => '2026-08-06',
        ], $actor));
        $hostelPayment = $this->withTenant($college, fn () => app(FeeCollectionService::class)->collectHostelFee(
            $hostelFee->fresh(),
            ['payment_date' => '2026-08-12', 'payment_mode' => FeePayment::MODE_BANK_TRANSFER, 'amount' => 2000],
            $actor,
        ));

        // The term-wise academic record the hostel term filter reads.
        StudentAcademicRecord::create([
            'college_id' => $college->id,
            'student_id' => $student->id,
            'enrollment_id' => $enrollment->id,
            'academic_year_id' => $ctx['year']->id,
            'academic_term_id' => $ctx['term']->id,
            'program_id' => $ctx['prog']->id,
            'section_id' => $section->id,
        ]);

        return compact(
            'ctx', 'department', 'section', 'category', 'structure', 'student', 'enrollment', 'assignment',
            'payment', 'concession', 'refund', 'route', 'stop', 'transportAssignment', 'transportStructure',
            'transportFee', 'transportPayment', 'hostel', 'building', 'room', 'bed', 'allocation',
            'hostelStructure', 'hostelFee', 'hostelPayment', 'actor',
        );
    }

    /**
     * Grow every dataset the reports read past one page, so a query count that
     * depends on the number of rows would move.
     *
     * @param  array<string, mixed>  $world
     */
    private function growWorld(College $college, array $world, int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            // Collections (collection / receipt reports).
            FeePayment::create([
                'college_id' => $college->id,
                'student_fee_assignment_id' => $world['assignment']->id,
                'student_enrollment_id' => $world['enrollment']->id,
                'fee_structure_id' => $world['structure']->id,
                'payment_number' => 'GROW-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'payment_date' => '2026-08-25',
                'payment_mode' => FeePayment::MODE_CASH,
                'amount' => 5,
                'status' => FeePayment::STATUS_COMPLETED,
            ]);

            // Fee structures (structure report) and fee heads.
            $this->makeFeeStructure($college, $world['ctx'], [
                'name' => sprintf('Grown Plan %02d', $i),
                'code' => sprintf('FS-GR-%02d', $i),
            ]);

            // Students, enrollments and assignments (ledger / due / assignment).
            $student = Student::create([
                'college_id' => $college->id,
                'student_number' => sprintf('STU-GR-%02d', $i),
                'first_name' => 'Grown',
                'last_name' => sprintf('Learner %02d', $i),
                'status' => 'active',
            ]);
            $enrollment = StudentEnrollment::create([
                'college_id' => $college->id,
                'student_id' => $student->id,
                'academic_year_id' => $world['ctx']['year']->id,
                'program_id' => $world['ctx']['prog']->id,
                'section_id' => $world['section']->id,
                'enrollment_number' => sprintf('ENR-GR-%02d', $i),
                'enrollment_date' => '2026-08-05',
                'status' => 'active',
            ]);
            $assignment = StudentFeeAssignment::create([
                'college_id' => $college->id,
                'student_enrollment_id' => $enrollment->id,
                'fee_structure_id' => $world['structure']->id,
                'assigned_amount' => 26500,
                'assigned_at' => '2026-08-10',
                'status' => StudentFeeAssignment::STATUS_ACTIVE,
            ]);

            // Concessions and refunds on the shared rows.
            FeeConcession::create([
                'college_id' => $college->id,
                'student_fee_assignment_id' => $assignment->id,
                'type' => FeeConcession::TYPE_FIXED,
                'value' => 100,
                'amount' => 100,
                'reason' => 'Grown concession '.$i,
                'status' => FeeConcession::STATUS_PENDING,
            ]);
            FeeRefund::create([
                'college_id' => $college->id,
                'fee_payment_id' => $world['payment']->id,
                'refund_number' => sprintf('RFD-GR-%02d', $i),
                'refund_date' => '2026-08-21',
                'amount' => 10,
                'reason' => 'Grown refund '.$i,
                'status' => FeeRefund::STATUS_PENDING,
            ]);

            // Transport and hostel charges (their report rows).
            StudentTransportFeeAssignment::create([
                'college_id' => $college->id,
                'student_transport_assignment_id' => $world['transportAssignment']->id,
                'transport_fee_structure_id' => $world['transportStructure']->id,
                'academic_year_id' => $world['ctx']['year']->id,
                'amount' => 1200.50,
                'effective_from' => sprintf('2026-10-%02d', ($i % 28) + 1),
                'status' => StudentTransportFeeAssignment::STATUS_COMPLETED,
            ]);
            HostelFeeAssignment::create([
                'college_id' => $college->id,
                'hostel_allocation_id' => $world['allocation']->id,
                'hostel_fee_structure_id' => $world['hostelStructure']->id,
                'academic_year_id' => $world['ctx']['year']->id,
                'assigned_amount' => 5000,
                'effective_from' => sprintf('2026-11-%02d', ($i % 28) + 1),
                'status' => HostelFeeAssignment::STATUS_COMPLETED,
            ]);
        }
    }
}
