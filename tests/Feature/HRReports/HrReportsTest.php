<?php

namespace Tests\Feature\HRReports;

use App\Http\Controllers\HrReportController;
use App\Models\AdmissionDocumentType;
use App\Models\AuditLog;
use App\Models\College;
use App\Models\Department;
use App\Models\Designation;
use App\Models\EmployeeDocument;
use App\Models\Faculty;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Payroll;
use App\Models\SalaryStructure;
use App\Models\StaffAttendance;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Feature\HR\HRTestHelpers;
use Tests\TestCase;

/**
 * HR Reports — the read-only reporting layer over the existing HR / Staff
 * module (staff / employee records, staff departments, designations, employee
 * documents, staff attendance, leave requests and payroll).
 *
 * Covers: the single hr_reports.view permission and its RBAC independence from
 * the operational HR permissions, the sidebar placement under REPORTS after
 * Finance Reports, the exact eight report views and their order, tenant
 * isolation including forged foreign filter ids, the department / designation /
 * staff relationships, employee documents with their derived status, the
 * attendance / leave / payroll figures read from the operational records, the
 * HR Summary, empty + invalid filters, deterministic pagination, GET-only
 * routes with no writes, and query counts that never grow with row counts
 * (N+1 protection).
 */
class HrReportsTest extends TestCase
{
    use HRTestHelpers;

    private const VIEW = ['hr_reports.view'];

    /** The operational HR permissions that must NOT grant any report. */
    private const OPERATIONAL = [
        'faculties.view', 'departments.view', 'designations.view', 'employee_documents.view',
        'staff_attendance.view', 'leave_requests.view', 'salary_structures.view', 'payrolls.view',
    ];

    /** Core HR tables the read-only reports must never touch. */
    private const TABLES = [
        'faculties', 'employee_documents', 'staff_attendances', 'leave_requests',
        'leave_types', 'payrolls', 'salary_structures', 'departments', 'designations',
    ];

    /* ------------------------------------------------------------------ *\
     * Permission / RBAC
     * \* ------------------------------------------------------------------ */

    public function test_hr_reports_permission_is_separate_from_the_operational_hr_permissions(): void
    {
        $college = $this->makeCollege('HRRPERM');

        // Guests are redirected to login for the report route.
        $this->get(route('hr-reports.index'))->assertRedirect(route('login'));

        // Every operational HR permission together still does NOT open the reports.
        $operator = $this->makeUserWithPermissions($college, self::OPERATIONAL);
        foreach (array_keys(HrReportController::REPORTS) as $report) {
            $this->asCollege($college, $operator)
                ->get(route('hr-reports.index', ['report' => $report]))->assertForbidden();
        }
        $this->asCollege($college, $operator)->get(route('employees.index'))->assertOk()
            ->assertDontSee('href="'.route('hr-reports.index').'"', false)
            ->assertDontSee('>REPORTS<', false);

        // The report permission alone opens every report but no operational page.
        $reporter = $this->reporter($college);
        foreach (array_keys(HrReportController::REPORTS) as $report) {
            $this->get(route('hr-reports.index', ['report' => $report]))->assertOk();
        }
        $this->get(route('employees.index'))->assertForbidden();

        // A role in one college is not a grant in another college.
        $other = $this->makeCollege('HRRPERM2');
        $reporter->colleges()->attach($other->id);
        $this->asCollege($other, $reporter)->get(route('hr-reports.index'))->assertForbidden();
    }

    /* ------------------------------------------------------------------ *\
     * The exact eight reports, their order and their filters
     * \* ------------------------------------------------------------------ */

    public function test_exactly_eight_reports_render_in_the_fixed_order_with_only_relevant_filters(): void
    {
        $college = $this->makeCollege('HRRLIST');
        $this->reporter($college);

        $expected = [
            'employees' => 'Employee / Staff List',
            'department' => 'Department-wise Staff Report',
            'designation' => 'Designation-wise Staff Report',
            'documents' => 'Employee Documents Report',
            'attendance' => 'Staff Attendance Report',
            'leave' => 'Leave Report',
            'payroll' => 'Payroll / Salary Report',
            'summary' => 'HR Summary',
        ];
        $this->assertSame($expected, HrReportController::REPORTS, 'The eight HR reports keep their exact names and order.');

        // Every report renders with its own label and only its own filters.
        foreach (HrReportController::REPORTS as $key => $label) {
            $this->get(route('hr-reports.index', ['report' => $key]))
                ->assertOk()
                ->assertViewHas('report', $key)
                ->assertViewHas('visible', HrReportController::FILTERS[$key])
                ->assertSee($label);
        }

        // The report switcher shows exactly the eight entries in the fixed order.
        $html = $this->get(route('hr-reports.index'))->assertOk()->getContent();
        $navStart = strpos($html, 'aria-label="HR report views"');
        $this->assertNotFalse($navStart, 'The report view navigation must be present.');
        $nav = substr($html, $navStart, strpos($html, '</nav>', $navStart) - $navStart);
        $this->assertSame(8, substr_count($nav, 'href='));
        $previous = -1;
        foreach ($expected as $key => $label) {
            $position = strpos($nav, $label);
            $this->assertNotFalse($position, "The nav must contain '{$label}'.");
            $this->assertGreaterThan($previous, $position, "Label '{$label}' must keep its exact position.");
            $this->assertStringContainsString('report='.$key, $nav);
            $previous = $position;
        }

        // Reports only carry the filters they can use.
        $this->assertSame(['search', 'faculty_id', 'department_id', 'designation_id', 'status', 'employment_type', 'from', 'to'], HrReportController::FILTERS['employees']);
        $this->assertContains('document_type_id', HrReportController::FILTERS['documents']);
        $this->assertContains('document_status', HrReportController::FILTERS['documents']);
        $this->assertContains('status', HrReportController::FILTERS['attendance']);
        $this->assertContains('leave_type_id', HrReportController::FILTERS['leave']);
        $this->assertContains('salary_structure_id', HrReportController::FILTERS['payroll']);
        $this->assertNotContains('leave_type_id', HrReportController::FILTERS['payroll']);
        $this->assertNotContains('document_type_id', HrReportController::FILTERS['leave']);
        $this->assertNotContains('from', HrReportController::FILTERS['summary']);
        $this->assertNotContains('status', HrReportController::FILTERS['summary']);
    }

    /* ------------------------------------------------------------------ *\
     * Sidebar placement
     * \* ------------------------------------------------------------------ */

    public function test_reports_menu_lists_hr_reports_after_finance_and_outside_the_hr_group(): void
    {
        $college = $this->makeCollege('HRRMENU');
        $user = $this->makeUserWithPermissions($college, [
            'inventory_dashboard.view', 'student_reports.view', 'academic_reports.view',
            'examination_reports.view', 'finance_reports.view', 'hr_reports.view',
            'faculties.view', 'departments.view', 'designations.view', 'employee_documents.view',
            'staff_attendance.view', 'leave_requests.view', 'salary_structures.view',
        ]);
        $html = $this->asCollege($college, $user)->get(route('hr-reports.index'))->assertOk()->getContent();

        $inventory = strpos($html, '>Inventory / Asset Management<');
        $reports = strpos($html, '>REPORTS<');
        $student = strpos($html, 'href="'.route('student-reports.index').'"');
        $academic = strpos($html, 'href="'.route('academic-reports.index').'"');
        $examination = strpos($html, 'href="'.route('examination-reports.index').'"');
        $finance = strpos($html, 'href="'.route('finance-reports.index').'"');
        $hr = strpos($html, 'href="'.route('hr-reports.index').'"');
        $platform = strpos($html, '>Platform<', (int) $reports);
        $this->assertNotFalse($inventory);
        $this->assertTrue(
            $inventory < $reports && $reports < $student && $student < $academic
            && $academic < $examination && $examination < $finance && $finance < $hr && $hr < $platform
        );
        $this->assertSame(1, substr_count($html, '>REPORTS<'));

        // Five report links live between REPORTS and Platform; the HR Reports
        // child is the last one and carries an HR / staff icon.
        $menu = substr($html, $reports, $platform - $reports);
        $this->assertSame(5, substr_count($menu, 'class="nav-link"'));
        $this->assertStringContainsString('🧑‍💼', $menu);

        // The REPORTS heading itself stays plain (no link, no reordering).
        $this->assertStringNotContainsString('<a', substr($html, $reports - 80, 80));

        // HR Reports left the HR / Staff Management group: the group keeps its
        // seven operational entries and no longer mentions HR Reports.
        $hrStart = strpos($html, '>HR / Staff Management</div>');
        $this->assertNotFalse($hrStart);
        $hrEnd = strpos($html, 'uppercase tracking-widest', $hrStart + 1);
        $group = substr($html, $hrStart, $hrEnd === false ? null : $hrEnd - $hrStart);
        $this->assertSame(7, substr_count($group, 'class="nav-link"'));
        $this->assertStringNotContainsString('HR Reports', $group);
        foreach (['Staff / Employee', 'Staff Departments', 'Designations', 'Employee Documents', 'Staff Attendance', 'Leave Management', 'Staff Salary / Payroll'] as $label) {
            $this->assertStringContainsString($label, $group);
        }

        // A report permission is the only thing that reveals the section: a user
        // holding only hr_reports.view sees REPORTS and the HR link.
        $solo = $this->makeUserWithPermissions($college, self::VIEW);
        $soloHtml = $this->asCollege($college, $solo)->get(route('hr-reports.index'))->assertOk()->getContent();
        $this->assertStringContainsString('>REPORTS<', $soloHtml);
        $this->assertStringContainsString('href="'.route('hr-reports.index').'"', $soloHtml);
        $this->assertStringNotContainsString('>HR / Staff Management</div>', $soloHtml);
    }

    /* ------------------------------------------------------------------ *\
     * Helpers
     * \* ------------------------------------------------------------------ */

    private function reporter(College $college): User
    {
        $user = $this->makeUserWithPermissions($college, self::VIEW);
        $this->asCollege($college, $user);

        return $user;
    }

    /** @param array<string, mixed> $overrides */
    private function employee(College $college, string $code, array $overrides = []): Faculty
    {
        return Faculty::create([
            'college_id' => $college->id,
            'employee_code' => $code,
            'first_name' => 'Staff',
            'last_name' => $code,
            'email' => strtolower($code).'@example.test',
            'status' => 'active',
            ...$overrides,
        ]);
    }

    /** @return array<string, int> */
    private function snapshot(): array
    {
        return [
            ...collect(self::TABLES)->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])->all(),
            'audit_logs' => AuditLog::count(),
        ];
    }

    /* ------------------------------------------------------------------ *\
     * Tenant isolation + forged foreign filter ids
     * \* ------------------------------------------------------------------ */

    public function test_every_report_is_tenant_scoped_even_with_foreign_filter_ids(): void
    {
        $a = $this->makeCollege('HRRTA');
        $b = $this->makeCollege('HRRTB');

        $bDept = Department::create(['college_id' => $b->id, 'name' => 'B Department', 'code' => 'B-DEPT', 'status' => 'active']);
        $bDes = Designation::create(['college_id' => $b->id, 'name' => 'B Designation', 'code' => 'B-DES', 'status' => 'active']);
        $bStaff = $this->employee($b, 'B-001', ['department_id' => $bDept->id, 'designation_id' => $bDes->id]);
        $bType = LeaveType::create(['college_id' => $b->id, 'name' => 'B Leave', 'code' => 'B-LV', 'status' => 'active']);
        $bStructure = SalaryStructure::create(['college_id' => $b->id, 'name' => 'B Pay', 'code' => 'B-PAY', 'status' => 'active']);
        $bDocType = AdmissionDocumentType::create(['college_id' => $b->id, 'name' => 'B Document', 'code' => 'B-DOC', 'status' => 'active']);

        $this->document($bStaff, $b, 'B Contract', $bDocType->id, '2026-01-01', '2027-01-01');
        StaffAttendance::create(['college_id' => $b->id, 'faculty_id' => $bStaff->id, 'attendance_date' => '2026-09-01', 'status' => 'present']);
        LeaveRequest::create(['college_id' => $b->id, 'faculty_id' => $bStaff->id, 'leave_type_id' => $bType->id, 'from_date' => '2026-09-01', 'to_date' => '2026-09-02', 'days' => 2, 'reason' => 'B reason', 'status' => 'approved']);
        Payroll::create(['college_id' => $b->id, 'faculty_id' => $bStaff->id, 'salary_structure_id' => $bStructure->id, 'pay_period' => '2026-08-01', 'basic_amount' => 1000, 'gross_amount' => 1200, 'total_deductions' => 100, 'net_amount' => 1100, 'status' => 'processed', 'processed_at' => now()]);

        $bReporter = $this->makeUserWithPermissions($b, self::VIEW);
        $this->asCollege($b, $bReporter)->get(route('hr-reports.index', ['report' => 'employees']))
            ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 1);
        foreach (['documents', 'attendance', 'leave', 'payroll'] as $report) {
            $this->asCollege($b, $bReporter)->get(route('hr-reports.index', ['report' => $report]))
                ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 1);
        }

        // College A is empty. Its reporter must see nothing, and a forged
        // foreign id must never reveal college B's records.
        $aReporter = $this->makeUserWithPermissions($a, self::VIEW);
        $forged = [
            'employees' => ['faculty_id' => $bStaff->id, 'department_id' => $bDept->id, 'designation_id' => $bDes->id],
            'documents' => ['faculty_id' => $bStaff->id, 'department_id' => $bDept->id, 'designation_id' => $bDes->id, 'document_type_id' => $bDocType->id],
            'attendance' => ['faculty_id' => $bStaff->id, 'department_id' => $bDept->id, 'designation_id' => $bDes->id],
            'leave' => ['faculty_id' => $bStaff->id, 'department_id' => $bDept->id, 'leave_type_id' => $bType->id],
            'payroll' => ['faculty_id' => $bStaff->id, 'department_id' => $bDept->id, 'salary_structure_id' => $bStructure->id],
        ];
        foreach ($forged as $report => $params) {
            $this->asCollege($a, $aReporter)->get(route('hr-reports.index', ['report' => $report] + $params))
                ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 0);
        }
        foreach ([['department', 'department_id'], ['designation', 'designation_id']] as [$report, $key]) {
            $this->asCollege($a, $aReporter)
                ->get(route('hr-reports.index', ['report' => $report, $key => $report === 'department' ? $bDept->id : $bDes->id]))
                ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 0);
        }
        $this->asCollege($a, $aReporter)->get(route('hr-reports.index', ['report' => 'summary', 'faculty_id' => $bStaff->id]))
            ->assertOk()->assertViewHas('summary', fn (array $summary) => $summary['staff']['total'] === 0
                && $summary['documents']['documents'] === 0
                && $summary['attendance']['records'] === 0
                && $summary['leave']['requests'] === 0
                && $summary['payroll']['payrolls'] === 0
                && $summary['department_breakdown'] === []);
    }

    /* ------------------------------------------------------------------ *\
     * Staff list + department / designation relationships
     * \* ------------------------------------------------------------------ */

    public function test_staff_and_master_reports_follow_the_existing_department_and_designation_relationships(): void
    {
        $college = $this->makeCollege('HRRREL');
        $this->reporter($college);

        $science = Department::create(['college_id' => $college->id, 'name' => 'Science', 'code' => 'SCI', 'status' => 'active']);
        $arts = Department::create(['college_id' => $college->id, 'name' => 'Arts', 'code' => 'ART', 'status' => 'active']);
        $professor = Designation::create(['college_id' => $college->id, 'name' => 'Professor', 'code' => 'PROF', 'status' => 'active']);
        $assistant = Designation::create(['college_id' => $college->id, 'name' => 'Assistant', 'code' => 'ASST', 'status' => 'active']);

        $alpha = $this->employee($college, 'REL-001', ['department_id' => $science->id, 'designation_id' => $professor->id, 'status' => 'active', 'employment_type' => 'permanent', 'joining_date' => '2026-01-10']);
        $beta = $this->employee($college, 'REL-002', ['department_id' => $science->id, 'designation_id' => $assistant->id, 'status' => 'inactive', 'employment_type' => 'contract', 'joining_date' => '2026-06-01']);
        $gamma = $this->employee($college, 'REL-003', ['department_id' => $arts->id, 'designation_id' => $professor->id, 'status' => 'active', 'employment_type' => 'permanent', 'joining_date' => '2025-03-05']);
        $this->document($alpha, $college, 'Employment Contract', null, '2026-01-10', '2027-01-10');

        // Staff list: department / designation names, document count and totals.
        $this->get(route('hr-reports.index', ['report' => 'employees']))
            ->assertOk()
            ->assertViewHas('totals', fn (array $t) => $t['staff'] === 3 && $t['active'] === 2 && $t['inactive'] === 1 && $t['with_documents'] === 1)
            ->assertSee('Science')->assertSee('Arts')->assertSee('Professor')->assertSee('Assistant')
            ->assertSee('REL-001');
        $row = $this->get(route('hr-reports.index', ['report' => 'employees']))->viewData('rows')->firstWhere('id', $alpha->id);
        $this->assertSame(1, (int) $row->documents_count);
        $this->assertSame($science->id, $row->department->id);
        $this->assertSame($professor->id, $row->designationMaster->id);

        // Each staff filter narrows on the existing columns.
        $this->get(route('hr-reports.index', ['report' => 'employees', 'department_id' => $science->id]))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 2 && $rows->pluck('id')->sort()->values()->all() === [$alpha->id, $beta->id]);
        $this->get(route('hr-reports.index', ['report' => 'employees', 'designation_id' => $professor->id]))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 2 && $rows->pluck('id')->sort()->values()->all() === [$alpha->id, $gamma->id]);
        $this->get(route('hr-reports.index', ['report' => 'employees', 'status' => 'active']))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 2 && $rows->pluck('id')->sort()->values()->all() === [$alpha->id, $gamma->id]);
        $this->get(route('hr-reports.index', ['report' => 'employees', 'employment_type' => 'contract']))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $beta->id);
        $this->get(route('hr-reports.index', ['report' => 'employees', 'from' => '2026-01-01', 'to' => '2026-05-01']))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $alpha->id);
        $this->get(route('hr-reports.index', ['report' => 'employees', 'search' => 'REL-003']))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $gamma->id);

        // Department report: live counts per department, honouring staff filters.
        $page = $this->get(route('hr-reports.index', ['report' => 'department']))
            ->assertOk()
            ->assertViewHas('totals', fn (array $t) => $t['departments'] === 2 && $t['staff'] === 3 && $t['active'] === 2 && $t['with_documents'] === 1);
        $scienceRow = $page->viewData('rows')->firstWhere('id', $science->id);
        $this->assertSame(2, (int) $scienceRow->staff_count);
        $this->assertSame(1, (int) $scienceRow->active_staff_count);
        $this->assertSame(1, (int) $scienceRow->inactive_staff_count);
        $this->assertSame(1, (int) $scienceRow->documented_staff_count);
        $activeRow = $this->get(route('hr-reports.index', ['report' => 'department', 'status' => 'active']))
            ->viewData('rows')->firstWhere('id', $science->id);
        $this->assertSame(1, (int) $activeRow->staff_count);
        $this->assertSame(0, (int) $activeRow->inactive_staff_count);
        $this->get(route('hr-reports.index', ['report' => 'department', 'department_id' => $arts->id]))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $arts->id);

        // Designation report: same relationship, counted per designation.
        $page = $this->get(route('hr-reports.index', ['report' => 'designation']))
            ->assertOk()
            ->assertViewHas('totals', fn (array $t) => $t['designations'] === 2 && $t['staff'] === 3 && $t['active'] === 2);
        $this->assertSame(2, (int) $page->viewData('rows')->firstWhere('id', $professor->id)->staff_count);
        $this->assertSame(1, (int) $page->viewData('rows')->firstWhere('id', $assistant->id)->staff_count);
        $this->get(route('hr-reports.index', ['report' => 'designation', 'department_id' => $arts->id]))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 2
                && (int) $rows->firstWhere('id', $professor->id)->staff_count === 1
                && (int) $rows->firstWhere('id', $assistant->id)->staff_count === 0);

        // Soft-deleted master data and staff disappear from every staff report.
        $arts->delete();
        $this->get(route('hr-reports.index', ['report' => 'department']))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $science->id);
        $gamma->delete();
        $this->get(route('hr-reports.index', ['report' => 'employees']))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 2);
        $this->get(route('hr-reports.index', ['report' => 'designation', 'designation_id' => $professor->id]))
            ->assertViewHas('rows', fn ($rows) => (int) $rows->first()->staff_count === 1);
    }

    /* ------------------------------------------------------------------ *\
     * Employee documents
     * \* ------------------------------------------------------------------ */

    public function test_employee_documents_report_derives_status_and_filters_on_the_stored_columns(): void
    {
        $college = $this->makeCollege('HRRDOC');
        $this->reporter($college);
        $staff = $this->employee($college, 'DOC-001');
        $other = $this->employee($college, 'DOC-002', ['status' => 'inactive']);
        $pan = AdmissionDocumentType::create(['college_id' => $college->id, 'name' => 'PAN Card', 'code' => 'PAN', 'status' => 'active']);
        $passport = AdmissionDocumentType::create(['college_id' => $college->id, 'name' => 'Passport', 'code' => 'PASSPORT', 'status' => 'active']);

        $valid = $this->document($staff, $college, 'PAN Card', $pan->id, '2026-01-01', '2027-01-01');
        $expiring = $this->document($staff, $college, 'Contract', $pan->id, '2026-02-01', now()->addDays(10)->toDateString());
        $expired = $this->document($staff, $college, 'Passport', $passport->id, '2024-01-01', now()->subDays(5)->toDateString());
        $noExpiry = $this->document($staff, $college, 'Degree', $pan->id, '2026-03-01', null);
        $this->document($other, $college, 'Inactive staff doc', $passport->id, '2026-04-01', '2027-04-01');

        $this->get(route('hr-reports.index', ['report' => 'documents']))
            ->assertOk()
            ->assertViewHas('totals', fn (array $t) => $t['documents'] === 5 && $t['staff'] === 2 && $t['expired'] === 1 && $t['expiring'] === 1)
            ->assertSee('PAN Card')->assertSee('Passport')->assertSee('Expired')->assertSee('Expiring');

        $row = $this->get(route('hr-reports.index', ['report' => 'documents']))->viewData('rows')->firstWhere('id', $valid->id);
        $this->assertSame('Valid', ucfirst(\App\Domain\HR\Services\HrReportService::documentStatus($row)));
        $this->assertSame($pan->id, $row->documentTypeMaster->id);
        $this->assertSame($staff->id, $row->employee->id);

        $this->get(route('hr-reports.index', ['report' => 'documents', 'document_status' => 'expired']))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $expired->id);
        $this->get(route('hr-reports.index', ['report' => 'documents', 'document_status' => 'expiring']))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $expiring->id);
        $this->get(route('hr-reports.index', ['report' => 'documents', 'document_status' => 'valid']))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 3 && $rows->pluck('id')->contains($noExpiry->id));
        $this->get(route('hr-reports.index', ['report' => 'documents', 'document_type_id' => $passport->id]))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 2);
        $this->get(route('hr-reports.index', ['report' => 'documents', 'faculty_id' => $staff->id]))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 4);
        $this->get(route('hr-reports.index', ['report' => 'documents', 'staff_status' => 'inactive']))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1);
        $this->get(route('hr-reports.index', ['report' => 'documents', 'from' => '2026-02-01', 'to' => '2026-02-28']))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $expiring->id);

        // Soft-deleted documents never appear.
        $expired->delete();
        $this->get(route('hr-reports.index', ['report' => 'documents']))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 4 && $rows->pluck('id')->doesntContain($expired->id));
    }

    /* ------------------------------------------------------------------ *\
     * Staff attendance
     * \* ------------------------------------------------------------------ */

    public function test_staff_attendance_report_lists_records_and_aggregates_the_filtered_range(): void
    {
        $college = $this->makeCollege('HRRATT');
        $this->reporter($college);
        $alpha = $this->employee($college, 'ATT-001');
        $beta = $this->employee($college, 'ATT-002', ['status' => 'inactive']);

        StaffAttendance::create(['college_id' => $college->id, 'faculty_id' => $alpha->id, 'attendance_date' => '2026-09-01', 'status' => 'present']);
        StaffAttendance::create(['college_id' => $college->id, 'faculty_id' => $alpha->id, 'attendance_date' => '2026-09-02', 'status' => 'late']);
        StaffAttendance::create(['college_id' => $college->id, 'faculty_id' => $beta->id, 'attendance_date' => '2026-09-02', 'status' => 'absent', 'remarks' => 'Sick']);
        StaffAttendance::create(['college_id' => $college->id, 'faculty_id' => $beta->id, 'attendance_date' => '2026-09-03', 'status' => 'holiday']);
        StaffAttendance::create(['college_id' => $college->id, 'faculty_id' => $alpha->id, 'attendance_date' => '2026-09-04', 'status' => 'leave']);

        $this->get(route('hr-reports.index', ['report' => 'attendance']))
            ->assertOk()
            ->assertViewHas('totals', fn (array $t) => $t['records'] === 5 && $t['present'] === 1 && $t['absent'] === 1
                && $t['late'] === 1 && $t['leave'] === 1 && $t['holiday'] === 1 && $t['rate'] === 50.0)
            ->assertSee('ATT-001')->assertSee('Sick');

        $this->get(route('hr-reports.index', ['report' => 'attendance', 'from' => '2026-09-02', 'to' => '2026-09-02']))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 2);
        $this->get(route('hr-reports.index', ['report' => 'attendance', 'status' => 'present']))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1
                && $rows->first()->faculty_id === $alpha->id
                && $rows->first()->attendance_date->toDateString() === '2026-09-01');
        $this->get(route('hr-reports.index', ['report' => 'attendance', 'faculty_id' => $alpha->id]))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 3);
        $this->get(route('hr-reports.index', ['report' => 'attendance', 'staff_status' => 'inactive']))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 2);
        $this->get(route('hr-reports.index', ['report' => 'attendance', 'from' => '2026-09-03']))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 2);

        // Attendance is append-only: soft-deleting a staff member keeps the
        // historical entry visible (the record still exists and is auditable).
        $beta->delete();
        $this->get(route('hr-reports.index', ['report' => 'attendance']))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 5);
    }

    /* ------------------------------------------------------------------ *\
     * Leave
     * \* ------------------------------------------------------------------ */

    public function test_leave_report_totals_days_and_filters_leave_type_status_and_window(): void
    {
        $college = $this->makeCollege('HRRLV');
        $this->reporter($college);
        $alpha = $this->employee($college, 'LV-001');
        $beta = $this->employee($college, 'LV-002', ['status' => 'inactive']);
        $casual = LeaveType::create(['college_id' => $college->id, 'name' => 'Casual Leave', 'code' => 'CL', 'max_days_per_year' => 12, 'status' => 'active']);
        $medical = LeaveType::create(['college_id' => $college->id, 'name' => 'Medical Leave', 'code' => 'ML', 'max_days_per_year' => 10, 'status' => 'inactive']);

        $approved = LeaveRequest::create(['college_id' => $college->id, 'faculty_id' => $alpha->id, 'leave_type_id' => $casual->id, 'from_date' => '2026-09-01', 'to_date' => '2026-09-03', 'days' => 3, 'reason' => 'Family', 'status' => 'approved']);
        $pending = LeaveRequest::create(['college_id' => $college->id, 'faculty_id' => $alpha->id, 'leave_type_id' => $medical->id, 'from_date' => '2026-09-10', 'to_date' => '2026-09-10', 'days' => 1, 'reason' => 'Checkup', 'status' => 'pending']);
        $rejected = LeaveRequest::create(['college_id' => $college->id, 'faculty_id' => $beta->id, 'leave_type_id' => $casual->id, 'from_date' => '2026-08-25', 'to_date' => '2026-08-27', 'days' => 3, 'reason' => 'Travel', 'status' => 'rejected']);

        $page = $this->get(route('hr-reports.index', ['report' => 'leave']))
            ->assertOk()
            ->assertViewHas('totals', fn (array $t) => $t['requests'] === 3 && $t['days'] === 7 && $t['approved'] === 1
                && $t['pending'] === 1 && $t['rejected'] === 1 && $t['cancelled'] === 0 && $t['approved_days'] === 3)
            ->assertSee('Casual Leave')->assertSee('Medical Leave');
        $this->assertSame(2, $page->viewData('by_type')[0]['requests']);
        $this->assertSame(6, $page->viewData('by_type')[0]['days']);
        $this->assertSame('inactive', $page->viewData('by_type')[1]['type_status']);

        $this->get(route('hr-reports.index', ['report' => 'leave', 'leave_type_id' => $casual->id]))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 2 && $rows->pluck('id')->sort()->values()->all() === [$approved->id, $rejected->id]);
        $this->get(route('hr-reports.index', ['report' => 'leave', 'status' => 'approved']))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $approved->id);
        $this->get(route('hr-reports.index', ['report' => 'leave', 'staff_status' => 'inactive']))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $rejected->id);

        // The date range selects the requests that OVERLAP it.
        $this->get(route('hr-reports.index', ['report' => 'leave', 'from' => '2026-09-02', 'to' => '2026-09-09']))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $approved->id);
        $this->get(route('hr-reports.index', ['report' => 'leave', 'from' => '2026-09-04']))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $pending->id);
        $this->get(route('hr-reports.index', ['report' => 'leave', 'to' => '2026-08-26']))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $rejected->id);
    }

    /* ------------------------------------------------------------------ *\
     * Payroll / salary
     * \* ------------------------------------------------------------------ */

    public function test_payroll_report_reads_the_stored_money_and_filters_period_status_and_structure(): void
    {
        $college = $this->makeCollege('HRRPAY');
        $this->reporter($college);
        $alpha = $this->employee($college, 'PAY-001');
        $beta = $this->employee($college, 'PAY-002');
        $academic = SalaryStructure::create(['college_id' => $college->id, 'name' => 'Academic Pay', 'code' => 'APS', 'status' => 'active']);
        $admin = SalaryStructure::create(['college_id' => $college->id, 'name' => 'Admin Pay', 'code' => 'ADM', 'status' => 'active']);

        $august = Payroll::create(['college_id' => $college->id, 'faculty_id' => $alpha->id, 'salary_structure_id' => $academic->id, 'pay_period' => '2026-08-01', 'basic_amount' => 20000, 'gross_amount' => 25000, 'total_deductions' => 3000, 'net_amount' => 22000, 'status' => 'processed', 'processed_at' => now()]);
        $september = Payroll::create(['college_id' => $college->id, 'faculty_id' => $alpha->id, 'salary_structure_id' => $academic->id, 'pay_period' => '2026-09-01', 'basic_amount' => 20000, 'gross_amount' => 25000, 'total_deductions' => 3000, 'net_amount' => 22000, 'status' => 'processed', 'processed_at' => now()]);
        $cancelled = Payroll::create(['college_id' => $college->id, 'faculty_id' => $beta->id, 'salary_structure_id' => $admin->id, 'pay_period' => '2026-09-01', 'basic_amount' => 10000, 'gross_amount' => 12000, 'total_deductions' => 1000, 'net_amount' => 11000, 'status' => 'cancelled']);
        // A stored record whose net intentionally does not equal gross − deductions:
        // the report must show the stored money, never recalculate it.
        $odd = Payroll::create(['college_id' => $college->id, 'faculty_id' => $beta->id, 'salary_structure_id' => $admin->id, 'pay_period' => '2026-07-01', 'basic_amount' => 4000, 'gross_amount' => 5000, 'total_deductions' => 500, 'net_amount' => 4300, 'status' => 'processed', 'processed_at' => now()]);

        $page = $this->get(route('hr-reports.index', ['report' => 'payroll']))
            ->assertOk()
            ->assertViewHas('totals', fn (array $t) => $t['payrolls'] === 4 && $t['basic'] === 54000.0 && $t['gross'] === 67000.0
                && $t['deductions'] === 7500.0 && $t['net'] === 59300.0)
            ->assertSee('Academic Pay')->assertSee('Sep 2026')->assertSee('22,000.00')->assertSee('Cancelled');
        $this->assertSame(3, $page->viewData('by_status')[0]['payrolls']);
        $this->assertSame(1, $page->viewData('by_status')[1]['payrolls']);

        $oddRow = $page->viewData('rows')->firstWhere('id', $odd->id);
        $this->assertSame('4300.00', (string) $oddRow->net_amount);

        $this->get(route('hr-reports.index', ['report' => 'payroll', 'from' => '2026-09', 'to' => '2026-09']))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 2 && $rows->pluck('id')->sort()->values()->all() === [$september->id, $cancelled->id]);
        $this->get(route('hr-reports.index', ['report' => 'payroll', 'status' => 'cancelled']))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $cancelled->id);
        $this->get(route('hr-reports.index', ['report' => 'payroll', 'salary_structure_id' => $admin->id]))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 2 && $rows->pluck('id')->contains($odd->id));
        $this->get(route('hr-reports.index', ['report' => 'payroll', 'faculty_id' => $beta->id]))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 2);
    }

    /* ------------------------------------------------------------------ *\
     * HR Summary
     * \* ------------------------------------------------------------------ */

    public function test_hr_summary_aggregates_the_existing_modules_and_respects_the_staff_filters(): void
    {
        $college = $this->makeCollege('HRRSUM');
        $this->reporter($college);
        $science = Department::create(['college_id' => $college->id, 'name' => 'Science', 'code' => 'SCI', 'status' => 'active']);
        Department::create(['college_id' => $college->id, 'name' => 'Arts', 'code' => 'ART', 'status' => 'active']);
        $professor = Designation::create(['college_id' => $college->id, 'name' => 'Professor', 'code' => 'PROF', 'status' => 'active']);
        $alpha = $this->employee($college, 'SUM-001', ['department_id' => $science->id, 'designation_id' => $professor->id, 'status' => 'active']);
        $beta = $this->employee($college, 'SUM-002', ['department_id' => $science->id, 'designation_id' => $professor->id, 'status' => 'inactive']);
        $this->document($alpha, $college, 'Contract', null, '2026-01-01', '2027-01-01');
        StaffAttendance::create(['college_id' => $college->id, 'faculty_id' => $alpha->id, 'attendance_date' => '2026-09-01', 'status' => 'present']);
        StaffAttendance::create(['college_id' => $college->id, 'faculty_id' => $beta->id, 'attendance_date' => '2026-09-01', 'status' => 'absent']);
        $casual = LeaveType::create(['college_id' => $college->id, 'name' => 'Casual Leave', 'code' => 'CL', 'status' => 'active']);
        LeaveRequest::create(['college_id' => $college->id, 'faculty_id' => $alpha->id, 'leave_type_id' => $casual->id, 'from_date' => '2026-09-01', 'to_date' => '2026-09-02', 'days' => 2, 'reason' => 'Family', 'status' => 'approved']);
        $structure = SalaryStructure::create(['college_id' => $college->id, 'name' => 'Academic Pay', 'code' => 'APS', 'status' => 'active']);
        Payroll::create(['college_id' => $college->id, 'faculty_id' => $alpha->id, 'salary_structure_id' => $structure->id, 'pay_period' => '2026-08-01', 'basic_amount' => 20000, 'gross_amount' => 25000, 'total_deductions' => 3000, 'net_amount' => 22000, 'status' => 'processed', 'processed_at' => now()]);

        $page = $this->get(route('hr-reports.index', ['report' => 'summary']))
            ->assertOk()
            ->assertSee('HR Summary')
            ->assertViewHas('summary', fn (array $s) => $s['staff']['total'] === 2 && $s['staff']['active'] === 1
                && $s['staff']['inactive'] === 1 && $s['staff']['with_documents'] === 1
                && $s['master']['departments'] === 2 && $s['master']['designations'] === 1
                && $s['documents']['documents'] === 1 && $s['documents']['expiring'] === 0 && $s['documents']['expired'] === 0
                && $s['attendance']['records'] === 2 && $s['attendance']['present'] === 1 && $s['attendance']['absent'] === 1
                && $s['leave']['requests'] === 1 && $s['leave']['days'] === 2 && $s['leave']['approved'] === 1
                && $s['payroll']['payrolls'] === 1 && $s['payroll']['net'] === 22000.0
                && $s['reconciliation']['difference'] === 0.0);
        $this->assertSame('Science', $page->viewData('summary')['department_breakdown'][0]['department']);
        $this->assertSame(2, $page->viewData('summary')['department_breakdown'][0]['staff']);
        $this->assertSame('Casual Leave', $page->viewData('summary')['leave']['by_type'][0]['leave_type']);

        // The staff scope filters the whole summary.
        $this->get(route('hr-reports.index', ['report' => 'summary', 'department_id' => $science->id]))
            ->assertViewHas('summary', fn (array $s) => $s['staff']['total'] === 2);
        $this->get(route('hr-reports.index', ['report' => 'summary', 'staff_status' => 'inactive']))
            ->assertViewHas('summary', fn (array $s) => $s['staff']['total'] === 1 && $s['staff']['active'] === 0
                && $s['documents']['documents'] === 0 && $s['leave']['requests'] === 0 && $s['payroll']['payrolls'] === 0);
        $this->get(route('hr-reports.index', ['report' => 'summary', 'designation_id' => $professor->id, 'staff_status' => 'active']))
            ->assertViewHas('summary', fn (array $s) => $s['staff']['total'] === 1 && $s['attendance']['present'] === 1);
    }

    /* ------------------------------------------------------------------ *\
     * Empty states + invalid filters
     * \* ------------------------------------------------------------------ */

    public function test_empty_results_invalid_filters_and_irrelevant_filters_are_handled_gracefully(): void
    {
        $college = $this->makeCollege('HRREMPTY');
        $this->reporter($college);

        foreach (HrReportController::REPORTS as $report => $label) {
            $page = $this->get(route('hr-reports.index', ['report' => $report]))
                ->assertOk()->assertSee('No ');

            if ($report === 'summary') {
                $page->assertViewHas('summary', fn (array $s) => $s['staff']['total'] === 0
                    && $s['master']['departments'] === 0 && $s['master']['designations'] === 0
                    && $s['documents']['documents'] === 0 && $s['attendance']['records'] === 0
                    && $s['leave']['requests'] === 0 && $s['payroll']['payrolls'] === 0
                    && $s['reconciliation']['difference'] === 0.0 && $s['department_breakdown'] === []);
            } else {
                $page->assertViewHas('rows', fn ($rows) => $rows->total() === 0);
            }
        }

        // An unknown report falls back to the first report, never to an error.
        $this->get(route('hr-reports.index', ['report' => 'not_a_report']))
            ->assertOk()->assertViewHas('report', 'employees');

        // Date windows and vocabularies are validated per report.
        $this->get(route('hr-reports.index', ['report' => 'employees', 'status' => 'made-up']))->assertSessionHasErrors('status');
        $this->get(route('hr-reports.index', ['report' => 'attendance', 'status' => 'made-up']))->assertSessionHasErrors('status');
        $this->get(route('hr-reports.index', ['report' => 'leave', 'status' => 'made-up']))->assertSessionHasErrors('status');
        $this->get(route('hr-reports.index', ['report' => 'payroll', 'status' => 'made-up']))->assertSessionHasErrors('status');
        $this->get(route('hr-reports.index', ['report' => 'employees', 'staff_status' => 'made-up']))->assertOk();
        $this->get(route('hr-reports.index', ['report' => 'documents', 'staff_status' => 'made-up']))->assertSessionHasErrors('staff_status');
        $this->get(route('hr-reports.index', ['report' => 'employees', 'employment_type' => 'made-up']))->assertSessionHasErrors('employment_type');
        $this->get(route('hr-reports.index', ['report' => 'documents', 'document_status' => 'made-up']))->assertSessionHasErrors('document_status');
        $this->get(route('hr-reports.index', ['report' => 'employees', 'from' => '2026-09-01', 'to' => '2026-08-01']))->assertSessionHasErrors('to');
        $this->get(route('hr-reports.index', ['report' => 'payroll', 'from' => '2026-13']))->assertSessionHasErrors('from');
        $this->get(route('hr-reports.index', ['report' => 'employees', 'faculty_id' => 'abc']))->assertSessionHasErrors('faculty_id');
        $this->get(route('hr-reports.index', ['report' => 'documents', 'document_type_id' => 'abc']))->assertSessionHasErrors('document_type_id');

        // Filter keys a report does not use are ignored, not enforced.
        $this->get(route('hr-reports.index', [
            'report' => 'summary', 'salary_structure_id' => 999999, 'leave_type_id' => 999999,
            'document_type_id' => 999999, 'status' => 'made-up', 'document_status' => 'made-up',
            'employment_type' => 'made-up', 'from' => 'not-a-date',
        ]))
            ->assertOk()
            ->assertViewHas('filters', fn (array $filters) => $filters['salary_structure_id'] === null
                && $filters['leave_type_id'] === null && $filters['document_type_id'] === null
                && $filters['status'] === null && $filters['document_status'] === null
                && $filters['employment_type'] === null && $filters['from'] === null);

        // Ids that point nowhere simply match nothing.
        $this->get(route('hr-reports.index', ['report' => 'employees', 'department_id' => 999999]))
            ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 0);
        $this->get(route('hr-reports.index', ['report' => 'attendance', 'faculty_id' => 999999]))
            ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 0);
    }

    /* ------------------------------------------------------------------ *\
     * Pagination
     * \* ------------------------------------------------------------------ */

    public function test_lists_paginate_deterministically_and_keep_their_filters(): void
    {
        $college = $this->makeCollege('HRRPAGE');
        $this->reporter($college);
        $staff = [];
        foreach (range(1, 25) as $i) {
            $staff[$i] = $this->employee($college, sprintf('PG-%02d', $i), ['status' => 'active']);
        }
        foreach (range(1, 3) as $i) {
            $this->employee($college, sprintf('OLD-%02d', $i), ['status' => 'inactive']);
        }

        $first = $this->get(route('hr-reports.index', ['report' => 'employees', 'status' => 'active']))
            ->assertOk()
            ->assertSee('Showing 1–20 of 25')
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 25 && $rows->perPage() === 20 && $rows->count() === 20);
        $paginator = $first->viewData('rows');
        $this->assertStringContainsString('report=employees', $paginator->nextPageUrl());
        $this->assertStringContainsString('status=active', $paginator->nextPageUrl());

        $second = $this->get(route('hr-reports.index', ['report' => 'employees', 'status' => 'active', 'page' => 2]))
            ->assertOk()
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 25 && $rows->count() === 5);
        $pageOneIds = $first->viewData('rows')->pluck('id')->all();
        $pageTwoIds = $second->viewData('rows')->pluck('id')->all();
        $this->assertSame([], array_intersect($pageOneIds, $pageTwoIds), 'Pages must never repeat rows.');
        $this->assertCount(25, array_unique([...$pageOneIds, ...$pageTwoIds]));

        // The attendance report paginates on the same rules and keeps a date window.
        foreach (range(1, 25) as $day) {
            StaffAttendance::create([
                'college_id' => $college->id,
                'faculty_id' => $staff[$day]->id,
                'attendance_date' => sprintf('2026-08-%02d', $day),
                'status' => 'present',
            ]);
        }
        $page = $this->get(route('hr-reports.index', ['report' => 'attendance', 'from' => '2026-08-01', 'to' => '2026-08-25']))
            ->assertOk()
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 25 && $rows->perPage() === 20 && $rows->count() === 20);
        $this->assertStringContainsString('from=2026-08-01', $page->viewData('rows')->nextPageUrl());
        $this->get(route('hr-reports.index', ['report' => 'attendance', 'from' => '2026-08-01', 'to' => '2026-08-25', 'page' => 2]))
            ->assertOk()
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 25 && $rows->count() === 5 && $rows->pluck('id')->doesntContain($page->viewData('rows')->first()->id));
    }

    /* ------------------------------------------------------------------ *\
     * GET-only + read-only
     * \* ------------------------------------------------------------------ */

    public function test_report_routes_never_write_and_expose_only_get(): void
    {
        $college = $this->makeCollege('HRRREAD');
        $this->reporter($college);
        $this->world($college, 'READ');

        $before = $this->snapshot();
        foreach (array_keys(HrReportController::REPORTS) as $report) {
            $this->get(route('hr-reports.index', ['report' => $report]))->assertOk();
        }
        $this->assertSame($before, $this->snapshot(), 'Rendering every report must not change a single row.');

        $this->post(route('hr-reports.index'), ['report' => 'summary'])->assertStatus(405);
        $this->put(route('hr-reports.index'))->assertStatus(405);
        $this->patch(route('hr-reports.index'))->assertStatus(405);
        $this->delete(route('hr-reports.index'))->assertStatus(405);
        $this->get('/hr-reports/create')->assertNotFound();
        $this->get('/hr-reports/1/edit')->assertNotFound();
        $this->assertSame($before, $this->snapshot());

        $methods = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'hr-reports'))
            ->flatMap(fn ($route) => $route->methods())->unique()->sort()->values()->all();
        $this->assertSame(['GET', 'HEAD'], $methods);
    }

    /* ------------------------------------------------------------------ *\
     * Query-count / N+1 protection
     * \* ------------------------------------------------------------------ */

    public function test_query_count_does_not_grow_with_rows_on_any_report(): void
    {
        $college = $this->makeCollege('HRRNPLUS');
        $this->reporter($college);
        $this->world($college, 'SMALL');

        $logs = fn (): array => collect(array_keys(HrReportController::REPORTS))
            ->mapWithKeys(fn (string $report) => [$report => $this->queryLogFor(route('hr-reports.index', ['report' => $report]))])
            ->all();
        $small = $logs();

        // Grow every dataset past one page (20 rows).
        foreach (range(1, 21) as $i) {
            $staff = $this->employee($college, sprintf('GROW-%03d', $i));
            $this->document($staff, $college, 'Growth doc '.$i, null, '2026-01-01', '2027-01-01');
            StaffAttendance::create(['college_id' => $college->id, 'faculty_id' => $staff->id, 'attendance_date' => '2026-09-01', 'status' => 'present']);
        }
        $leaveType = LeaveType::firstOrFail();
        $structure = SalaryStructure::firstOrFail();
        foreach (Faculty::query()->orderBy('id')->get() as $staff) {
            LeaveRequest::create(['college_id' => $college->id, 'faculty_id' => $staff->id, 'leave_type_id' => $leaveType->id, 'from_date' => '2026-09-05', 'to_date' => '2026-09-06', 'days' => 2, 'reason' => 'Growth', 'status' => 'pending']);
            Payroll::create(['college_id' => $college->id, 'faculty_id' => $staff->id, 'salary_structure_id' => $structure->id, 'pay_period' => '2026-06-01', 'basic_amount' => 1000, 'gross_amount' => 1200, 'total_deductions' => 200, 'net_amount' => 1000, 'status' => 'processed', 'processed_at' => now()]);
        }

        $grown = $logs();

        // Growing the tables may make Laravel SKIP an eager-load query, but it
        // must never ADD one: every report reads its page with a fixed number
        // of queries.
        foreach (array_keys(HrReportController::REPORTS) as $report) {
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
        // same number of queries: nothing is loaded per row.
        foreach (['employees', 'documents', 'attendance', 'leave', 'payroll'] as $report) {
            $one = $this->queryLogFor(route('hr-reports.index', ['report' => $report, 'search' => 'GROW-001']));
            $full = $this->queryLogFor(route('hr-reports.index', ['report' => $report, 'search' => 'GROW-']));
            $this->assertSame(
                count($one),
                count($full),
                sprintf('Report [%s] must not run a query per row (one row: %d queries, a full page: %d).', $report, count($one), count($full)),
            );
        }
    }

    /* ------------------------------------------------------------------ *\
     * Legacy route contract
     * \* ------------------------------------------------------------------ */

    public function test_legacy_hr_reports_route_contract_is_preserved(): void
    {
        $college = $this->makeCollege('HRRLEGACY');
        $this->reporter($college);
        $employee = $this->employee($college, 'REP-001');
        StaffAttendance::create(['college_id' => $college->id, 'faculty_id' => $employee->id, 'attendance_date' => '2026-09-22', 'status' => 'present']);
        StaffAttendance::create(['college_id' => $college->id, 'faculty_id' => $employee->id, 'attendance_date' => '2026-09-23', 'status' => 'late']);

        $html = $this->get(route('hr-reports.index', [
            'report' => 'attendance', 'faculty_id' => $employee->id, 'from' => '2026-09-22', 'to' => '2026-09-22',
        ]))->assertOk()->getContent();
        $this->assertStringContainsString('>Present</td>', $html);
        $this->assertStringNotContainsString('>Late</td>', $html);
        $this->assertStringNotContainsString('Create', $html);
    }

    /* ------------------------------------------------------------------ *\
     * Fixtures
     * \* ------------------------------------------------------------------ */

    /**
     * A small but complete HR world: two staff in one department / designation,
     * a document, attendance, a leave request and a payroll record.
     */
    private function world(College $college, string $prefix): void
    {
        $department = Department::create(['college_id' => $college->id, 'name' => $prefix.' Department', 'code' => 'D-'.$prefix, 'status' => 'active']);
        $designation = Designation::create(['college_id' => $college->id, 'name' => $prefix.' Designation', 'code' => 'G-'.$prefix, 'status' => 'active']);
        $type = LeaveType::create(['college_id' => $college->id, 'name' => $prefix.' Leave', 'code' => 'L-'.$prefix, 'status' => 'active']);
        $structure = SalaryStructure::create(['college_id' => $college->id, 'name' => $prefix.' Pay', 'code' => 'P-'.$prefix, 'status' => 'active']);

        $alpha = $this->employee($college, $prefix.'-001', ['department_id' => $department->id, 'designation_id' => $designation->id]);
        $beta = $this->employee($college, $prefix.'-002', ['department_id' => $department->id, 'designation_id' => $designation->id, 'status' => 'inactive']);

        $this->document($alpha, $college, $prefix.' Contract', null, '2026-01-01', '2027-01-01');
        StaffAttendance::create(['college_id' => $college->id, 'faculty_id' => $alpha->id, 'attendance_date' => '2026-09-01', 'status' => 'present']);
        StaffAttendance::create(['college_id' => $college->id, 'faculty_id' => $beta->id, 'attendance_date' => '2026-09-01', 'status' => 'absent']);
        LeaveRequest::create(['college_id' => $college->id, 'faculty_id' => $alpha->id, 'leave_type_id' => $type->id, 'from_date' => '2026-09-01', 'to_date' => '2026-09-02', 'days' => 2, 'reason' => $prefix.' leave', 'status' => 'approved']);
        Payroll::create(['college_id' => $college->id, 'faculty_id' => $alpha->id, 'salary_structure_id' => $structure->id, 'pay_period' => '2026-08-01', 'basic_amount' => 20000, 'gross_amount' => 25000, 'total_deductions' => 3000, 'net_amount' => 22000, 'status' => 'processed', 'processed_at' => now()]);
    }

    private function document(Faculty $staff, College $college, string $name, ?int $typeId, ?string $issued, ?string $expires): EmployeeDocument
    {
        return EmployeeDocument::create([
            'college_id' => $college->id,
            'faculty_id' => $staff->id,
            'document_name' => $name,
            'document_type' => $name,
            'document_type_id' => $typeId,
            'file_path' => 'employee-documents/'.$college->id.'/'.$staff->id.'/'.str()->uuid().'.pdf',
            'original_filename' => str()->slug($name).'.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1024,
            'issue_date' => $issued,
            'expiry_date' => $expires,
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

}
