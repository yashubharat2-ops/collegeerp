<?php

namespace Tests\Feature\Transport;

use App\Http\Controllers\Transport\TransportReportController;
use App\Models\AcademicYear;
use App\Models\College;
use App\Models\Faculty;
use App\Models\FeePayment;
use App\Models\Permission;
use App\Models\Program;
use App\Models\Role;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\StudentTransportAssignment;
use App\Models\StudentTransportFeeAssignment;
use App\Models\TransportDriver;
use App\Models\TransportFeeStructure;
use App\Models\TransportRoute;
use App\Models\TransportStop;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\ExamAttendance\ExamAttendanceTestHelpers;
use Tests\TestCase;

/**
 * Transport Reports — the read-only reporting layer over the existing
 * Transport Management module (vehicles, drivers, routes / stops, student
 * transport assignments, transport fees) and its Student / Finance
 * integrations.
 *
 * Covers: the single transport_reports.view permission and its RBAC
 * independence from the operational Transport permissions, the seeded
 * Super Admin / College Admin grants, the sidebar placement under REPORTS
 * after Library Reports, the exact six report views and their order, tenant
 * isolation including forged foreign filter ids, the vehicle / driver /
 * route-stop / assignment relationships, the shared TransportFeeService
 * ledger (never recalculated), the Transport Summary, empty + invalid +
 * irrelevant filters, soft deletes, deterministic pagination, GET-only
 * routes with no writes, and query counts that never grow with row counts
 * (N+1 protection).
 */
class TransportReportTest extends TestCase
{
    use ExamAttendanceTestHelpers;

    private const VIEW = ['transport_reports.view'];

    /** The operational Transport permissions that must NOT grant any report. */
    private const OPERATIONAL = [
        'transport_dashboard.view', 'vehicles.view', 'vehicle_documents.view',
        'transport_drivers.view', 'transport_routes.view',
        'student_transport_assignments.view', 'transport_fees.view',
    ];

    /** Core Transport / Finance tables the read-only reports must never touch. */
    private const TABLES = [
        'vehicles', 'transport_drivers', 'transport_routes', 'transport_stops',
        'student_transport_assignments', 'transport_fee_structures',
        'student_transport_fee_assignments', 'fee_payments', 'fee_refunds',
    ];

    /* ------------------------------------------------------------------ *\
     * Permission / RBAC
     * \* ------------------------------------------------------------------ */

    public function test_transport_reports_permission_is_separate_from_the_operational_transport_permissions(): void
    {
        $college = $this->makeCollege('TRPPERM');

        // Guests are redirected to login for the report route.
        $this->get(route('transport-reports.index'))->assertRedirect(route('login'));

        // Every operational Transport permission together still does NOT open the reports.
        $operator = $this->makeUserWithPermissions($college, self::OPERATIONAL);
        foreach (array_keys(TransportReportController::REPORTS) as $report) {
            $this->asCollege($college, $operator)
                ->get(route('transport-reports.index', ['report' => $report]))->assertForbidden();
        }
        $this->asCollege($college, $operator)->get(route('vehicles.index'))->assertOk()
            ->assertDontSee('href="'.route('transport-reports.index').'"', false)
            ->assertDontSee('nav-group__label">Reports<', false);

        // The report permission alone opens every report but no operational page.
        $reporter = $this->makeUserWithPermissions($college, self::VIEW);
        foreach (array_keys(TransportReportController::REPORTS) as $report) {
            $this->asCollege($college, $reporter)
                ->get(route('transport-reports.index', ['report' => $report]))
                ->assertOk()->assertSee(TransportReportController::REPORTS[$report]);
        }
        $this->asCollege($college, $reporter)->get(route('vehicles.index'))->assertForbidden();
        $this->asCollege($college, $reporter)->get(route('transport-drivers.index'))->assertForbidden();
        $this->asCollege($college, $reporter)->get(route('transport-assignments.index'))->assertForbidden();
        $this->asCollege($college, $reporter)->get(route('transport-fees.index'))->assertForbidden();

        // A role in one college is not a grant in another college.
        $other = $this->makeCollege('TRPPERM2');
        $reporter->colleges()->attach($other->id);
        $this->asCollege($other, $reporter)->get(route('transport-reports.index'))->assertForbidden();
    }

    public function test_the_seeded_admin_roles_hold_the_transport_reports_permission(): void
    {
        // The existing seeding system creates the dedicated permission and
        // grants it to both admin roles; it stays separate from the
        // operational Transport permissions.
        $permission = Permission::query()->where('slug', 'transport_reports.view')->firstOrFail();
        $this->assertSame('transport_reports', $permission->module);

        $college = College::query()->where('code', 'DEMO')->firstOrFail();
        $super = Role::query()->whereNull('college_id')->where('slug', 'super-admin')->firstOrFail();
        $admin = Role::query()->where('college_id', $college->id)->where('slug', 'college-admin')->firstOrFail();

        $this->assertTrue($super->permissions()->whereKey($permission->id)->exists(), 'Super Admin must hold transport_reports.view.');
        $this->assertTrue($admin->permissions()->whereKey($permission->id)->exists(), 'College Admin must hold transport_reports.view.');

        // A Super Admin user can open every report.
        $superUser = $this->makeSuperAdmin($college);
        foreach (array_keys(TransportReportController::REPORTS) as $report) {
            $this->asCollege($college, $superUser)
                ->get(route('transport-reports.index', ['report' => $report]))->assertOk();
        }

        // A user with the seeded College Admin role can open every report too.
        $adminUser = User::create([
            'name' => 'Seeded Transport Admin',
            'email' => 'seeded-transport-admin@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $adminUser->colleges()->attach($college->id, ['is_default' => true]);
        $adminUser->roles()->attach($admin->id, ['college_id' => $college->id]);
        foreach (array_keys(TransportReportController::REPORTS) as $report) {
            $this->asCollege($college, $adminUser)
                ->get(route('transport-reports.index', ['report' => $report]))->assertOk();
        }

        // A report permission is not an operational Transport grant.
        foreach (self::OPERATIONAL as $slug) {
            $this->assertNotSame('transport_reports.view', $slug);
        }
    }

    /* ------------------------------------------------------------------ *\
     * The exact six reports, their order and their filters
     * \* ------------------------------------------------------------------ */

    public function test_exactly_six_reports_render_in_the_fixed_order_with_only_relevant_filters(): void
    {
        $college = $this->makeCollege('TRPLIST');
        $this->makeUserWithPermissions($college, self::VIEW);
        $this->asCollege($college, User::query()->latest('id')->first());

        $expected = [
            'vehicle' => 'Vehicle Report',
            'driver' => 'Driver Report',
            'route_stop' => 'Route / Stop Report',
            'assignments' => 'Student Transport Assignment Report',
            'fees' => 'Transport Fee Report',
            'summary' => 'Transport Summary',
        ];
        $this->assertSame($expected, TransportReportController::REPORTS, 'The six Transport reports keep their exact names and order.');

        // Every report renders with its own label and only its own filters.
        foreach (TransportReportController::REPORTS as $key => $label) {
            $this->get(route('transport-reports.index', ['report' => $key]))
                ->assertOk()
                ->assertViewHas('report', $key)
                ->assertViewHas('visible', TransportReportController::FILTERS[$key])
                ->assertSee($label);
        }

        // The report switcher shows exactly the six entries in the fixed order.
        $html = $this->get(route('transport-reports.index'))->assertOk()->getContent();
        $navStart = strpos($html, 'aria-label="Transport report views"');
        $this->assertNotFalse($navStart, 'The report view navigation must be present.');
        $nav = substr($html, $navStart, strpos($html, '</nav>', $navStart) - $navStart);
        $this->assertSame(6, substr_count($nav, 'href='));
        $previous = -1;
        foreach ($expected as $key => $label) {
            $position = strpos($nav, $label);
            $this->assertNotFalse($position, "The nav must contain '{$label}'.");
            $this->assertGreaterThan($previous, $position, "Label '{$label}' must keep its exact position.");
            $this->assertStringContainsString('report='.$key, $nav);
            $previous = $position;
        }

        // Reports only carry the filters they can use.
        $this->assertSame(['vehicle_id', 'status', 'vehicle_type'], TransportReportController::FILTERS['vehicle']);
        $this->assertSame(['driver_id', 'status', 'from', 'to'], TransportReportController::FILTERS['driver']);
        $this->assertSame(['route_id', 'stop_id', 'status'], TransportReportController::FILTERS['route_stop']);
        $this->assertSame(['academic_year_id', 'student_id', 'program_id', 'section_id', 'route_id', 'stop_id', 'status', 'from', 'to'], TransportReportController::FILTERS['assignments']);
        $this->assertSame(['academic_year_id', 'student_id', 'route_id', 'stop_id', 'status', 'from', 'to'], TransportReportController::FILTERS['fees']);
        $this->assertSame([], TransportReportController::FILTERS['summary']);

        // The Summary has no filters at all — not even a date window.
        $this->assertNotContains('from', TransportReportController::FILTERS['summary']);
        $this->assertNotContains('status', TransportReportController::FILTERS['summary']);
    }

    /* ------------------------------------------------------------------ *\
     * Sidebar placement
     * \* ------------------------------------------------------------------ */

    public function test_reports_menu_lists_transport_reports_after_library_and_outside_the_transport_group(): void
    {
        $college = $this->makeCollege('TRPMENU');
        $user = $this->makeUserWithPermissions($college, [
            'inventory_dashboard.view', 'student_reports.view', 'academic_reports.view',
            'examination_reports.view', 'finance_reports.view', 'hr_reports.view', 'library_reports.view',
            'transport_reports.view', 'vehicles.view', 'transport_fees.view',
        ]);
        $html = $this->asCollege($college, $user)->get(route('transport-reports.index'))->assertOk()->getContent();

        $inventory = strpos($html, '>Inventory<');
        $reports = strpos($html, 'nav-group__label">Reports<');
        $student = strpos($html, 'href="'.route('student-reports.index').'"');
        $academic = strpos($html, 'href="'.route('academic-reports.index').'"');
        $examination = strpos($html, 'href="'.route('examination-reports.index').'"');
        $finance = strpos($html, 'href="'.route('finance-reports.index').'"');
        $hr = strpos($html, 'href="'.route('hr-reports.index').'"');
        $library = strpos($html, 'href="'.route('library-reports.index').'"');
        $transport = strpos($html, 'href="'.route('transport-reports.index').'"');
        $platform = strpos($html, 'nav-group__label">Settings<', (int) $reports);
        $platform = $platform === false ? strpos($html, '</nav>', (int) $reports) : $platform;
        $this->assertNotFalse($inventory);
        $this->assertTrue(
            $inventory < $reports && $reports < $student && $student < $academic
            && $academic < $examination && $examination < $finance && $finance < $hr
            && $hr < $library && $library < $transport && $transport < $platform
        );
        $this->assertSame(1, substr_count($html, 'nav-group__label">Reports<'));

        // The report links live between the Reports heading and Administration /
        // Settings, and Transport Reports follows Library Reports.
        $menu = substr($html, $reports, $platform - $reports);
        $this->assertGreaterThan(
            (int) strpos($menu, route('library-reports.index')),
            (int) strpos($menu, route('transport-reports.index')),
            'Library Reports must stay in the section, before Transport Reports.'
        );

        // The REPORTS heading itself stays plain (no link, no reordering).
        $this->assertStringNotContainsString('<a', substr($html, $reports - 80, 80));

        // Transport Reports left the Transport Management group: the group keeps
        // only its operational entries and no longer mentions the reports.
        $groupStart = strpos($html, '>Transport</div>');
        $this->assertNotFalse($groupStart);
        $groupEnd = strpos($html, 'nav-group__head', $groupStart + 1);
        $group = substr($html, $groupStart, $groupEnd === false ? null : $groupEnd - $groupStart);
        $this->assertStringNotContainsString('Transport Reports', $group);
        $this->assertStringNotContainsString(route('transport-reports.index'), $group);
        $this->assertStringContainsString('Vehicles', $group);
        $this->assertStringContainsString('Transport Fees', $group);

        // A report permission is the only thing that reveals the section: a user
        // holding only transport_reports.view sees REPORTS and the Transport
        // link, but no Transport Management group at all.
        $solo = $this->makeUserWithPermissions($college, self::VIEW);
        $soloHtml = $this->asCollege($college, $solo)->get(route('transport-reports.index'))->assertOk()->getContent();
        $this->assertStringContainsString('nav-group__label">Reports<', $soloHtml);
        $this->assertStringContainsString('href="'.route('transport-reports.index').'"', $soloHtml);
        $this->assertStringNotContainsString('>Transport</div>', $soloHtml);
    }

    /* ------------------------------------------------------------------ *\
     * Tenant isolation
     * \* ------------------------------------------------------------------ */

    public function test_every_report_is_tenant_scoped_even_with_foreign_filter_ids(): void
    {
        $a = $this->makeCollege('TRPTA');
        $b = $this->makeCollege('TRPTB');
        [$vehicleA, $driverA, $routeA, $stopA, $yearA, $assignmentA, $feeA] = $this->world($a, 'A');
        [$vehicleB, $driverB, $routeB, $stopB, $yearB, $assignmentB, $feeB] = $this->world($b, 'B');
        $this->makeUserWithPermissions($a, self::VIEW);
        $this->asCollege($a, User::query()->latest('id')->first());

        foreach (array_keys(TransportReportController::REPORTS) as $report) {
            $response = $this->get(route('transport-reports.index', ['report' => $report]))->assertOk();
            $html = $response->getContent();
            // The foreign college's identifiers and names never appear.
            foreach ([$vehicleB->registration_number, $driverB->license_number, $routeB->name, $routeB->code, $stopB->name, 'STU-B'] as $needle) {
                $this->assertStringNotContainsString($needle, $html, "Report {$report} leaked foreign data ({$needle}).");
            }
        }

        // Foreign filter ids are valid integers: they simply match nothing.
        $foreign = [
            'vehicle' => ['vehicle_id' => $vehicleB->id],
            'driver' => ['driver_id' => $driverB->id],
            'route_stop' => ['route_id' => $routeB->id, 'stop_id' => $stopB->id],
            'assignments' => ['route_id' => $routeB->id, 'stop_id' => $stopB->id, 'academic_year_id' => $yearB->id],
            'fees' => ['route_id' => $routeB->id, 'academic_year_id' => $yearB->id],
            'summary' => [],
        ];
        foreach ($foreign as $report => $params) {
            $response = $this->get(route('transport-reports.index', ['report' => $report] + $params))->assertOk();
            if ($report !== 'summary') {
                $this->assertSame(0, $response->viewData('rows')->count(), "Report {$report} must be empty for foreign ids.");
            }
            foreach ([$vehicleB->registration_number, $routeB->name] as $needle) {
                $this->assertStringNotContainsString($needle, $response->getContent());
            }
        }

        // The visible rows are the home college's.
        $this->get(route('transport-reports.index', ['report' => 'vehicle']))
            ->assertViewHas('rows', fn ($rows) => $rows->count() === 3 && $rows->pluck('id')->contains($vehicleA->id));
        $this->get(route('transport-reports.index', ['report' => 'assignments']))
            ->assertViewHas('rows', fn ($rows) => $rows->count() === 3 && $rows->pluck('id')->contains($assignmentA->id));
    }

    /* ------------------------------------------------------------------ *\
     * The six reports
     * \* ------------------------------------------------------------------ */

    public function test_vehicle_report_lists_existing_vehicles_with_status_and_type_filters(): void
    {
        $college = $this->makeCollege('TRPVEH');
        $bus = $this->vehicle($college, 'KA-01 AA-1111', ['vehicle_type' => 'Bus', 'seating_capacity' => 40, 'status' => 'active']);
        $van = $this->vehicle($college, 'KA-01 BB-2222', ['vehicle_type' => 'Van', 'seating_capacity' => 12, 'status' => 'maintenance']);
        $this->vehicle($college, 'KA-01 CC-3333', ['vehicle_type' => 'Bus', 'status' => 'inactive']);
        $archived = $this->vehicle($college, 'KA-01 DD-4444', ['vehicle_type' => 'Bus']);
        $archived->delete();
        $this->makeUserWithPermissions($college, self::VIEW);
        $this->asCollege($college, User::query()->latest('id')->first());

        // Soft-deleted vehicles never appear; inactive ones stay visible.
        // Deterministic order: registration number, then id.
        $this->get(route('transport-reports.index', ['report' => 'vehicle']))
            ->assertOk()
            ->assertViewHas('rows', fn ($rows) => $rows->pluck('registration_number')->all() === ['KA-01 AA-1111', 'KA-01 BB-2222', 'KA-01 CC-3333'])
            ->assertViewHas('totals', fn ($totals) => $totals === ['total' => 3, 'active' => 1, 'inactive' => 1, 'maintenance' => 1, 'retired' => 0])
            ->assertSee('Bus')->assertSee('40')->assertSee('Van');

        // Status, type and vehicle filters narrow the listing.
        $this->get(route('transport-reports.index', ['report' => 'vehicle', 'status' => 'active']))
            ->assertViewHas('rows', fn ($rows) => $rows->pluck('id')->all() === [$bus->id])
            ->assertViewHas('totals', fn ($totals) => $totals['total'] === 1);
        $this->get(route('transport-reports.index', ['report' => 'vehicle', 'vehicle_type' => 'Bus']))
            ->assertViewHas('rows', fn ($rows) => $rows->count() === 2
                && $rows->pluck('registration_number')->all() === ['KA-01 AA-1111', 'KA-01 CC-3333']
                && ! $rows->contains('id', $van->id));
        $this->get(route('transport-reports.index', ['report' => 'vehicle', 'vehicle_id' => $van->id]))
            ->assertViewHas('rows', fn ($rows) => $rows->pluck('id')->all() === [$van->id]);

        // A type that does not exist returns nothing — never invented rows.
        $this->get(route('transport-reports.index', ['report' => 'vehicle', 'vehicle_type' => 'Hovercraft']))
            ->assertViewHas('rows', fn ($rows) => $rows->count() === 0)
            ->assertSee('No vehicles match these filters.');
    }

    public function test_driver_report_reads_the_staff_details_and_never_invents_a_vehicle(): void
    {
        $college = $this->makeCollege('TRPDRV');
        $ravi = $this->driver($college, 'Ravi', 'Driver', 'DL-1', [
            'email' => 'ravi@example.test', 'phone' => '9000000001',
        ], [
            'license_type' => 'LMV', 'license_expiry' => '2027-01-15',
            'joining_date' => '2026-01-01', 'status' => 'active',
        ]);
        $amina = $this->driver($college, 'Amina', 'Driver', 'DL-2', [], ['status' => 'inactive', 'license_expiry' => '2026-10-10']);
        $archived = $this->driver($college, 'Gone', 'Driver', 'DL-3');
        $archived->delete();
        $this->makeUserWithPermissions($college, self::VIEW);
        $this->asCollege($college, User::query()->latest('id')->first());

        $this->get(route('transport-reports.index', ['report' => 'driver']))
            ->assertOk()
            ->assertViewHas('rows', fn ($rows) => $rows->pluck('license_number')->all() === ['DL-1', 'DL-2'])
            ->assertViewHas('totals', fn ($totals) => $totals === ['total' => 2, 'active' => 1, 'inactive' => 1])
            ->assertSee('Ravi Driver')
            ->assertSee('ravi@example.test')
            ->assertSee('9000000001')
            ->assertSee('LMV')
            ->assertSee('15 Jan 2027');

        // The schema records no driver → vehicle assignment: the column stays "—".
        $this->assertStringContainsString('Assigned vehicle', $this->get(route('transport-reports.index', ['report' => 'driver']))->getContent());
        $this->assertStringContainsString('never invents a pairing', $this->get(route('transport-reports.index', ['report' => 'driver']))->getContent());

        // Status, driver and license-expiry window filters.
        $this->get(route('transport-reports.index', ['report' => 'driver', 'status' => 'inactive']))
            ->assertViewHas('rows', fn ($rows) => $rows->pluck('id')->all() === [$amina->id]);
        $this->get(route('transport-reports.index', ['report' => 'driver', 'driver_id' => $ravi->id]))
            ->assertViewHas('rows', fn ($rows) => $rows->pluck('id')->all() === [$ravi->id]);
        $this->get(route('transport-reports.index', ['report' => 'driver', 'from' => '2026-12-01', 'to' => '2027-06-30']))
            ->assertViewHas('rows', fn ($rows) => $rows->pluck('id')->all() === [$ravi->id]);
    }

    public function test_route_stop_report_preserves_the_route_stop_relationships(): void
    {
        $college = $this->makeCollege('TRPROUTE');
        $year = $this->year($college, 'RS');
        $north = $this->route($college, 'North', 'NORTH');
        $gate = $this->stop($college, $north, 'Gate', 'GATE', 1);
        $mall = $this->stop($college, $north, 'Mall', 'MALL', 2, ['status' => 'inactive']);
        $gone = $this->stop($college, $north, 'Gone', 'GONE', 3);
        $gone->delete();
        $south = $this->route($college, 'South', 'SOUTH', ['status' => 'inactive']);
        $this->stop($college, $south, 'Depot', 'DEPOT', 1);

        $this->assignment($college, $year, $north, $gate, ['status' => 'active'], 'RS1');
        $this->assignment($college, $year, $north, $gate, ['status' => 'completed'], 'RS2');
        $this->assignment($college, $year, $south, $this->stopFor($south, 1), ['status' => 'active'], 'RS3');

        $this->makeUserWithPermissions($college, self::VIEW);
        $this->asCollege($college, User::query()->latest('id')->first());

        // Routes with their stops in the stored sequence — never a flat list.
        $response = $this->get(route('transport-reports.index', ['report' => 'route_stop']))->assertOk();
        $response->assertViewHas('rows', fn ($rows) => $rows->pluck('name')->all() === ['North', 'South']);
        $response->assertViewHas('rows', function ($rows) {
            $north = $rows->firstWhere('name', 'North');

            return $north->stops->pluck('code')->all() === ['GATE', 'MALL'] // soft-deleted stop hidden
                && $north->stops_count === 2 && $north->active_stops_count === 1;
        });
        // Live student counts per stop and route, active and total.
        $response->assertViewHas('stopCounts', fn ($counts) => $counts[$this->stopFor($north, 1)->id] === ['active' => 1, 'total' => 2]
            && $counts[$mall->id] === ['active' => 0, 'total' => 0]);
        $response->assertViewHas('routeCounts', fn ($counts) => $counts[$north->id] === ['active' => 1, 'total' => 2]
            && $counts[$south->id] === ['active' => 1, 'total' => 1]);
        $response->assertViewHas('totals', fn ($totals) => $totals['routes'] === 2 && $totals['routes_active'] === 1
            && $totals['stops'] === 3 && $totals['stops_active'] === 2);
        $response->assertSee('Gate')->assertSee('Mall');

        // Route / stop / status filters.
        $this->get(route('transport-reports.index', ['report' => 'route_stop', 'route_id' => $north->id]))
            ->assertViewHas('rows', fn ($rows) => $rows->count() === 1 && $rows->first()->id === $north->id);
        $this->get(route('transport-reports.index', ['report' => 'route_stop', 'stop_id' => $gate->id]))
            ->assertViewHas('rows', fn ($rows) => $rows->count() === 1 && $rows->first()->id === $north->id);
        $this->get(route('transport-reports.index', ['report' => 'route_stop', 'status' => 'inactive']))
            ->assertViewHas('rows', fn ($rows) => $rows->count() === 1 && $rows->first()->id === $south->id);
    }

    public function test_student_assignment_report_reuses_student_enrollment_relationships(): void
    {
        $college = $this->makeCollege('TRPASG');
        $year = $this->year($college, 'AS');
        $route = $this->route($college, 'Campus', 'CAMPUS');
        $stop = $this->stop($college, $route, 'Library', 'LIB', 1);
        $stadium = $this->stop($college, $route, 'Stadium', 'STAD', 2);
        $program = Program::create(['college_id' => $college->id, 'name' => 'Program AS', 'code' => 'PRG-AS', 'status' => 'active']);
        $section = Section::create(['college_id' => $college->id, 'academic_year_id' => $year->id, 'program_id' => $program->id, 'name' => 'Section AS', 'code' => 'SEC-AS', 'status' => 'active']);
        // One assignment per student per year is a real domain rule — three
        // students share the same program / section (class).
        [$student, $enrollment] = $this->studentEnrollment($college, $year, 'AS1', $program, $section);
        [, $enrollmentDone] = $this->studentEnrollment($college, $year, 'AS2', $program, $section);
        [, $enrollmentHidden] = $this->studentEnrollment($college, $year, 'AS3', $program, $section);
        $assignment = $this->assignment($college, $year, $route, $stop, ['status' => 'active', 'student_enrollment_id' => $enrollment->id], 'AS1');
        $done = $this->assignment($college, $year, $route, $stadium, ['status' => 'completed', 'start_date' => '2026-07-01', 'student_enrollment_id' => $enrollmentDone->id], 'AS2');
        $hidden = $this->assignment($college, $year, $route, $stop, ['student_enrollment_id' => $enrollmentHidden->id], 'AS3');
        $hidden->delete();

        $this->makeUserWithPermissions($college, self::VIEW);
        $this->asCollege($college, User::query()->latest('id')->first());

        // Student + enrollment + program / section + route / stop + status, all
        // from the existing relationships. Soft-deleted rows never appear;
        // completed history does. Newest start date first (deterministic).
        $response = $this->get(route('transport-reports.index', ['report' => 'assignments']))->assertOk();
        $response->assertViewHas('rows', fn ($rows) => $rows->count() === 2
            && $rows->pluck('id')->all() === [$assignment->id, $done->id]);
        $response->assertViewHas('rows', function ($rows) use ($student, $enrollment, $route, $stop) {
            $row = $rows->first();

            return $row->studentEnrollment->student->id === $student->id
                && $row->studentEnrollment->id === $enrollment->id
                && $row->studentEnrollment->program->code === 'PRG-AS'
                && $row->studentEnrollment->section->code === 'SEC-AS'
                && $row->transportRoute->id === $route->id
                && $row->transportStop->id === $stop->id
                && $row->status === 'active';
        });
        $response->assertSee('ENR-AS'); // the enrollment number is table-only

        // The full filter set narrows the listing.
        $cases = [
            ['academic_year_id' => $year->id, 'count' => 2],
            ['student_id' => $student->id, 'count' => 1],
            ['program_id' => $program->id, 'count' => 2],
            ['section_id' => $section->id, 'count' => 2],
            ['route_id' => $route->id, 'count' => 2],
            ['stop_id' => $stop->id, 'count' => 1],
            ['status' => 'completed', 'count' => 1],
            ['from' => '2026-08-01', 'count' => 1],
            ['to' => '2026-07-31', 'count' => 1],
        ];
        foreach ($cases as $case) {
            $this->get(route('transport-reports.index', ['report' => 'assignments'] + array_diff_key($case, ['count' => true])))
                ->assertViewHas('rows', fn ($rows) => $rows->count() === $case['count']);
        }
    }

    public function test_transport_fee_report_reuses_the_shared_finance_ledger(): void
    {
        $college = $this->makeCollege('TRPFEE');
        $year = $this->year($college, 'FE');
        $route = $this->route($college, 'Fees', 'FEES');
        $stop = $this->stop($college, $route, 'FStop', 'FST', 1);
        $this->assignment($college, $year, $route, $stop, [], 'FE1');
        $transportAssignment = StudentTransportAssignment::withoutGlobalScopes()->latest('id')->firstOrFail();
        $feeUser = $this->makeUserWithPermissions($college, ['transport_fees.view', 'transport_fees.create', 'transport_fees.update']);
        $this->asCollege($college, $feeUser);

        $structure = $this->fixture(TransportFeeStructure::class, $college, [
            'academic_year_id' => $year->id, 'name' => 'Term Fee', 'code' => 'TF-FE',
            'amount' => 1200.50, 'effective_from' => '2026-07-01', 'status' => 'active',
        ]);

        // Assign and collect through the EXISTING operational flows — the
        // report reads the same rows those screens write.
        $this->post(route('transport-fees.store'), [
            'student_transport_assignment_id' => $transportAssignment->id,
            'transport_fee_structure_id' => $structure->id,
            'effective_from' => '2026-09-01',
            'status' => 'active',
        ])->assertSessionHasNoErrors();
        $fee = StudentTransportFeeAssignment::query()->firstOrFail();
        $this->post(route('transport-fees.collect', $fee->id), [
            'payment_date' => now()->toDateString(), 'payment_mode' => 'cash', 'amount' => 400,
        ])->assertSessionHasNoErrors();

        // The report shows the shared ledger's assigned / collected / due —
        // never a recalculated figure.
        $reportUser = $this->makeUserWithPermissions($college, self::VIEW);
        $this->asCollege($college, $reportUser);
        $totals = ['assigned' => 1200.5, 'net_collected' => 400.0, 'outstanding' => 800.5, 'assignments' => 1, 'outstanding_assignments' => 1];
        $response = $this->get(route('transport-reports.index', ['report' => 'fees']))->assertOk();
        $response->assertViewHas('totals', $totals)
            ->assertViewHas('rows', fn ($rows) => $rows->count() === 1
                && $rows->first()->id === $fee->id
                && (float) $rows->first()->ledger['assigned'] === 1200.5
                && (float) $rows->first()->ledger['net_collected'] === 400.0
                && (float) $rows->first()->ledger['outstanding'] === 800.5
                && $rows->first()->ledger['status'] === 'partial')
            ->assertSee('Term Fee')
            ->assertSee('1,200.50')
            ->assertSee('800.50');

        // Filters narrow through the existing assignment / year relationships.
        $this->get(route('transport-reports.index', ['report' => 'fees', 'academic_year_id' => $year->id]))
            ->assertViewHas('rows', fn ($rows) => $rows->count() === 1);
        $this->get(route('transport-reports.index', ['report' => 'fees', 'route_id' => $route->id, 'stop_id' => $stop->id]))
            ->assertViewHas('rows', fn ($rows) => $rows->count() === 1);
        $this->get(route('transport-reports.index', ['report' => 'fees', 'status' => 'cancelled']))
            ->assertViewHas('rows', fn ($rows) => $rows->count() === 0);
        $this->get(route('transport-reports.index', ['report' => 'fees', 'from' => '2026-08-01']))
            ->assertViewHas('rows', fn ($rows) => $rows->count() === 1);
        $this->get(route('transport-reports.index', ['report' => 'fees', 'from' => '2026-10-01']))
            ->assertViewHas('rows', fn ($rows) => $rows->count() === 0);

        // Settle the balance through Finance — the report follows immediately.
        $this->asCollege($college, $feeUser);
        $this->post(route('transport-fees.collect', $fee->id), [
            'payment_date' => now()->toDateString(), 'payment_mode' => 'cash', 'amount' => 800.50,
        ])->assertSessionHasNoErrors();
        $this->asCollege($college, $reportUser);
        $this->get(route('transport-reports.index', ['report' => 'fees']))
            ->assertViewHas('totals', fn ($summary) => $summary['outstanding'] === 0.0 && $summary['outstanding_assignments'] === 0)
            ->assertViewHas('rows', fn ($rows) => $rows->count() === 1 && $rows->first()->ledger['status'] === 'paid');
    }

    public function test_transport_summary_aggregates_the_live_records(): void
    {
        $college = $this->makeCollege('TRPSUM');
        [$vehicle, $driver, $route, $stop, $year, $assignment, $fee] = $this->world($college, 'S');
        $this->makeUserWithPermissions($college, self::VIEW);
        $this->asCollege($college, User::query()->latest('id')->first());

        $response = $this->get(route('transport-reports.index', ['report' => 'summary']))->assertOk();
        $response->assertViewHas('summary', function (array $summary) {
            return $summary['vehicles'] === ['total' => 3, 'active' => 1, 'inactive' => 1, 'maintenance' => 1, 'retired' => 0]
                && $summary['drivers'] === ['total' => 3, 'active' => 2, 'inactive' => 1]
                && $summary['routes'] === ['total' => 2, 'active' => 1, 'inactive' => 1]
                && $summary['stops'] === ['total' => 3, 'active' => 2, 'inactive' => 1]
                && $summary['assignments'] === ['total' => 3, 'active' => 1, 'completed' => 1, 'cancelled' => 1]
                && $summary['fees']['assignments'] === 2
                && $summary['fees']['active'] === 1
                && $summary['fees']['cancelled'] === 1
                && $summary['fees']['assigned'] === 1700.75
                && $summary['fees']['net_collected'] === 500.25
                && $summary['fees']['outstanding'] === 1200.50
                && $summary['fees']['outstanding_assignments'] === 2;
        });
        $response->assertSee('Total vehicles')->assertSee('Total drivers')->assertSee('Total routes')
            ->assertSee('Total stops')->assertSee('Student transport assignments');

        // The summary has no filters: parameters are ignored, not judged.
        $this->get(route('transport-reports.index', ['report' => 'summary', 'status' => 'made-up', 'from' => 'nonsense', 'route_id' => 999999]))
            ->assertOk()
            ->assertViewHas('summary', fn (array $summary) => $summary['vehicles']['total'] === 3);
    }

    /* ------------------------------------------------------------------ *\
     * Empty / invalid / irrelevant filters, soft deletes, pagination
     * \* ------------------------------------------------------------------ */

    public function test_empty_results_invalid_filters_and_irrelevant_filters_are_handled_gracefully(): void
    {
        $college = $this->makeCollege('TRPEMPTY');
        $this->makeUserWithPermissions($college, self::VIEW);
        $this->asCollege($college, User::query()->latest('id')->first());

        // Every list report renders an explicit empty state on a fresh college.
        foreach (['vehicle' => 'No vehicles', 'driver' => 'No drivers', 'route_stop' => 'No routes', 'assignments' => 'No transport assignments', 'fees' => 'No transport fee assignments'] as $report => $empty) {
            $this->get(route('transport-reports.index', ['report' => $report]))
                ->assertOk()->assertSee($empty);
        }
        $this->get(route('transport-reports.index', ['report' => 'summary']))->assertOk()
            ->assertViewHas('summary', fn (array $summary) => $summary['vehicles']['total'] === 0
                && $summary['assignments']['total'] === 0 && $summary['fees']['assigned'] === 0.0);

        // Unknown report keys fall back to the first report instead of erroring.
        $this->get(route('transport-reports.index', ['report' => 'bogus']))
            ->assertOk()->assertViewHas('report', 'vehicle');

        // Invalid filter values are rejected where the report uses them.
        $this->get(route('transport-reports.index', ['report' => 'vehicle', 'status' => 'made-up']))
            ->assertSessionHasErrors('status');
        $this->get(route('transport-reports.index', ['report' => 'assignments', 'from' => '2026-10-20', 'to' => '2026-09-01']))
            ->assertSessionHasErrors('to');
        $this->get(route('transport-reports.index', ['report' => 'vehicle', 'vehicle_id' => 'not-an-id']))
            ->assertSessionHasErrors('vehicle_id');
        $this->get(route('transport-reports.index', ['report' => 'driver', 'from' => 'not-a-date']))
            ->assertSessionHasErrors('from');

        // Irrelevant parameters a report cannot use are ignored, not judged.
        $this->get(route('transport-reports.index', ['report' => 'summary', 'status' => 'made-up', 'vehicle_type' => 'Anything', 'stop_id' => 42]))
            ->assertOk()->assertViewHas('report', 'summary');
        $this->get(route('transport-reports.index', ['report' => 'vehicle', 'status' => 'active', 'academic_year_id' => 123456]))
            ->assertOk();
    }

    public function test_soft_deleted_and_inactive_records_follow_transport_rules(): void
    {
        $college = $this->makeCollege('TRPSOFT');
        $year = $this->year($college, 'SF');
        $route = $this->route($college, 'Soft', 'SOFT');
        $stop = $this->stop($college, $route, 'SStop', 'SST', 1);

        $keptVehicle = $this->vehicle($college, 'KA-77 KE-1111', ['status' => 'inactive']);
        $hiddenVehicle = $this->vehicle($college, 'KA-77 HI-2222');
        $hiddenVehicle->delete();
        $keptDriver = $this->driver($college, 'Kept', 'Driver', 'SDL-1', [], ['status' => 'inactive']);
        $hiddenDriver = $this->driver($college, 'Hidden', 'Driver', 'SDL-2');
        $hiddenDriver->delete();

        $keptAssignment = $this->assignment($college, $year, $route, $stop, ['status' => 'cancelled'], 'SF1');
        $hiddenAssignment = $this->assignment($college, $year, $route, $stop, [], 'SF2');
        $hiddenAssignment->delete();

        $structure = $this->fixture(TransportFeeStructure::class, $college, [
            'academic_year_id' => $year->id, 'name' => 'Soft Fee', 'code' => 'SF-FEE',
            'amount' => 900, 'effective_from' => '2026-07-01', 'status' => 'inactive',
        ]);
        $keptFee = $this->fixture(StudentTransportFeeAssignment::class, $college, [
            'student_transport_assignment_id' => $keptAssignment->id, 'transport_fee_structure_id' => $structure->id,
            'academic_year_id' => $year->id, 'amount' => 900, 'effective_from' => '2026-09-01', 'status' => 'cancelled',
        ]);
        $hiddenFee = $this->fixture(StudentTransportFeeAssignment::class, $college, [
            'student_transport_assignment_id' => $keptAssignment->id, 'transport_fee_structure_id' => $structure->id,
            'academic_year_id' => $year->id, 'amount' => 700, 'effective_from' => '2026-08-01', 'status' => 'active',
        ]);
        $hiddenFee->delete();

        $this->makeUserWithPermissions($college, self::VIEW);
        $this->asCollege($college, User::query()->latest('id')->first());

        // Soft-deleted rows disappear everywhere; inactive / cancelled history
        // stays visible exactly as the operational screens show it.
        $this->get(route('transport-reports.index', ['report' => 'vehicle']))
            ->assertViewHas('rows', fn ($rows) => $rows->pluck('id')->all() === [$keptVehicle->id]);
        $this->get(route('transport-reports.index', ['report' => 'driver']))
            ->assertViewHas('rows', fn ($rows) => $rows->pluck('id')->all() === [$keptDriver->id]);
        $this->get(route('transport-reports.index', ['report' => 'assignments']))
            ->assertViewHas('rows', fn ($rows) => $rows->pluck('id')->all() === [$keptAssignment->id]);
        $this->get(route('transport-reports.index', ['report' => 'fees']))
            ->assertViewHas('rows', fn ($rows) => $rows->pluck('id')->all() === [$keptFee->id]);
    }

    public function test_lists_paginate_deterministically_and_keep_their_filters(): void
    {
        $college = $this->makeCollege('TRPPAGE');
        foreach (range(1, 25) as $i) {
            $this->vehicle($college, sprintf('KA-55 PG-%04d', $i), ['vehicle_type' => 'Bus']);
        }
        $this->vehicle($college, 'KA-55 PG-9999', ['vehicle_type' => 'Van']);
        $this->makeUserWithPermissions($college, self::VIEW);
        $this->asCollege($college, User::query()->latest('id')->first());

        $page = $this->get(route('transport-reports.index', ['report' => 'vehicle', 'vehicle_type' => 'Bus']))
            ->assertOk()
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 25 && $rows->perPage() === 20 && $rows->count() === 20);
        $this->assertStringContainsString('vehicle_type=Bus', (string) $page->viewData('rows')->nextPageUrl());
        $this->get(route('transport-reports.index', ['report' => 'vehicle', 'vehicle_type' => 'Bus', 'page' => 2]))
            ->assertOk()
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 25 && $rows->count() === 5
                && $rows->pluck('id')->doesntContain($page->viewData('rows')->first()->id));
    }

    /* ------------------------------------------------------------------ *\
     * GET-only + read-only
     * \* ------------------------------------------------------------------ */

    public function test_report_routes_never_write_and_expose_only_get(): void
    {
        $college = $this->makeCollege('TRPREAD');
        $this->makeUserWithPermissions($college, self::VIEW);
        $this->asCollege($college, User::query()->latest('id')->first());
        $this->world($college, 'RD');

        $before = $this->snapshot();
        foreach (array_keys(TransportReportController::REPORTS) as $report) {
            $this->get(route('transport-reports.index', ['report' => $report]))->assertOk();
        }
        $this->assertSame($before, $this->snapshot(), 'Rendering every report must not change a single row.');

        $this->post(route('transport-reports.index'), ['report' => 'summary'])->assertStatus(405);
        $this->put(route('transport-reports.index'))->assertStatus(405);
        $this->patch(route('transport-reports.index'))->assertStatus(405);
        $this->delete(route('transport-reports.index'))->assertStatus(405);
        $this->get('/transport-reports/create')->assertNotFound();
        $this->get('/transport-reports/1/edit')->assertNotFound();
        $this->assertSame($before, $this->snapshot());

        $methods = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'transport-reports'))
            ->flatMap(fn ($route) => $route->methods())->unique()->sort()->values()->all();
        $this->assertSame(['GET', 'HEAD'], $methods);
    }

    /* ------------------------------------------------------------------ *\
     * Query-count / N+1 protection
     * \* ------------------------------------------------------------------ */

    public function test_query_count_does_not_grow_with_rows_on_any_report(): void
    {
        $college = $this->makeCollege('TRPNPLUS');
        $this->makeUserWithPermissions($college, self::VIEW);
        $this->asCollege($college, User::query()->latest('id')->first());
        [, , $worldRoute] = $this->world($college, 'SM');

        $logs = fn (): array => collect(array_keys(TransportReportController::REPORTS))
            ->mapWithKeys(fn (string $report) => [$report => $this->queryLogFor(route('transport-reports.index', ['report' => $report]))])
            ->all();
        $small = $logs();

        // Grow every dataset past one page (20 rows).
        $year = $this->year($college, 'GR');
        $route = $this->route($college, 'Growth', 'GROWTH');
        $stop = $this->stop($college, $route, 'GStop', 'GST', 1);
        foreach (range(1, 21) as $i) {
            $this->vehicle($college, sprintf('KA-66 GR-%04d', $i), ['vehicle_type' => 'Bus']);
            $this->driver($college, 'Grow', 'Driver'.$i, sprintf('GDL-%04d', $i));
            $this->assignment($college, $year, $route, $stop, [], sprintf('GR%04d', $i));
        }

        $grown = $logs();

        // Growing the tables may make Laravel SKIP an eager-load query, but it
        // must never ADD one: every report reads its page with a fixed number
        // of queries.
        foreach (array_keys(TransportReportController::REPORTS) as $report) {
            $before = $small[$report];
            $after = $grown[$report];
            $this->assertLessThanOrEqual(
                count($before),
                count($after),
                sprintf(
                    "Report [%s] ran %d queries before growth and %d after; a page must not cost more queries as rows grow.\nNew queries after growth:\n%s",
                    $report,
                    count($before),
                    count($after),
                    implode("\n", array_slice(array_values(array_diff($after, $before)), 0, 12)),
                ),
            );
        }

        // One row and a full page of identically shaped collections cost the
        // same number of queries: nothing is loaded per row. (Each status value
        // is valid for its own report vocabulary and matches exactly one row.)
        $oneRowFilters = [
            'vehicle' => ['status' => 'maintenance'],
            'driver' => ['status' => 'inactive'],
            'route_stop' => ['route_id' => $worldRoute->id],
            'assignments' => ['status' => 'completed'],
            'fees' => ['status' => 'cancelled'],
        ];
        foreach ($oneRowFilters as $report => $filter) {
            $one = $this->queryLogFor(route('transport-reports.index', ['report' => $report] + $filter));
            $full = $this->queryLogFor(route('transport-reports.index', ['report' => $report]));
            $this->assertSame(
                count($one),
                count($full),
                sprintf('Report [%s] must not run a query per row (filtered: %d queries, full page: %d).', $report, count($one), count($full)),
            );
        }
    }

    /* ------------------------------------------------------------------ *\
     * Helpers
     * \* ------------------------------------------------------------------ */

    /** @return array<string, int> */
    private function snapshot(): array
    {
        return collect(self::TABLES)->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])->all();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function fixture(string $class, College $college, array $data)
    {
        $record = new $class(array_diff_key($data, ['route_id' => true, 'faculty_id' => true]));
        $record->college_id = $college->id;
        foreach (['route_id', 'faculty_id'] as $key) {
            if (isset($data[$key])) {
                $record->{$key} = $data[$key];
            }
        }
        $record->save();

        return $record;
    }

    private function year(College $college, string $suffix): AcademicYear
    {
        return $this->fixture(AcademicYear::class, $college, [
            'name' => 'Year '.$suffix, 'code' => 'AY-'.$suffix,
            'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30', 'status' => 'active',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function vehicle(College $college, string $registration, array $overrides = []): Vehicle
    {
        return $this->fixture(Vehicle::class, $college, array_merge([
            'registration_number' => $registration, 'seating_capacity' => 30, 'status' => 'active',
        ], $overrides));
    }

    /**
     * Driver fixture: $staff are the referenced staff record's fields (contact
     * details …), $overrides the driver record's fields (license / joining /
     * status …). The schema keeps drivers and staff as separate records, so
     * the two sets are never mixed — and $overrides always wins.
     *
     * @param  array<string, mixed>  $staff
     * @param  array<string, mixed>  $overrides
     */
    private function driver(College $college, string $first, string $last, string $license, array $staff = [], array $overrides = []): TransportDriver
    {
        $faculty = Faculty::create(array_merge([
            'college_id' => $college->id, 'employee_code' => 'EMP-'.Str::upper(Str::random(6)),
            'first_name' => $first, 'last_name' => $last, 'status' => 'active',
        ], $staff));

        return $this->fixture(TransportDriver::class, $college, array_merge([
            'faculty_id' => $faculty->id, 'license_number' => $license, 'status' => 'active',
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function route(College $college, string $name, string $code, array $overrides = []): TransportRoute
    {
        return $this->fixture(TransportRoute::class, $college, array_merge([
            'name' => $name, 'code' => $code, 'status' => 'active',
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function stop(College $college, TransportRoute $route, string $name, string $code, int $sequence, array $overrides = []): TransportStop
    {
        return $this->fixture(TransportStop::class, $college, array_merge([
            'route_id' => $route->id, 'name' => $name, 'code' => $code,
            'sequence' => $sequence, 'status' => 'active',
        ], $overrides));
    }

    private function stopFor(TransportRoute $route, int $sequence): TransportStop
    {
        // Test fixture lookup outside an HTTP tenant context.
        return TransportStop::withoutGlobalScopes()
            ->where('route_id', $route->id)->where('sequence', $sequence)->firstOrFail();
    }

    /** @return array{0: Student, 1: StudentEnrollment} */
    private function studentEnrollment(College $college, AcademicYear $year, string $suffix, ?Program $program = null, ?Section $section = null): array
    {
        $student = $this->fixture(Student::class, $college, [
            'student_number' => 'STU-'.$suffix.'-'.Str::upper(Str::random(4)),
            'first_name' => 'Stu', 'last_name' => $suffix, 'status' => 'active',
        ]);
        $program ??= Program::create(['college_id' => $college->id, 'name' => 'Program '.$suffix, 'code' => 'PRG-'.$suffix, 'status' => 'active']);
        $section ??= Section::create(['college_id' => $college->id, 'academic_year_id' => $year->id, 'program_id' => $program->id, 'name' => 'Section '.$suffix, 'code' => 'SEC-'.$suffix, 'status' => 'active']);
        $enrollment = $this->fixture(StudentEnrollment::class, $college, [
            'student_id' => $student->id, 'academic_year_id' => $year->id, 'program_id' => $program->id,
            'section_id' => $section->id, 'enrollment_number' => 'ENR-'.$suffix, 'enrollment_date' => '2026-07-05', 'status' => 'active',
        ]);

        return [$student, $enrollment];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function assignment(College $college, AcademicYear $year, TransportRoute $route, TransportStop $stop, array $overrides = [], string $suffix = 'A'): StudentTransportAssignment
    {
        if (! isset($overrides['student_enrollment_id'])) {
            [, $enrollment] = $this->studentEnrollment($college, $year, $suffix);
            $overrides['student_enrollment_id'] = $enrollment->id;
        }

        return $this->fixture(StudentTransportAssignment::class, $college, array_merge([
            'academic_year_id' => $year->id,
            'transport_route_id' => $route->id, 'transport_stop_id' => $stop->id,
            'start_date' => '2026-09-01', 'status' => 'active',
        ], $overrides));
    }

    /**
     * A small but complete Transport world: vehicles / drivers in several
     * states (incl. soft-deleted), routes with stops, assignments across
     * statuses and transport fees with shared Finance collections.
     *
     * @return array{0: Vehicle, 1: TransportDriver, 2: TransportRoute, 3: TransportStop, 4: AcademicYear, 5: StudentTransportAssignment, 6: StudentTransportFeeAssignment}
     */
    private function world(College $college, string $prefix): array
    {
        $year = $this->year($college, $prefix);

        $vehicle = $this->vehicle($college, "KA-90 {$prefix}V-1111", ['vehicle_type' => 'Bus', 'status' => 'active']);
        $this->vehicle($college, "KA-90 {$prefix}V-2222", ['vehicle_type' => 'Van', 'status' => 'inactive']);
        $this->vehicle($college, "KA-90 {$prefix}V-3333", ['vehicle_type' => 'Bus', 'status' => 'maintenance']);
        $hidden = $this->vehicle($college, "KA-90 {$prefix}V-4444");
        $hidden->delete();

        $driver = $this->driver($college, 'World', 'Driver'.$prefix, "WDL-{$prefix}1", [], ['status' => 'active']);
        $this->driver($college, 'Rest', 'Driver'.$prefix, "WDL-{$prefix}2", [], ['status' => 'inactive']);
        $this->driver($college, 'Extra', 'Driver'.$prefix, "WDL-{$prefix}3", [], ['status' => 'active']);
        $hidden = $this->driver($college, 'Hidden', 'Driver'.$prefix, "WDL-{$prefix}4");
        $hidden->delete();

        $route = $this->route($college, "Route {$prefix} 1", "R{$prefix}1");
        $this->route($college, "Route {$prefix} 2", "R{$prefix}2", ['status' => 'inactive']);
        $stop = $this->stop($college, $route, "Stop {$prefix} 1", "S{$prefix}1", 1);
        $this->stop($college, $route, "Stop {$prefix} 2", "S{$prefix}2", 2, ['status' => 'inactive']);
        $this->stop($college, $route, "Stop {$prefix} 3", "S{$prefix}3", 3);
        $hidden = $this->stop($college, $route, "Stop {$prefix} 4", "S{$prefix}4", 4);
        $hidden->delete();

        $assignment = $this->assignment($college, $year, $route, $stop, ['status' => 'active'], $prefix.'1');
        $this->assignment($college, $year, $route, $stop, ['status' => 'completed', 'start_date' => '2026-07-15'], $prefix.'2');
        $this->assignment($college, $year, $route, $stop, ['status' => 'cancelled', 'start_date' => '2026-07-20'], $prefix.'3');
        $hidden = $this->assignment($college, $year, $route, $stop, [], $prefix.'4');
        $hidden->delete();

        $structure = $this->fixture(TransportFeeStructure::class, $college, [
            'academic_year_id' => $year->id, 'name' => "Fee {$prefix}", 'code' => "FEE-{$prefix}",
            'amount' => 1200.50, 'effective_from' => '2026-07-01', 'status' => 'active',
        ]);
        $fee = $this->fixture(StudentTransportFeeAssignment::class, $college, [
            'student_transport_assignment_id' => $assignment->id, 'transport_fee_structure_id' => $structure->id,
            'academic_year_id' => $year->id, 'amount' => 1200.50, 'effective_from' => '2026-09-01', 'status' => 'active',
        ]);
        $this->fixture(StudentTransportFeeAssignment::class, $college, [
            'student_transport_assignment_id' => $assignment->id, 'transport_fee_structure_id' => $structure->id,
            'academic_year_id' => $year->id, 'amount' => 500.25, 'effective_from' => '2026-08-01', 'status' => 'cancelled',
        ]);

        // One Finance collection against the active fee (shared fee_payments row).
        FeePayment::create([
            'college_id' => $college->id,
            'student_enrollment_id' => $assignment->student_enrollment_id,
            'transport_fee_assignment_id' => $fee->id,
            'payment_number' => 'PAY-'.$prefix.'-'.Str::upper(Str::random(4)),
            'payment_date' => now()->toDateString(),
            'payment_mode' => 'cash',
            'amount' => 500.25,
            'status' => FeePayment::STATUS_COMPLETED,
        ]);

        return [$vehicle, $driver, $route, $stop, $year, $assignment, $fee];
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
}
