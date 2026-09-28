<?php

namespace App\Http\Controllers;

use App\Domain\HR\Services\HrReportService;
use App\Models\Department;
use App\Models\Designation;
use App\Models\EmployeeDocument;
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
 * HR Reports — a READ-ONLY reporting layer over the existing HR / Staff records
 * (employees, staff departments, designations, employee documents, staff
 * attendance, leave requests and leave types, payrolls).
 *
 * Nothing is persisted here: no report tables, no snapshots and no second copy
 * of any HR fact — every figure is read live from the existing models through
 * HrReportService, always tenant-scoped by CollegeScope.
 *
 * There are no POST/PUT/PATCH/DELETE routes for this module, so no screen can
 * create, edit or remove an HR record.
 */
class HrReportController extends Controller
{
    /** The reports the screen can render, in navigation order. */
    public const REPORTS = [
        'directory' => 'Employee / Staff Directory',
        'department' => 'Department-wise Staff',
        'designation' => 'Designation-wise Staff',
        'attendance' => 'Staff Attendance Report',
        'leave' => 'Leave Report',
        'leave_balance' => 'Leave Balance Report',
        'payroll' => 'Payroll / Salary Register',
        'documents' => 'Employee Document / Compliance',
    ];

    /** Filters that apply to each report; any other query parameter is ignored. */
    public const FILTERS = [
        'directory' => ['search', 'department_id', 'designation_id', 'employment_type', 'status'],
        'department' => ['search', 'status'],
        'designation' => ['search', 'status'],
        'attendance' => ['faculty_id', 'department_id', 'status', 'from', 'to'],
        'leave' => ['search', 'faculty_id', 'department_id', 'leave_type_id', 'status', 'from', 'to'],
        'leave_balance' => ['search', 'faculty_id', 'department_id', 'designation_id', 'status', 'from', 'to'],
        'payroll' => ['search', 'faculty_id', 'department_id', 'salary_structure_id', 'status', 'from', 'to'],
        'documents' => ['search', 'faculty_id', 'department_id', 'document_type', 'state', 'from', 'to'],
    ];

    /** Date-range meaning per report (for labels in the filter form). */
    public const DATE_LABELS = [
        'attendance' => 'Attendance date',
        'leave' => 'Leave dates',
        'leave_balance' => 'Leave period',
        'payroll' => 'Pay period',
        'documents' => 'Expiry date',
    ];

    /** Reports whose date range is entered as YEAR-MONTH (pay periods). */
    public const MONTH_REPORTS = ['payroll'];

    private const KEYS = [
        'search', 'faculty_id', 'department_id', 'designation_id', 'leave_type_id', 'salary_structure_id',
        'employment_type', 'status', 'state', 'document_type', 'from', 'to',
    ];

    private const INTEGER_KEYS = [
        'faculty_id', 'department_id', 'designation_id', 'leave_type_id', 'salary_structure_id',
    ];

    private const STRING_KEYS = ['employment_type', 'status', 'state', 'document_type'];

    public function index(Request $request, HrReportService $reports): View
    {
        $this->authorize('viewAny', HrReport::class);

        $requested = $request->query('report');
        $report = is_string($requested) && isset(self::REPORTS[$requested]) ? $requested : 'directory';
        $filters = $this->filters($request, $report);

        $data = match ($report) {
            'department' => $reports->department($filters),
            'designation' => $reports->designation($filters),
            'attendance' => $reports->attendance($filters),
            'leave' => $reports->leave($filters),
            'leave_balance' => $reports->leaveBalance($filters),
            'payroll' => $reports->payroll($filters),
            'documents' => $reports->documents($filters),
            default => $reports->directory($filters),
        };

        return view('hr_reports.index', array_merge($data, $this->options($report), [
            'report' => $report,
            'reports' => self::REPORTS,
            'filters' => $filters,
            'visible' => self::FILTERS[$report],
            'statuses' => self::statuses($report),
            'statusLabel' => self::statusLabel($report),
            'states' => HrReportService::DOCUMENT_STATES,
            'stateLabels' => self::stateLabels(),
            'dateLabel' => self::DATE_LABELS[$report] ?? 'Date',
            'monthRange' => in_array($report, self::MONTH_REPORTS, true),
            'searchLabel' => self::searchLabel($report),
            'searchPlaceholder' => self::searchPlaceholder($report),
        ]));
    }

    /**
     * The selectable statuses of the selected report (empty = no status filter).
     *
     * The Headcount, Directory and Leave Balance reports use the employee's own
     * status; the others the operational status of the record they list, or the
     * derived compliance vocabulary of the Documents report.
     */
    public static function statuses(string $report): array
    {
        return match ($report) {
            'directory', 'leave_balance' => Faculty::STATUSES,
            'department' => HrReportService::DEPARTMENT_STATUSES,
            'designation' => Designation::STATUSES,
            'attendance' => StaffAttendance::STATUSES,
            'leave' => LeaveRequest::STATUSES,
            'payroll' => Payroll::STATUSES,
            default => [],
        };
    }

    /** Human labels for the derived document compliance states. */
    public static function stateLabels(): array
    {
        return [
            'expired' => 'Expired',
            'expiring' => 'Expiring within '.HrReportService::EXPIRING_WINDOW_DAYS.' days',
            'valid' => 'Valid',
            'none' => 'No expiry date',
        ];
    }

    private static function statusLabel(string $report): string
    {
        return match ($report) {
            'directory', 'leave_balance' => 'Employee status',
            'department' => 'Department status',
            'designation' => 'Designation status',
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
            'leave', 'leave_balance', 'payroll' => 'Search employee',
            'documents' => 'Search document or employee',
            default => 'Search staff',
        };
    }

    private static function searchPlaceholder(string $report): string
    {
        return match ($report) {
            'department' => 'Department name or code',
            'designation' => 'Designation name or code',
            'leave', 'leave_balance', 'payroll' => 'Employee number or name',
            'documents' => 'Document name, employee number or name',
            default => 'Employee number, name or email',
        };
    }

    private function filters(Request $request, string $report): array
    {
        $statuses = self::statuses($report);
        $visible = self::FILTERS[$report];
        $uses = fn (string $key): bool => in_array($key, $visible, true);
        $dateFormat = in_array($report, self::MONTH_REPORTS, true) ? 'date_format:Y-m' : 'date_format:Y-m-d';

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'faculty_id' => ['nullable', 'integer', 'min:1'],
            'department_id' => ['nullable', 'integer', 'min:1'],
            'designation_id' => ['nullable', 'integer', 'min:1'],
            'leave_type_id' => ['nullable', 'integer', 'min:1'],
            'salary_structure_id' => ['nullable', 'integer', 'min:1'],
            'document_type' => ['nullable', 'string', 'max:100'],
            // A vocabulary is enforced only where the selected report actually
            // uses the filter; elsewhere the parameter is ignored, not judged.
            'employment_type' => ['nullable', $uses('employment_type') ? Rule::in(Faculty::EMPLOYMENT_TYPES) : 'string'],
            'status' => ['nullable', $statuses === [] ? 'string' : Rule::in($statuses)],
            'state' => ['nullable', $uses('state') ? Rule::in(HrReportService::DOCUMENT_STATES) : 'string'],
            'from' => ['nullable', $dateFormat],
            'to' => ['nullable', $dateFormat, 'after_or_equal:from'],
        ]);

        // Only the filters of the selected report take effect; the service always
        // receives the same keys. An integer ID from another college matches
        // nothing, because every root query is tenant-scoped.
        $filters = array_fill_keys(self::KEYS, null);
        foreach (self::FILTERS[$report] as $key) {
            $filters[$key] = $validated[$key] ?? null;
        }
        $filters['search'] = trim((string) ($filters['search'] ?? ''));

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
        if (isset($visible['leave_type_id'])) {
            $options['leaveTypes'] = LeaveType::query()->orderBy('name')->orderBy('id')->get(['id', 'name', 'code']);
        }
        if (isset($visible['salary_structure_id'])) {
            $options['salaryStructures'] = SalaryStructure::query()->orderBy('name')->orderBy('id')->get(['id', 'name', 'code']);
        }
        if (isset($visible['document_type'])) {
            $options['documentTypes'] = EmployeeDocument::query()
                ->whereNotNull('employee_documents.document_type')
                ->distinct()
                ->orderBy('employee_documents.document_type')
                ->pluck('employee_documents.document_type');
        }

        // The smaller vocabularies the forms select from.
        $options['employmentTypes'] = Faculty::EMPLOYMENT_TYPES;

        return $options;
    }
}
