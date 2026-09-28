<?php

namespace Tests\Feature\HrReports;

use App\Http\Controllers\HrReportController;
use App\Models\{AuditLog, College, Department, Designation, EmployeeDocument, Faculty, LeaveRequest, LeaveType, Payroll, Permission, Role, SalaryStructure, StaffAttendance, User};
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\HR\HRTestHelpers;
use Tests\TestCase;

/**
 * HR Reports — the eight read-only reports over the existing HR / Staff records.
 *
 * Covers the acceptance criteria of the module: the dedicated permission and its
 * separation from the operational HR permissions, the placement and order of the
 * eight report views, tenant isolation with forged foreign filter ids, the
 * relevant filters (and the ignoring of irrelevant ones), the figures read from
 * the existing records, empty / invalid input, soft-deleted and inactive rows,
 * deterministic pagination with filters preserved, GET-only read-only routes and
 * query-count stability as rows grow.
 */
class HrReportsTest extends TestCase
{
    use HRTestHelpers;

    private const VIEW = ['hr_reports.view'];

    private const TABLES = [
        'faculties', 'departments', 'designations', 'employee_documents',
        'staff_attendances', 'leave_requests', 'leave_types', 'payrolls', 'audit_logs',
    ];

    /* ------------------------------------------------------------------ *
     * RBAC
     * ------------------------------------------------------------------ */

    public function test_the_view_permission_is_required_and_separate_from_hr_permissions(): void
    {
        $college = $this->makeCollege('HRRBAC');

        // The operational HR permissions alone never open the reports.
        $operator = $this->makeUserWithPermissions($college, [
            'faculties.view', 'departments.view', 'designations.view', 'employee_documents.view',
            'staff_attendance.view', 'leave_requests.view', 'payrolls.view',
        ]);
        $this->asCollege($college, $operator)->get(route('hr-reports.index'))->assertForbidden();

        // The report permission opens every report view…
        $reporter = $this->reporter($college);
        foreach (array_keys(HrReportController::REPORTS) as $report) {
            $this->asCollege($college, $reporter)->get(route('hr-reports.index', ['report' => $report]))->assertOk();
        }

        // …and nothing else: the report viewer holds no operational HR access.
        $this->asCollege($college, $reporter)->get(route('employees.index'))->assertForbidden();
        $this->asCollege($college, $reporter)->get(route('payrolls.index'))->assertForbidden();
        $this->asCollege($college, $reporter)->get(route('staff-attendance.index'))->assertForbidden();

        // The permission is seeded for the administrative roles.
        $this->assertNotNull(Permission::query()->where('slug', 'hr_reports.view')->first());

        // A user of another college can never reach the reports.
        $other = $this->makeCollege('HRRBACB');
        $foreign = $this->reporter($other);
        $this->asCollege($college, $foreign)->get(route('hr-reports.index'))->assertForbidden();

        // An inactive permission holder loses the screen.
        $reporter->update(['is_active' => false]);
        $this->asCollege($college, $reporter->fresh())->get(route('hr-reports.index'))->assertForbidden();
    }

    /* ------------------------------------------------------------------ *
     * Navigation
     * ------------------------------------------------------------------ */

    public function test_the_eight_reports_are_listed_in_order_and_the_sidebar_entry_is_gated(): void
    {
        $college = $this->makeCollege('HRNAV');
        $reporter = $this->reporter($college);

        $html = $this->asCollege($college, $reporter)->get(route('hr-reports.index'))->assertOk()->getContent();

        $start = strpos($html, 'aria-label="HR report views"');
        $this->assertNotFalse($start, 'The report switcher must be rendered.');
        $end = strpos($html, '</nav>', $start);
        $nav = substr($html, $start, $end - $start);

        $labels = array_values(HrReportController::REPORTS);
        $this->assertCount(8, $labels);
        $this->assertSame(8, substr_count($nav, 'aria-current') + substr_count($nav, 'bg-slate-100'), 'Every report view needs a switcher entry.');

        $cursor = -1;
        foreach ($labels as $label) {
            $position = strpos($nav, $label);
            $this->assertNotFalse($position, "The switcher must list [{$label}].");
            $this->assertGreaterThan($cursor, $position, "[{$label}] is out of order.");
            $cursor = $position;
        }
        $this->assertSame('Employee / Staff Directory', $labels[0]);
        $this->assertSame('Employee Document / Compliance', $labels[7]);

        // The sidebar entry sits in the HR / Staff Management group and is gated
        // by the report permission (the group keeps its existing seven screens).
        $dashboard = $this->asCollege($college, $reporter)->get(route('dashboard'))->assertOk()->getContent();
        $this->assertSame(1, substr_count($this->hrGroup($dashboard), 'HR Reports'));

        $operator = $this->makeUserWithPermissions($college, ['faculties.view']);
        $other = $this->asCollege($college, $operator)->get(route('dashboard'))->assertOk()->getContent();
        $this->assertStringNotContainsString('HR Reports', $this->hrGroup($other));
    }

    /* ------------------------------------------------------------------ *
     * Filters
     * ------------------------------------------------------------------ */

    public function test_empty_and_invalid_filters_are_handled_gracefully(): void
    {
        $college = $this->makeCollege('HRFILTER');
        $this->reporter($college);

        // An empty college lists every report with its own empty message.
        $empties = [
            'directory' => 'No staff match these filters.',
            'department' => 'No departments match these filters.',
            'designation' => 'No designations match these filters.',
            'attendance' => 'No attendance records match these filters.',
            'leave' => 'No leave requests match these filters.',
            'leave_balance' => 'No employees match these filters.',
            'payroll' => 'No payroll runs match these filters.',
            'documents' => 'No employee documents match these filters.',
        ];
        foreach ($empties as $report => $message) {
            $this->get(route('hr-reports.index', ['report' => $report]))
                ->assertOk()
                ->assertSee($message);
        }

        // Unknown vocabulary values are rejected, not silently applied.
        $this->get(route('hr-reports.index', ['report' => 'attendance', 'status' => 'nonsense']))
            ->assertSessionHasErrors('status');
        $this->get(route('hr-reports.index', ['report' => 'documents', 'state' => 'nonsense']))
            ->assertSessionHasErrors('state');
        $this->get(route('hr-reports.index', ['report' => 'directory', 'employment_type' => 'nonsense']))
            ->assertSessionHasErrors('employment_type');
        $this->get(route('hr-reports.index', ['report' => 'attendance', 'from' => 'yesterday']))
            ->assertSessionHasErrors('from');
        $this->get(route('hr-reports.index', ['report' => 'payroll', 'from' => '2026-09-01']))
            ->assertSessionHasErrors('from');
        $this->get(route('hr-reports.index', ['report' => 'attendance', 'from' => '2026-09-10', 'to' => '2026-09-01']))
            ->assertSessionHasErrors('to');

        // An unknown report falls back to the first view.
        $this->get(route('hr-reports.index', ['report' => 'does-not-exist']))->assertOk()->assertSee('Employee / Staff Directory');

        // Filters that do not belong to the selected report are ignored, not judged.
        $world = $this->world($college, 'IGN');
        $this->get(route('hr-reports.index', [
            'report' => 'directory',
            'leave_type_id' => 999, 'state' => 'expired', 'salary_structure_id' => 999,
        ]))->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 3);
        // A date range that the report does not use is ignored when well formed…
        $this->get(route('hr-reports.index', ['report' => 'department', 'from' => '2026-09-01', 'to' => '2026-09-30']))
            ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 2);
        // …and rejected when malformed, because the parameter is still a date.
        $this->get(route('hr-reports.index', ['report' => 'department', 'from' => 'not-a-date']))
            ->assertSessionHasErrors('from');
        $this->assertNotEmpty($world);
    }

    public function test_every_report_is_tenant_scoped_even_with_foreign_filter_ids(): void
    {
        $collegeA = $this->makeCollege('HRTENA');
        $collegeB = $this->makeCollege('HRTENB');
        $worldA = $this->world($collegeA, 'A');
        $worldB = $this->world($collegeB, 'B');
        $this->reporter($collegeA);
        $this->reporter($collegeB);

        // The dropdowns only ever offer the active college's own rows.
        $response = $this->get(route('hr-reports.index', ['report' => 'leave']))->assertOk();
        $ownIds = fn ($rows) => collect($rows)->map(fn ($row) => is_object($row) ? $row->id : $row)->sort()->values()->all();
        $this->assertSame(
            $ownIds([$worldA['employee']->id, $worldA['second']->id, $worldA['inactive']->id]),
            $ownIds($response->viewData('employees'))
        );
        $this->assertSame(
            $ownIds([$worldA['leaveType']->id, $worldA['sick']->id, $worldA['archived']->id]),
            $ownIds($response->viewData('leaveTypes'))
        );
        $this->assertSame($ownIds([$worldA['department']->id, $worldA['idle']->id]), $ownIds($response->viewData('departments')));

        // Every foreign id finds nothing: no rows, no totals, no leakage.
        $foreignFilters = [
            ['report' => 'directory', 'department_id' => $worldB['department']->id],
            ['report' => 'directory', 'designation_id' => $worldB['designation']->id],
            ['report' => 'directory', 'search' => 'REP-B-001'],
            ['report' => 'department', 'search' => 'Department B'],
            ['report' => 'designation', 'search' => 'Professor B'],
            ['report' => 'attendance', 'faculty_id' => $worldB['employee']->id],
            ['report' => 'attendance', 'department_id' => $worldB['department']->id],
            ['report' => 'leave', 'faculty_id' => $worldB['employee']->id],
            ['report' => 'leave', 'leave_type_id' => $worldB['leaveType']->id],
            ['report' => 'leave_balance', 'faculty_id' => $worldB['employee']->id],
            ['report' => 'payroll', 'salary_structure_id' => $worldB['structure']->id],
            ['report' => 'payroll', 'faculty_id' => $worldB['employee']->id],
            ['report' => 'documents', 'faculty_id' => $worldB['employee']->id],
            ['report' => 'documents', 'document_type' => 'Foreign B'],
        ];

        foreach ($foreignFilters as $filters) {
            $response = $this->get(route('hr-reports.index', $filters))->assertOk();
            $this->assertSame(0, $response->viewData('rows')->total(), 'Foreign filter id leaked rows: '.json_encode($filters));
            $this->assertSame(0, (int) ($response->viewData('totals')['employees'] ?? 0), 'Foreign filter id leaked totals: '.json_encode($filters));
        }

        // The other college's records never appear in an unfiltered listing either.
        $html = $this->get(route('hr-reports.index', ['report' => 'payroll']))->assertOk()->getContent();
        $this->assertStringContainsString('REP-A-001', $html);
        $this->assertStringNotContainsString('REP-B-001', $html);
    }

    public function test_filters_read_the_existing_hr_relationships(): void
    {
        $college = $this->makeCollege('HRFILT2');
        $world = $this->world($college, 'FLT');
        $this->reporter($college);
        $employee = $world['employee'];
        $other = $world['second'];

        // Directory: employee search, department, designation, employment type, status.
        $this->get(route('hr-reports.index', ['report' => 'directory', 'search' => 'REP-FLT-001']))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1);
        $this->get(route('hr-reports.index', ['report' => 'directory', 'search' => 'Alpha']))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1);
        $this->get(route('hr-reports.index', ['report' => 'directory', 'department_id' => $world['department']->id]))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 2);
        $this->get(route('hr-reports.index', ['report' => 'directory', 'designation_id' => $world['designation']->id]))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1);
        $this->get(route('hr-reports.index', ['report' => 'directory', 'employment_type' => 'contract']))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1);
        $this->get(route('hr-reports.index', ['report' => 'directory', 'status' => 'inactive']))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1);

        // Headcounts: department and designation status filters.
        $this->get(route('hr-reports.index', ['report' => 'department', 'search' => 'Department FLT']))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1);
        $this->get(route('hr-reports.index', ['report' => 'department', 'status' => 'inactive']))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1);
        $this->get(route('hr-reports.index', ['report' => 'designation', 'status' => 'inactive']))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1);

        // Attendance: employee, department, status and date range.
        $this->get(route('hr-reports.index', ['report' => 'attendance', 'faculty_id' => $employee->id]))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 3);
        $this->get(route('hr-reports.index', ['report' => 'attendance', 'department_id' => $world['department']->id]))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 4);
        $this->get(route('hr-reports.index', ['report' => 'attendance', 'status' => 'present']))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 2);
        $this->get(route('hr-reports.index', ['report' => 'attendance', 'from' => '2026-09-22', 'to' => '2026-09-22']))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 2);
        $html = $this->get(route('hr-reports.index', [
            'report' => 'attendance', 'faculty_id' => $employee->id, 'from' => '2026-09-22', 'to' => '2026-09-22',
        ]))->assertOk()->getContent();
        $this->assertStringContainsString('>Present</td>', $html);
        $this->assertStringNotContainsString('>Late</td>', $html);

        // Leave: leave type, status, employee and the overlap date rule.
        $this->get(route('hr-reports.index', ['report' => 'leave', 'leave_type_id' => $world['leaveType']->id]))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 2);
        $this->get(route('hr-reports.index', ['report' => 'leave', 'status' => 'approved']))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1);
        $this->get(route('hr-reports.index', ['report' => 'leave', 'from' => '2026-09-02', 'to' => '2026-09-02']))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1, 'A request that overlaps the period must be listed.');
        $this->get(route('hr-reports.index', ['report' => 'leave', 'from' => '2026-08-01', 'to' => '2026-08-02']))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 0);

        // Leave balance: employee, department and a period.
        $this->get(route('hr-reports.index', ['report' => 'leave_balance', 'faculty_id' => $employee->id]))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1);
        $this->get(route('hr-reports.index', ['report' => 'leave_balance', 'department_id' => $world['department']->id]))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 2);
        $this->get(route('hr-reports.index', ['report' => 'leave_balance', 'from' => '2026-09-01', 'to' => '2026-09-30']))
            ->assertViewHas('totals', fn ($totals) => $totals['approved_days'] === 3.0 && $totals['pending_days'] === 2.0);

        // Payroll: employee, structure, status and pay period range.
        $this->get(route('hr-reports.index', ['report' => 'payroll', 'faculty_id' => $employee->id]))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 2);
        $this->get(route('hr-reports.index', ['report' => 'payroll', 'salary_structure_id' => $world['structure']->id]))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 3);
        $this->get(route('hr-reports.index', ['report' => 'payroll', 'status' => 'cancelled']))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1);
        $this->get(route('hr-reports.index', ['report' => 'payroll', 'from' => '2026-09', 'to' => '2026-09']))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1);

        // Documents: employee, department, type and the derived compliance state.
        $this->get(route('hr-reports.index', ['report' => 'documents', 'faculty_id' => $other->id]))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 2);
        $this->get(route('hr-reports.index', ['report' => 'documents', 'document_type' => 'Certificate']))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1);
        $this->get(route('hr-reports.index', ['report' => 'documents', 'state' => 'expired']))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1);
        $this->get(route('hr-reports.index', ['report' => 'documents', 'state' => 'expiring']))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1);
        $this->get(route('hr-reports.index', ['report' => 'documents', 'state' => 'valid']))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1);
        $this->get(route('hr-reports.index', ['report' => 'documents', 'state' => 'none']))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1);
    }

    /* ------------------------------------------------------------------ *
     * Figures
     * ------------------------------------------------------------------ */

    public function test_report_figures_reconcile_with_the_existing_records(): void
    {
        $college = $this->makeCollege('HRFIG');
        $world = $this->world($college, 'FIG');
        $this->reporter($college);

        // Directory: every staff member, active and inactive.
        $this->get(route('hr-reports.index', ['report' => 'directory']))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 3)
            ->assertViewHas('totals', fn ($totals) => $totals['employees'] === 3
                && $totals['active'] === 2
                && $totals['inactive'] === 1
                && $totals['departments'] === 1);

        // Department-wise headcount, and staff held by no department.
        $this->get(route('hr-reports.index', ['report' => 'department']))
            ->assertViewHas('totals', fn ($totals) => $totals['departments'] === 2
                && $totals['staff'] === 2
                && $totals['unassigned'] === 1)
            ->assertViewHas('rows', function ($rows) {
                $department = collect($rows->items())->firstWhere('code', 'DEPT-FIG');

                return $department !== null
                    && (int) $department->employees_count === 2
                    && (int) $department->active_employees_count === 2;
            });

        // Designation-wise headcount.
        $this->get(route('hr-reports.index', ['report' => 'designation']))
            ->assertViewHas('totals', fn ($totals) => $totals['designations'] === 2
                && $totals['staff'] === 2
                && $totals['unassigned'] === 1);

        // Attendance counts and the rate over recorded working days.
        $this->get(route('hr-reports.index', ['report' => 'attendance']))
            ->assertViewHas('totals', fn ($totals) => $totals['days'] === 4
                && $totals['employees'] === 2
                && $totals['present'] === 2
                && $totals['late'] === 1
                && $totals['holiday'] === 1
                && $totals['absent'] === 0
                && $totals['attendance_rate'] === 100.0);

        // Leave: requests and days per status, from the requests' own figures.
        $this->get(route('hr-reports.index', ['report' => 'leave']))
            ->assertViewHas('totals', fn ($totals) => $totals['requests'] === 3
                && $totals['days'] === 6.0
                && $totals['approved'] === 1 && $totals['approved_days'] === 3.0
                && $totals['pending'] === 1 && $totals['pending_days'] === 2.0
                && $totals['rejected'] === 1 && $totals['rejected_days'] === 1.0
                && $totals['cancelled'] === 0);

        // Leave balance: active leave types' entitlement against recorded days.
        $this->get(route('hr-reports.index', ['report' => 'leave_balance']))
            ->assertViewHas('rows', function ($rows) use ($world) {
                $employee = collect($rows->items())->firstWhere('id', $world['employee']->id);

                return $employee !== null
                    && (float) $employee->entitlement_days === 20.0
                    && (float) $employee->availed_days === 3.0
                    && (float) $employee->pending_days === 2.0
                    && (float) $employee->balance_days === 17.0;
            })
            ->assertViewHas('totals', fn ($totals) => $totals['employees'] === 3
                && $totals['entitlement'] === 60.0
                && $totals['approved_days'] === 3.0
                && $totals['pending_days'] === 2.0
                && $totals['balance_days'] === 57.0);

        // Payroll: the amounts the payrolls themselves recorded.
        $this->get(route('hr-reports.index', ['report' => 'payroll']))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 3)
            ->assertViewHas('totals', fn ($totals) => $totals['payrolls'] === 3
                && $totals['employees'] === 2
                && $totals['basic'] === 2800.0
                && $totals['gross'] === 3900.0
                && $totals['deductions'] === 400.0
                && $totals['net'] === 3500.0
                && $totals['processed'] === 2);

        // Documents: metadata plus the compliance state implied by the dates.
        $this->get(route('hr-reports.index', ['report' => 'documents']))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 4)
            ->assertViewHas('totals', fn ($totals) => $totals['documents'] === 4
                && $totals['employees'] === 2
                && $totals['expired'] === 1
                && $totals['expiring'] === 1
                && $totals['valid'] === 1
                && $totals['none'] === 1)
            ->assertViewHas('rows', function ($rows) {
                $states = collect($rows->items())->pluck('compliance_state')->sort()->values()->all();

                return $states === ['expired', 'expiring', 'none', 'valid'];
            });

        // The document file path is never rendered anywhere on the screen.
        $html = $this->get(route('hr-reports.index', ['report' => 'documents']))->assertOk()->getContent();
        $this->assertStringNotContainsString('employee-documents/', $html);
    }

    /* ------------------------------------------------------------------ *
     * Soft deletes and inactive rows
     * ------------------------------------------------------------------ */

    public function test_soft_deleted_and_inactive_records_are_respected(): void
    {
        $college = $this->makeCollege('HRSOFT');
        $world = $this->world($college, 'SFT');
        $this->reporter($college);

        // A soft-deleted employee leaves every report.
        $retired = $this->employee($college, 'REP-SFT-900', 'Retired', ['last_name' => 'Staffer']);
        $this->attendance($college, $retired, '2026-09-22', 'present');
        $this->document($college, $retired, ['document_name' => 'Old Paper', 'document_type' => 'Legacy']);
        $retired->delete();

        $this->get(route('hr-reports.index', ['report' => 'directory']))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 3);
        $this->get(route('hr-reports.index', ['report' => 'attendance']))
            ->assertViewHas('totals', fn ($totals) => $totals['days'] === 4)
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 4);
        $this->get(route('hr-reports.index', ['report' => 'documents']))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 4)
            ->assertDontSee('Old Paper');
        $this->get(route('hr-reports.index', ['report' => 'leave_balance']))
            ->assertViewHas('totals', fn ($totals) => $totals['employees'] === 3);

        // A soft-deleted attendance row, document and leave request disappear.
        $attendance = StaffAttendance::query()->where('faculty_id', $world['employee']->id)->firstOrFail();
        $attendance->delete();
        $this->get(route('hr-reports.index', ['report' => 'attendance']))
            ->assertViewHas('totals', fn ($totals) => $totals['days'] === 3);

        $document = EmployeeDocument::query()->where('document_name', 'Degree SFT')->firstOrFail();
        $document->delete();
        $this->get(route('hr-reports.index', ['report' => 'documents']))
            ->assertViewHas('totals', fn ($totals) => $totals['documents'] === 3 && $totals['expired'] === 0);

        $leave = LeaveRequest::query()->where('status', 'approved')->firstOrFail();
        $leave->delete();
        $this->get(route('hr-reports.index', ['report' => 'leave']))
            ->assertViewHas('totals', fn ($totals) => $totals['requests'] === 2 && $totals['approved'] === 0);

        // An inactive leave type keeps its records but holds no entitlement.
        $world['leaveType']->update(['status' => 'inactive']);
        $this->get(route('hr-reports.index', ['report' => 'leave_balance']))
            ->assertViewHas('rows', fn ($rows) => collect($rows->items())->every(fn ($row) => (float) $row->entitlement_days === 8.0));

        // Inactive staff stay in the directory until they are excluded by filter.
        $this->get(route('hr-reports.index', ['report' => 'directory', 'status' => 'active']))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 2);
    }

    /* ------------------------------------------------------------------ *
     * Pagination
     * ------------------------------------------------------------------ */

    public function test_lists_paginate_deterministically_and_keep_filters(): void
    {
        $college = $this->makeCollege('HRPAGE');
        $this->reporter($college);

        $ids = [];
        for ($i = 1; $i <= 21; $i++) {
            $ids[] = $this->employee($college, sprintf('REP-PG-%03d', $i), 'Paged', [
                'last_name' => sprintf('Staff %02d', $i),
                'status' => 'active',
            ])->id;
        }

        $query = ['report' => 'directory', 'search' => 'Paged', 'status' => 'active'];
        $page = $this->get(route('hr-reports.index', $query))->assertOk();
        $rows = $page->viewData('rows');

        $this->assertSame(21, $rows->total());
        $this->assertSame(20, $rows->perPage(), 'Reports paginate 20 rows per page.');
        $this->assertSame(array_slice($ids, 0, 20), $rows->getCollection()->pluck('id')->all(), 'Ordered by last name with the id as tiebreak.');
        $this->assertStringContainsString('report=directory', $rows->nextPageUrl());
        $this->assertStringContainsString('search=Paged', $rows->nextPageUrl());
        $this->assertStringContainsString('status=active', $rows->nextPageUrl());
        $page->assertSee('Showing 1–20 of 21');

        $this->get(route('hr-reports.index', [...$query, 'page' => 2]))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 21
                && $rows->getCollection()->pluck('id')->all() === [$ids[20]]);

        // The grouped attendance report paginates the same way.
        $world = $this->world($college, 'PG2');
        foreach (StaffAttendance::STATUSES as $index => $status) {
            for ($i = 0; $i < 4; $i++) {
                $this->attendance($college, $world['employee'], sprintf('2026-10-%02d', ($index * 4) + $i + 1), $status);
            }
        }
        $this->get(route('hr-reports.index', ['report' => 'attendance', 'faculty_id' => $world['employee']->id]))
            ->assertViewHas('rows', fn ($rows) => $rows->perPage() === 20 && $rows->lastPage() === 1);

        $this->get(route('hr-reports.index', ['report' => 'attendance']))
            ->assertViewHas('rows', fn ($rows) => $rows->perPage() === 20
                && $rows->count() === min(20, $rows->total())
                && $rows->total() === 6, 'Grouped attendance rows: five statuses for one employee and one for the other.');
    }

    /* ------------------------------------------------------------------ *
     * Read-only guarantee
     * ------------------------------------------------------------------ */

    public function test_report_routes_never_write_and_expose_only_get(): void
    {
        $college = $this->makeCollege('HRREAD');
        $this->world($college, 'RD');
        $this->reporter($college);

        $before = $this->snapshot();

        foreach (array_keys(HrReportController::REPORTS) as $report) {
            $this->get(route('hr-reports.index', ['report' => $report]))->assertOk();
        }
        $this->assertSame($before, $this->snapshot(), 'Reading the reports must not change a single record.');

        // The module owns exactly one route, and it is a read.
        $methods = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'hr-reports'))
            ->flatMap(fn ($route) => $route->methods())->unique()->sort()->values()->all();
        $this->assertSame(['GET', 'HEAD'], $methods);

        // No write verb reaches the report screen, and no record screen exists.
        $this->post(route('hr-reports.index'))->assertStatus(405);
        $this->put(route('hr-reports.index'))->assertStatus(405);
        $this->patch(route('hr-reports.index'))->assertStatus(405);
        $this->delete(route('hr-reports.index'))->assertStatus(405);
        $this->get('/hr-reports/create')->assertNotFound();
        $this->get('/hr-reports/1/edit')->assertNotFound();

        // The rendered screens offer no write affordance.
        foreach (array_keys(HrReportController::REPORTS) as $report) {
            $this->get(route('hr-reports.index', ['report' => $report]))
                ->assertDontSee('Create')
                ->assertDontSee('Delete')
                ->assertDontSee('Export');
        }
    }

    /* ------------------------------------------------------------------ *
     * Query-count / N+1 protection
     * ------------------------------------------------------------------ */

    public function test_query_count_does_not_grow_with_rows_on_any_report(): void
    {
        $college = $this->makeCollege('HRNPLUS');
        $world = $this->world($college, 'NPL');
        $this->reporter($college);

        $logs = fn (): array => collect(array_keys(HrReportController::REPORTS))
            ->mapWithKeys(fn (string $report) => [$report => $this->queryLogFor(route('hr-reports.index', ['report' => $report]))])
            ->all();
        $small = $logs();

        $this->growWorld($college, $world, 21);

        $grown = $logs();

        // Growing the tables may make Laravel SKIP an eager-load query whose
        // foreign keys are all null on the page, but it must never ADD one:
        // every report reads its page with a fixed number of queries.
        foreach (array_keys(HrReportController::REPORTS) as $report) {
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

        // One row and a full page cost the same number of queries.
        foreach (['directory', 'attendance', 'leave', 'payroll', 'documents'] as $report) {
            $one = $this->queryLogFor(route('hr-reports.index', ['report' => $report, 'search' => 'Grown 01']));
            $full = $this->queryLogFor(route('hr-reports.index', ['report' => $report, 'search' => 'Grown']));

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

    private function hrGroup(string $html): string
    {
        $start = strpos($html, '>HR / Staff Management</div>');
        $this->assertNotFalse($start, 'The HR / Staff Management group must exist.');

        $end = strpos($html, 'uppercase tracking-widest', $start + 1);

        return substr($html, $start, $end === false ? null : $end - $start);
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
            'documents_updated' => DB::table('employee_documents')->max('updated_at'),
            'audit_logs' => AuditLog::count(),
        ];
    }

    private function employee(College $college, string $code, string $first, array $extra = []): Faculty
    {
        return Faculty::create(array_merge([
            'college_id' => $college->id,
            'employee_code' => $code,
            'first_name' => $first,
            'last_name' => 'Member',
            'email' => strtolower($code).'@example.test',
            'status' => 'active',
            'joining_date' => '2026-07-01',
        ], $extra));
    }

    private function attendance(College $college, Faculty $employee, string $date, string $status): StaffAttendance
    {
        return StaffAttendance::create([
            'college_id' => $college->id,
            'faculty_id' => $employee->id,
            'attendance_date' => $date,
            'status' => $status,
        ]);
    }

    private function document(College $college, Faculty $employee, array $attributes): EmployeeDocument
    {
        return EmployeeDocument::create(array_merge([
            'college_id' => $college->id,
            'faculty_id' => $employee->id,
            'document_name' => 'Document',
            'document_type' => 'General',
            'file_path' => 'employee-documents/'.$college->id.'/proof.pdf',
            'original_filename' => 'proof.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1024,
        ], $attributes));
    }

    /**
     * One college with a complete HR world: two active staff and one inactive,
     * two departments (one inactive), two designations (one inactive), three
     * leave types (one inactive), four attendance records, three leave requests,
     * three payroll runs and four documents in every compliance state.
     *
     * @return array<string, mixed>
     */
    private function world(College $college, string $prefix): array
    {
        $department = Department::create([
            'college_id' => $college->id, 'name' => 'Department '.$prefix, 'code' => 'DEPT-'.$prefix, 'status' => 'active',
        ]);
        $idle = Department::create([
            'college_id' => $college->id, 'name' => 'Idle '.$prefix, 'code' => 'IDLE-'.$prefix, 'status' => 'inactive',
        ]);
        $designation = Designation::create([
            'college_id' => $college->id, 'name' => 'Professor '.$prefix, 'code' => 'PROF-'.$prefix, 'status' => 'active',
        ]);
        $clerk = Designation::create([
            'college_id' => $college->id, 'name' => 'Clerk '.$prefix, 'code' => 'CLRK-'.$prefix, 'status' => 'inactive',
        ]);

        $employee = $this->employee($college, 'REP-'.$prefix.'-001', 'Alpha', [
            'last_name' => 'Alpha', 'department_id' => $department->id, 'designation_id' => $designation->id,
            'employment_type' => 'permanent',
        ]);
        $second = $this->employee($college, 'REP-'.$prefix.'-002', 'Beta', [
            'last_name' => 'Beta', 'department_id' => $department->id, 'designation_id' => $clerk->id,
            'employment_type' => 'part_time',
        ]);
        $inactive = $this->employee($college, 'REP-'.$prefix.'-003', 'Gamma', [
            'last_name' => 'Gamma', 'employment_type' => 'contract', 'status' => 'inactive',
        ]);

        $leaveType = LeaveType::create([
            'college_id' => $college->id, 'name' => 'Casual '.$prefix, 'code' => 'CL-'.$prefix,
            'max_days_per_year' => 12, 'status' => 'active',
        ]);
        $sick = LeaveType::create([
            'college_id' => $college->id, 'name' => 'Sick '.$prefix, 'code' => 'SK-'.$prefix,
            'max_days_per_year' => 8, 'status' => 'active',
        ]);
        $archived = LeaveType::create([
            'college_id' => $college->id, 'name' => 'Archived '.$prefix, 'code' => 'AR-'.$prefix,
            'max_days_per_year' => 5, 'status' => 'inactive',
        ]);

        $this->attendance($college, $employee, '2026-09-22', 'present');
        $this->attendance($college, $employee, '2026-09-23', 'late');
        $this->attendance($college, $employee, '2026-09-24', 'holiday');
        $this->attendance($college, $second, '2026-09-22', 'present');

        LeaveRequest::create([
            'college_id' => $college->id, 'faculty_id' => $employee->id, 'leave_type_id' => $leaveType->id,
            'from_date' => '2026-09-01', 'to_date' => '2026-09-03', 'days' => 3,
            'reason' => 'Family', 'status' => 'approved',
        ]);
        LeaveRequest::create([
            'college_id' => $college->id, 'faculty_id' => $employee->id, 'leave_type_id' => $leaveType->id,
            'from_date' => '2026-09-10', 'to_date' => '2026-09-11', 'days' => 2,
            'reason' => 'Personal', 'status' => 'pending',
        ]);
        LeaveRequest::create([
            'college_id' => $college->id, 'faculty_id' => $second->id, 'leave_type_id' => $sick->id,
            'from_date' => '2026-09-05', 'to_date' => '2026-09-05', 'days' => 1,
            'reason' => 'Unwell', 'status' => 'rejected',
        ]);

        $structure = SalaryStructure::create([
            'college_id' => $college->id, 'name' => 'Structure '.$prefix, 'code' => 'STR-'.$prefix,
            'effective_from' => '2026-04-01', 'status' => 'active',
        ]);
        $this->payrollRun($college, $employee, $structure, '2026-08-01', 1000, 1500, 150, 'processed');
        $this->payrollRun($college, $employee, $structure, '2026-09-01', 1000, 1500, 150, 'processed');
        $this->payrollRun($college, $second, $structure, '2026-08-01', 800, 900, 100, 'cancelled');

        $today = Carbon::now()->startOfDay();
        $this->document($college, $employee, [
            'document_name' => 'Degree '.$prefix, 'document_type' => 'Certificate',
            'issue_date' => '2020-01-01', 'expiry_date' => $today->copy()->subMonth()->toDateString(),
        ]);
        $this->document($college, $employee, [
            'document_name' => 'Identity '.$prefix, 'document_type' => 'Identity',
            'issue_date' => '2025-01-01', 'expiry_date' => $today->copy()->addDays(10)->toDateString(),
        ]);
        $this->document($college, $second, [
            'document_name' => 'Contract '.$prefix, 'document_type' => 'Contract',
            'issue_date' => '2026-01-01', 'expiry_date' => $today->copy()->addDays(200)->toDateString(),
        ]);
        $this->document($college, $second, [
            'document_name' => 'Note '.$prefix, 'document_type' => 'General', 'expiry_date' => null,
        ]);

        return compact(
            'department', 'idle', 'designation', 'clerk', 'employee', 'second', 'inactive',
            'leaveType', 'sick', 'archived', 'structure',
        );
    }

    private function payrollRun(
        College $college,
        Faculty $employee,
        SalaryStructure $structure,
        string $period,
        float $basic,
        float $gross,
        float $deductions,
        string $status,
    ): Payroll {
        return Payroll::create([
            'college_id' => $college->id,
            'faculty_id' => $employee->id,
            'salary_structure_id' => $structure->id,
            'pay_period' => $period,
            'basic_amount' => $basic,
            'gross_amount' => $gross,
            'total_deductions' => $deductions,
            'net_amount' => $gross - $deductions,
            'status' => $status,
        ]);
    }

    /**
     * Grow every dataset the reports read past one page, so a query count that
     * depended on the number of rows would move.
     *
     * @param  array<string, mixed>  $world
     */
    private function growWorld(College $college, array $world, int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $employee = $this->employee($college, sprintf('REP-%s-%03d', 'GR', $i), sprintf('Grown %02d', $i), [
                'last_name' => sprintf('Grown %02d', $i),
                'department_id' => $world['department']->id,
                'designation_id' => $world['designation']->id,
            ]);

            $this->attendance($college, $employee, sprintf('2026-11-%02d', ($i % 28) + 1), 'present');
            $this->document($college, $employee, [
                'document_name' => 'Grown Doc '.$i, 'document_type' => 'Certificate',
                'expiry_date' => Carbon::now()->addDays(60)->toDateString(),
            ]);
            LeaveRequest::create([
                'college_id' => $college->id, 'faculty_id' => $employee->id, 'leave_type_id' => $world['leaveType']->id,
                'from_date' => '2026-10-01', 'to_date' => '2026-10-02', 'days' => 2,
                'reason' => 'Grown '.$i, 'status' => 'approved',
            ]);
            $this->payrollRun($college, $employee, $world['structure'], sprintf('2026-%02d-01', ($i % 12) + 1), 100, 150, 15, 'processed');
        }
    }
}
