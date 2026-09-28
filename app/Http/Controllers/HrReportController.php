<?php

namespace App\Http\Controllers;

use App\Domain\HR\Services\HrReportService;
use App\Models\AdmissionDocumentType;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Faculty;
use App\Models\HrReport;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Payroll;
use App\Models\SalaryStructure;
use App\Models\StaffAttendance;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * HR Reports — a READ-ONLY reporting layer over the existing HR / Staff module
 * (Staff / Employee, Staff Departments, Designations, Employee Documents, Staff
 * Attendance, Leave Management and Staff Salary / Payroll).
 *
 * Nothing is persisted here: no report tables, no snapshots and no second copy
 * of any HR fact — every row and every figure is read live from the operational
 * models through HrReportService, always tenant-scoped by CollegeScope, so a
 * department / designation / employee id from another college can only produce
 * an empty report.
 *
 * There are no POST/PUT/PATCH/DELETE routes for this module, so no screen can
 * create, edit or remove an HR record from here.
 */
class HrReportController extends Controller
{
    /** The eight reports the screen can render, in navigation order. */
    public const REPORTS = [
        'employees' => 'Employee / Staff List',
        'department' => 'Department-wise Staff Report',
        'designation' => 'Designation-wise Staff Report',
        'documents' => 'Employee Documents Report',
        'attendance' => 'Staff Attendance Report',
        'leave' => 'Leave Report',
        'payroll' => 'Payroll / Salary Report',
        'summary' => 'HR Summary',
    ];

    /**
     * Filters that apply to each report; any other query parameter is ignored
     * and never enforced. Only the filters relevant to the selected report are
     * offered, so no report carries a filter it cannot use.
     */
    public const FILTERS = [
        // Staff reports: the `status` filter is the status of the staff record.
        'employees' => ['search', 'faculty_id', 'department_id', 'designation_id', 'status', 'employment_type', 'from', 'to'],
        'department' => ['search', 'faculty_id', 'department_id', 'designation_id', 'status', 'employment_type', 'from', 'to'],
        'designation' => ['search', 'faculty_id', 'department_id', 'designation_id', 'status', 'employment_type', 'from', 'to'],
        // Record reports: `staff_status` narrows the staff dimension, `status`
        // is the operational status of the listed record.
        'documents' => ['search', 'faculty_id', 'department_id', 'designation_id', 'staff_status', 'document_type_id', 'document_status', 'from', 'to'],
        'attendance' => ['search', 'faculty_id', 'department_id', 'designation_id', 'staff_status', 'status', 'from', 'to'],
        'leave' => ['search', 'faculty_id', 'department_id', 'designation_id', 'staff_status', 'leave_type_id', 'status', 'from', 'to'],
        'payroll' => ['search', 'faculty_id', 'department_id', 'designation_id', 'staff_status', 'salary_structure_id', 'status', 'from', 'to'],
        // The summary has no date window: it aggregates all dates for the
        // selected staff scope.
        'summary' => ['faculty_id', 'department_id', 'designation_id', 'staff_status'],
    ];

    /** Reports whose `status` filter is the status of the staff record. */
    private const STAFF_STATUS_REPORTS = ['employees', 'department', 'designation'];

    /** Date-range meaning per report (for the labels in the filter form). */
    public const DATE_LABELS = [
        'employees' => 'Joining date',
        'department' => 'Joining date',
        'designation' => 'Joining date',
        'documents' => 'Issue date',
        'attendance' => 'Attendance date',
        'leave' => 'Leave date',
        'payroll' => 'Pay period',
    ];

    private const KEYS = [
        'search', 'faculty_id', 'department_id', 'designation_id', 'employment_type', 'staff_status',
        'document_type_id', 'document_status', 'leave_type_id', 'salary_structure_id', 'status', 'from', 'to',
    ];

    private const INTEGER_KEYS = [
        'faculty_id', 'department_id', 'designation_id', 'document_type_id', 'leave_type_id', 'salary_structure_id',
    ];

    private const STRING_KEYS = ['employment_type', 'staff_status', 'document_status', 'status'];

    public function index(Request $request, HrReportService $reports): View
    {
        $this->authorize('viewAny', HrReport::class);

        $requested = $request->query('report');
        $report = is_string($requested) && isset(self::REPORTS[$requested]) ? $requested : 'employees';
        $filters = $this->filters($request, $report);

        $data = match ($report) {
            'department' => $reports->departmentWise($filters),
            'designation' => $reports->designationWise($filters),
            'documents' => $reports->documents($filters),
            'attendance' => $reports->attendance($filters),
            'leave' => $reports->leave($filters),
            'payroll' => $reports->payroll($filters),
            'summary' => $reports->summary($filters),
            default => $reports->employees($filters),
        };

        return view('hr_reports.index', array_merge($data, $this->options($report), [
            'report' => $report,
            'reports' => self::REPORTS,
            'filters' => $filters,
            'visible' => self::FILTERS[$report],
            'statuses' => self::statuses($report),
            'statusLabel' => self::statusLabel($report),
            'dateLabel' => self::DATE_LABELS[$report] ?? 'Date',
            'searchLabel' => self::searchLabel($report),
            'searchPlaceholder' => self::searchPlaceholder($report),
            'employmentTypes' => Faculty::EMPLOYMENT_TYPES,
            'staffStatuses' => Faculty::STATUSES,
            'documentStatuses' => HrReportService::DOCUMENT_STATUSES,
        ]));
    }

    /**
     * The selectable statuses of the selected report's `status` filter
     * (empty = the report has no operational status filter).
     */
    public static function statuses(string $report): array
    {
        return match ($report) {
            'employees', 'department', 'designation' => Faculty::STATUSES,
            'attendance' => StaffAttendance::STATUSES,
            'leave' => LeaveRequest::STATUSES,
            'payroll' => Payroll::STATUSES,
            default => [],
        };
    }

    private static function statusLabel(string $report): string
    {
        return match ($report) {
            'employees', 'department', 'designation' => 'Staff status',
            'attendance' => 'Attendance status',
            'leave' => 'Leave status',
            'payroll' => 'Payroll status',
            default => 'Status',
        };
    }

    private static function searchLabel(string $report): string
    {
        return match ($report) {
            'department' => 'Search department',
            'designation' => 'Search designation',
            'documents' => 'Search employee or document',
            default => 'Search staff',
        };
    }

    private static function searchPlaceholder(string $report): string
    {
        return match ($report) {
            'department', 'designation' => 'Name or code',
            'documents' => 'Employee name / code or document name',
            default => 'Name, employee code, email or phone',
        };
    }

    /**
     * Validate and normalise the query string into the service's vocabulary.
     * Only the filters of the selected report take effect; the service always
     * receives the same keys.
     */
    private function filters(Request $request, string $report): array
    {
        $visible = self::FILTERS[$report];
        $uses = fn (string $key): bool => in_array($key, $visible, true);
        $statuses = self::statuses($report);

        $dateRules = $uses('from')
            ? ($report === 'payroll' ? ['date_format:Y-m'] : ['date_format:Y-m-d'])
            : ['string', 'max:20'];

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'faculty_id' => ['nullable', 'integer', 'min:1'],
            'department_id' => ['nullable', 'integer', 'min:1'],
            'designation_id' => ['nullable', 'integer', 'min:1'],
            'document_type_id' => ['nullable', 'integer', 'min:1'],
            'leave_type_id' => ['nullable', 'integer', 'min:1'],
            'salary_structure_id' => ['nullable', 'integer', 'min:1'],
            // A vocabulary is enforced only where the selected report actually
            // uses the filter; elsewhere the parameter is ignored, not judged.
            'employment_type' => ['nullable', $uses('employment_type') ? Rule::in(Faculty::EMPLOYMENT_TYPES) : 'string'],
            'staff_status' => ['nullable', $uses('staff_status') ? Rule::in(Faculty::STATUSES) : 'string'],
            'document_status' => ['nullable', $uses('document_status') ? Rule::in(HrReportService::DOCUMENT_STATUSES) : 'string'],
            'status' => ['nullable', $statuses === [] ? 'string' : Rule::in($statuses)],
            'from' => ['nullable', ...$dateRules],
            'to' => ['nullable', ...$dateRules, ...($uses('from') ? ['after_or_equal:from'] : [])],
        ]);

        $filters = array_fill_keys(self::KEYS, null);
        foreach ($visible as $key) {
            $filters[$key] = $validated[$key] ?? null;
        }
        $filters['search'] = trim((string) ($filters['search'] ?? ''));

        // The staff reports list staff rows, so their `status` filter is the
        // status of the staff record; the record reports keep `status` for the
        // listed record and use `staff_status` for the staff dimension.
        if (in_array($report, self::STAFF_STATUS_REPORTS, true)) {
            $filters['staff_status'] = $filters['status'];
            $filters['status'] = null;
        }

        foreach (self::INTEGER_KEYS as $key) {
            $filters[$key] = $filters[$key] === null ? null : (int) $filters[$key];
        }
        foreach (self::STRING_KEYS as $key) {
            $filters[$key] = filled($filters[$key]) ? trim((string) $filters[$key]) : null;
        }

        return $filters;
    }

    /** Tenant-scoped dropdown options, loaded only for the filters shown. */
    private function options(string $report): array
    {
        $visible = array_flip(self::FILTERS[$report]);
        $options = [];

        if (isset($visible['faculty_id'])) {
            $options['employees'] = Faculty::query()
                ->orderBy('first_name')->orderBy('last_name')->orderBy('id')
                ->get(['id', 'employee_code', 'first_name', 'middle_name', 'last_name']);
        }
        if (isset($visible['department_id'])) {
            $options['departments'] = Department::query()->orderBy('name')->orderBy('id')->get(['id', 'name', 'code']);
        }
        if (isset($visible['designation_id'])) {
            $options['designations'] = Designation::query()->orderBy('name')->orderBy('id')->get(['id', 'name', 'code']);
        }
        if (isset($visible['document_type_id'])) {
            $options['documentTypes'] = AdmissionDocumentType::query()
                ->orderBy('name')->orderBy('id')->get(['id', 'name', 'code']);
        }
        if (isset($visible['leave_type_id'])) {
            $options['leaveTypes'] = LeaveType::query()
                ->orderBy('name')->orderBy('id')->get(['id', 'name', 'code', 'status']);
        }
        if (isset($visible['salary_structure_id'])) {
            $options['salaryStructures'] = SalaryStructure::query()
                ->orderBy('name')->orderBy('id')->get(['id', 'name', 'code', 'status']);
        }

        return $options;
    }
}
