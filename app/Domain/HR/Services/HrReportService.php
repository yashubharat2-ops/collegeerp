<?php

namespace App\Domain\HR\Services;

use App\Models\Department;
use App\Models\Designation;
use App\Models\EmployeeDocument;
use App\Models\Faculty;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Payroll;
use App\Models\StaffAttendance;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * HrReportService — the READ side of the HR Reports module.
 *
 * Eight live reports over the EXISTING HR / Staff records:
 *
 *   Employee / Staff List          → faculties (Staff / Employee)
 *   Department-wise Staff Report   → departments + their existing staff
 *   Designation-wise Staff Report  → designations + their existing staff
 *   Employee Documents Report      → employee_documents
 *   Staff Attendance Report        → staff_attendances
 *   Leave Report                   → leave_requests + leave_types
 *   Payroll / Salary Report        → payrolls + salary_structures
 *   HR Summary                     → aggregates of all of the above
 *
 * There are no report tables, no snapshots and no second copy of any HR fact:
 * every row and every figure is read live from the operational records through
 * the models that already own them. Nothing in this class writes, and no HR,
 * Attendance, Leave or Payroll rule is re-implemented — the reports only
 * aggregate what those modules store.
 *
 * Rules honoured by every method:
 *
 *  - Tenant safety — every root query starts from a model carrying
 *    CollegeScope (BelongsToCollege), so a forged foreign filter id can only
 *    ever produce an empty report. Staff-level filters travel through the
 *    existing relationships, never through a second query per row.
 *  - Deterministic pagination — 20 rows per page with a unique id tiebreak, so
 *    pages never overlap or skip rows, and filters survive pagination.
 *  - Constant query counts per page — counts and sums are SQL aggregates and
 *    relations are eager-loaded; nothing is fetched per row.
 *  - Respect existing rules — soft-deleted master data (departments,
 *    designations, documents, leave, staff) never appears, and a staff filter
 *    narrows through the soft-delete aware employee relationship, so removed
 *    staff are excluded from any staff-scoped report. Unfiltered attendance /
 *    leave / payroll reports still list the historical record, exactly like the
 *    operational screens, instead of hiding it; payrolls are always listed with
 *    the status the Payroll module recorded.
 *  - Read-only — nothing in this class writes.
 *
 * Filter vocabulary of the service (the controller normalises the query string
 * into it): `staff_status` is always the status of the staff record, `status`
 * is always the operational status of the row being listed (attendance status,
 * leave status, payroll status), and `from` / `to` always mean the date window
 * of the selected report.
 */
class HrReportService
{
    /** Rows per page, matching the other REPORTS modules. */
    public const PER_PAGE = 20;

    /** Derived document statuses of the Employee Documents Report. */
    public const DOCUMENT_STATUSES = ['valid', 'expiring', 'expired'];

    /** A document whose expiry falls inside this window is "expiring". */
    public const EXPIRING_WINDOW_DAYS = 30;

    /** Staff relations every report renders. */
    private const STAFF_RELATIONS = ['employee.department:id,name', 'employee.designationMaster:id,name'];

    /* ------------------------------------------------------------------ *\
     * 1. Employee / Staff List
     * \* ------------------------------------------------------------------ */

    /**
     * One row per staff / employee record with their department, designation,
     * employment type and document count.
     *
     * @param  array<string, mixed>  $filters
     * @return array{rows: LengthAwarePaginator, totals: array<string, int>}
     */
    public function employees(array $filters): array
    {
        $query = $this->staffQuery($filters);

        $rows = (clone $query)
            ->with(['department:id,name', 'designationMaster:id,name'])
            ->withCount('documents')
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->orderBy('faculties.id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return [
            'rows' => $rows,
            'totals' => [
                'staff' => (int) (clone $query)->count(),
                'active' => (int) (clone $query)->where('status', 'active')->count(),
                'inactive' => (int) (clone $query)->where('status', 'inactive')->count(),
                'with_documents' => (int) (clone $query)->whereHas('documents')->count(),
            ],
        ];
    }

    /* ------------------------------------------------------------------ *\
     * 2. Department-wise Staff Report
     * \* ------------------------------------------------------------------ */

    /**
     * One row per department with the staff counts of its existing employees.
     * The counts honour the same staff filters as the employee list (status,
     * employment type, designation, joining window and search).
     *
     * @param  array<string, mixed>  $filters
     * @return array{rows: LengthAwarePaginator, totals: array<string, int>}
     */
    public function departmentWise(array $filters): array
    {
        $query = Department::query()
            ->when($filters['department_id'] ?? null, fn (Builder $q, $id) => $q->whereKey($id))
            ->when($filters['search'] ?? null, fn (Builder $q, $search) => $q->where(function (Builder $inner) use ($search): void {
                $inner->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%");
            }))
            ->withCount($this->staffCounts($filters));

        $rows = (clone $query)
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $totals = ['departments' => 0, 'staff' => 0, 'active' => 0, 'inactive' => 0, 'with_documents' => 0];
        foreach ($rows->getCollection() as $row) {
            $totals['departments']++;
            $totals['staff'] += (int) $row->staff_count;
            $totals['active'] += (int) $row->active_staff_count;
            $totals['inactive'] += (int) $row->inactive_staff_count;
            $totals['with_documents'] += (int) $row->documented_staff_count;
        }

        return ['rows' => $rows, 'totals' => $totals];
    }

    /* ------------------------------------------------------------------ *\
     * 3. Designation-wise Staff Report
     * \* ------------------------------------------------------------------ */

    /**
     * One row per designation with the staff counts of its existing employees,
     * narrowed by the same staff filters as the other staff reports.
     *
     * @param  array<string, mixed>  $filters
     * @return array{rows: LengthAwarePaginator, totals: array<string, int>}
     */
    public function designationWise(array $filters): array
    {
        $query = Designation::query()
            ->when($filters['designation_id'] ?? null, fn (Builder $q, $id) => $q->whereKey($id))
            ->when($filters['search'] ?? null, fn (Builder $q, $search) => $q->where(function (Builder $inner) use ($search): void {
                $inner->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%");
            }))
            ->withCount($this->staffCounts($filters));

        $rows = (clone $query)
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $totals = ['designations' => 0, 'staff' => 0, 'active' => 0, 'inactive' => 0, 'with_documents' => 0];
        foreach ($rows->getCollection() as $row) {
            $totals['designations']++;
            $totals['staff'] += (int) $row->staff_count;
            $totals['active'] += (int) $row->active_staff_count;
            $totals['inactive'] += (int) $row->inactive_staff_count;
            $totals['with_documents'] += (int) $row->documented_staff_count;
        }

        return ['rows' => $rows, 'totals' => $totals];
    }

    /* ------------------------------------------------------------------ *\
     * 4. Employee Documents Report
     * \* ------------------------------------------------------------------ */

    /**
     * One row per stored employee document with the employee it belongs to and
     * its derived status (valid / expiring / expired) computed from the stored
     * expiry date — the report never writes a status back.
     *
     * @param  array<string, mixed>  $filters
     * @return array{rows: LengthAwarePaginator, totals: array<string, int>}
     */
    public function documents(array $filters): array
    {
        $query = $this->documentQuery($filters);

        $rows = (clone $query)
            ->with(self::STAFF_RELATIONS)
            ->with('documentTypeMaster:id,name,code')
            ->orderByDesc('issue_date')
            ->orderByDesc('employee_documents.id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return ['rows' => $rows, 'totals' => $this->documentTotals($query)];
    }

    /* ------------------------------------------------------------------ *\
     * 5. Staff Attendance Report
     * \* ------------------------------------------------------------------ */

    /**
     * One row per recorded staff attendance entry, with the day counts and the
     * attendance rate of the whole filtered set as SQL aggregates.
     *
     * @param  array<string, mixed>  $filters
     * @return array{rows: LengthAwarePaginator, totals: array<string, int|float>}
     */
    public function attendance(array $filters): array
    {
        $query = $this->attendanceQuery($filters);

        $rows = (clone $query)
            ->with(self::STAFF_RELATIONS)
            ->orderByDesc('attendance_date')
            ->orderByDesc('staff_attendances.id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return ['rows' => $rows, 'totals' => $this->attendanceTotals($query)];
    }

    /* ------------------------------------------------------------------ *\
     * 6. Leave Report
     * \* ------------------------------------------------------------------ */

    /**
     * One row per leave request (with its existing leave type and approver)
     * plus the day totals and the leave-type breakdown of the filtered set.
     *
     * @param  array<string, mixed>  $filters
     * @return array{rows: LengthAwarePaginator, totals: array<string, int>, by_type: array<int, array<string, mixed>>}
     */
    public function leave(array $filters): array
    {
        $query = $this->leaveQuery($filters);

        $rows = (clone $query)
            ->with([...self::STAFF_RELATIONS, 'leaveType:id,name,code', 'approver:id,name'])
            ->orderByDesc('from_date')
            ->orderByDesc('leave_requests.id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return [
            'rows' => $rows,
            'totals' => $this->leaveTotals($query),
            'by_type' => $this->leaveDaysByType($query),
        ];
    }

    /* ------------------------------------------------------------------ *\
     * 7. Payroll / Salary Report
     * \* ------------------------------------------------------------------ */

    /**
     * One row per processed / cancelled payroll with the money the Payroll
     * module stored (never recalculated here) and the totals of the set.
     *
     * @param  array<string, mixed>  $filters
     * @return array{rows: LengthAwarePaginator, totals: array<string, int|float>, by_status: array<int, array<string, mixed>>}
     */
    public function payroll(array $filters): array
    {
        $query = $this->payrollQuery($filters);

        $rows = (clone $query)
            ->with([...self::STAFF_RELATIONS, 'salaryStructure:id,name,code'])
            ->orderByDesc('pay_period')
            ->orderByDesc('payrolls.id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return [
            'rows' => $rows,
            'totals' => $this->payrollTotals($query),
            'by_status' => $this->payrollByStatus($query),
        ];
    }

    /* ------------------------------------------------------------------ *\
     * 8. HR Summary
     * \* ------------------------------------------------------------------ */

    /**
     * The read-only HR headline figures — staff, master data, documents,
     * attendance, leave and payroll — each derived from the same filtered
     * queries the individual reports use, plus the payroll reconciliation
     * (gross − deductions = net) and the department / leave-type / payroll
     * breakdowns.
     *
     * @param  array<string, mixed>  $filters
     * @return array{summary: array<string, mixed>}
     */
    public function summary(array $filters): array
    {
        $staff = $this->staffQuery($filters);
        $documents = $this->documentQuery($filters);
        $attendance = $this->attendanceQuery($filters);
        $leave = $this->leaveQuery($filters);
        $payroll = $this->payrollQuery($filters);

        $payrollTotals = $this->payrollTotals($payroll);

        return ['summary' => [
            'staff' => [
                'total' => (int) (clone $staff)->count(),
                'active' => (int) (clone $staff)->where('status', 'active')->count(),
                'inactive' => (int) (clone $staff)->where('status', 'inactive')->count(),
                'with_documents' => (int) (clone $staff)->whereHas('documents')->count(),
            ],
            'master' => [
                'departments' => (int) Department::query()
                    ->when($filters['department_id'] ?? null, fn (Builder $q, $id) => $q->whereKey($id))
                    ->count(),
                'designations' => (int) Designation::query()
                    ->when($filters['designation_id'] ?? null, fn (Builder $q, $id) => $q->whereKey($id))
                    ->count(),
            ],
            'documents' => $this->documentTotals($documents),
            'attendance' => $this->attendanceTotals($attendance),
            'leave' => $this->leaveTotals($leave) + ['by_type' => $this->leaveDaysByType($leave)],
            'payroll' => $payrollTotals + ['by_status' => $this->payrollByStatus($payroll)],
            'reconciliation' => [
                'difference' => round((float) $payrollTotals['gross'] - (float) $payrollTotals['deductions'] - (float) $payrollTotals['net'], 2),
            ],
            'department_breakdown' => $this->departmentBreakdown($filters),
        ]];
    }

    /* ------------------------------------------------------------------ *\
     * Root queries (tenant-scoped by CollegeScope)
     * \* ------------------------------------------------------------------ */

    /**
     * The staff / employee query with every staff-level filter applied:
     * status, employment type, department, designation, search and joining
     * window.
     *
     * @param  array<string, mixed>  $filters
     */
    private function staffQuery(array $filters): Builder
    {
        return Faculty::query()
            ->when($filters['faculty_id'] ?? null, fn (Builder $q, $id) => $q->whereKey($id))
            ->when($filters['search'] ?? null, fn (Builder $q, $search) => $this->searchStaff($q, $search))
            ->when($filters['department_id'] ?? null, fn (Builder $q, $id) => $q->where('department_id', $id))
            ->when($filters['designation_id'] ?? null, fn (Builder $q, $id) => $q->where('designation_id', $id))
            ->when($filters['staff_status'] ?? null, fn (Builder $q, $status) => $q->where('status', $status))
            ->when($filters['employment_type'] ?? null, fn (Builder $q, $type) => $q->where('employment_type', $type))
            ->when($filters['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('joining_date', '>=', $date))
            ->when($filters['to'] ?? null, fn (Builder $q, $date) => $q->whereDate('joining_date', '<=', $date));
    }

    /**
     * The employee-document query: staff filters travel through the existing
     * employee relationship, so a foreign department / designation / employee
     * id can only produce an empty page.
     *
     * @param  array<string, mixed>  $filters
     */
    private function documentQuery(array $filters): Builder
    {
        $query = EmployeeDocument::query()
            ->when($filters['document_type_id'] ?? null, fn (Builder $q, $id) => $q->where('document_type_id', $id));

        $this->narrowByStaff($query, $filters, $this->hasStaffFilters($filters));

        return $query
            ->when($filters['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('issue_date', '>=', $date))
            ->when($filters['to'] ?? null, fn (Builder $q, $date) => $q->whereDate('issue_date', '<=', $date))
            ->when($filters['document_status'] ?? null, fn (Builder $q, $status) => $this->applyDocumentStatus($q, $status));
    }

    /**
     * The staff attendance query with staff, status and date-range filters.
     *
     * @param  array<string, mixed>  $filters
     */
    private function attendanceQuery(array $filters): Builder
    {
        $query = StaffAttendance::query();
        $this->narrowByStaff($query, $filters, $this->hasStaffFilters($filters));

        return $query
            ->when($filters['status'] ?? null, fn (Builder $q, $status) => $q->where('status', $status))
            ->when($filters['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('attendance_date', '>=', $date))
            ->when($filters['to'] ?? null, fn (Builder $q, $date) => $q->whereDate('attendance_date', '<=', $date));
    }

    /**
     * The leave-request query. A date range selects the requests that OVERLAP
     * the window (the meaning the Leave module gives to a leave date range),
     * with each side of the window optional.
     *
     * @param  array<string, mixed>  $filters
     */
    private function leaveQuery(array $filters): Builder
    {
        $query = LeaveRequest::query()
            ->when($filters['leave_type_id'] ?? null, fn (Builder $q, $id) => $q->where('leave_type_id', $id))
            ->when($filters['status'] ?? null, fn (Builder $q, $status) => $q->where('status', $status));
        $this->narrowByStaff($query, $filters, $this->hasStaffFilters($filters));

        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;

        // Overlap = the request starts on or before the window ends AND ends on
        // or after the window starts; each side of the window is optional.
        return $query
            ->when($from, fn (Builder $q, $date) => $q->whereDate('to_date', '>=', $date))
            ->when($to, fn (Builder $q, $date) => $q->whereDate('from_date', '<=', $date));
    }

    /**
     * The payroll query; `from` / `to` are pay periods (Y-m), the vocabulary
     * the Payroll module stores on `pay_period`.
     *
     * @param  array<string, mixed>  $filters
     */
    private function payrollQuery(array $filters): Builder
    {
        $query = Payroll::query()
            ->when($filters['salary_structure_id'] ?? null, fn (Builder $q, $id) => $q->where('salary_structure_id', $id))
            ->when($filters['status'] ?? null, fn (Builder $q, $status) => $q->where('status', $status));
        $this->narrowByStaff($query, $filters, $this->hasStaffFilters($filters));

        return $query
            ->when($filters['from'] ?? null, fn (Builder $q, $period) => $q->whereDate('pay_period', '>=', $period.'-01'))
            ->when($filters['to'] ?? null, fn (Builder $q, $period) => $q->whereDate('pay_period', '<=', $period.'-01'));
    }

    /* ------------------------------------------------------------------ *\
     * Aggregates
     * \* ------------------------------------------------------------------ */

    /**
     * @return array<string, int>
     */
    private function documentTotals(Builder $query): array
    {
        $today = CarbonImmutable::today();

        return [
            'documents' => (int) (clone $query)->count(),
            'staff' => (int) (clone $query)->distinct()->count('faculty_id'),
            'expired' => (int) (clone $query)->whereNotNull('expiry_date')->whereDate('expiry_date', '<', $today)->count(),
            'expiring' => (int) (clone $query)
                ->whereNotNull('expiry_date')
                ->whereDate('expiry_date', '>=', $today)
                ->whereDate('expiry_date', '<=', $today->addDays(self::EXPIRING_WINDOW_DAYS))
                ->count(),
        ];
    }

    /**
     * @return array<string, int|float>
     */
    private function attendanceTotals(Builder $query): array
    {
        $counts = (clone $query)
            ->reorder()
            ->toBase()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->orderBy('status')
            ->pluck('total', 'status');

        $totals = ['records' => 0, 'present' => 0, 'absent' => 0, 'late' => 0, 'leave' => 0, 'holiday' => 0];
        foreach (StaffAttendance::STATUSES as $status) {
            $totals[$status] = (int) ($counts[$status] ?? 0);
            $totals['records'] += $totals[$status];
        }

        // The rate counts how many non-holiday working days were attended
        // (present or late), using the module's own status vocabulary.
        $workingDays = $totals['records'] - $totals['holiday'];
        $totals['rate'] = $workingDays > 0
            ? round((($totals['present'] + $totals['late']) / $workingDays) * 100, 1)
            : 0.0;

        return $totals;
    }

    /**
     * @return array<string, int>
     */
    private function leaveTotals(Builder $query): array
    {
        $counts = (clone $query)
            ->reorder()
            ->toBase()
            ->selectRaw('status, COUNT(*) as requests, COALESCE(SUM(days), 0) as days')
            ->groupBy('status')
            ->orderBy('status')
            ->get()
            ->keyBy('status');

        $totals = ['requests' => 0, 'days' => 0, 'approved' => 0, 'pending' => 0, 'rejected' => 0, 'cancelled' => 0, 'approved_days' => 0];
        foreach (LeaveRequest::STATUSES as $status) {
            $row = $counts[$status] ?? null;
            $totals[$status] = $row ? (int) $row->requests : 0;
            $totals['requests'] += $totals[$status];
            $totals['days'] += $row ? (int) $row->days : 0;
            if ($status === 'approved') {
                $totals['approved_days'] = $row ? (int) $row->days : 0;
            }
        }

        return $totals;
    }

    /**
     * Leave days per existing leave type (tenant-scoped; inactive types are
     * still shown as the Leave module never deletes them).
     *
     * @return array<int, array<string, mixed>>
     */
    private function leaveDaysByType(Builder $query): array
    {
        $rows = (clone $query)
            ->reorder()
            ->toBase()
            ->selectRaw('leave_type_id, COUNT(*) as requests, COALESCE(SUM(days), 0) as days')
            ->groupBy('leave_type_id')
            ->orderByDesc('days')
            ->orderBy('leave_type_id')
            ->get();

        $types = LeaveType::query()
            ->whereIn('id', $rows->pluck('leave_type_id')->filter()->unique()->values()->all())
            ->get(['id', 'name', 'code', 'status'])
            ->keyBy('id');

        return $rows->map(fn ($row): array => [
            'leave_type' => $types[$row->leave_type_id]?->name ?? 'Unknown leave type',
            'code' => $types[$row->leave_type_id]?->code,
            'type_status' => $types[$row->leave_type_id]?->status,
            'requests' => (int) $row->requests,
            'days' => (int) $row->days,
        ])->values()->all();
    }

    /**
     * @return array<string, int|float>
     */
    private function payrollTotals(Builder $query): array
    {
        $totals = (clone $query)
            ->reorder()
            ->toBase()
            ->selectRaw(
                'COUNT(*) as payrolls,'
                .' COALESCE(SUM(basic_amount), 0) as basic,'
                .' COALESCE(SUM(gross_amount), 0) as gross,'
                .' COALESCE(SUM(total_deductions), 0) as deductions,'
                .' COALESCE(SUM(net_amount), 0) as net'
            )
            ->first();

        return [
            'payrolls' => (int) ($totals->payrolls ?? 0),
            'basic' => round((float) ($totals->basic ?? 0), 2),
            'gross' => round((float) ($totals->gross ?? 0), 2),
            'deductions' => round((float) ($totals->deductions ?? 0), 2),
            'net' => round((float) ($totals->net ?? 0), 2),
        ];
    }

    /**
     * Payroll count and net per recorded status, in the Payroll vocabulary.
     *
     * @return array<int, array<string, mixed>>
     */
    private function payrollByStatus(Builder $query): array
    {
        $rows = (clone $query)
            ->reorder()
            ->toBase()
            ->selectRaw('status, COUNT(*) as payrolls, COALESCE(SUM(net_amount), 0) as net')
            ->groupBy('status')
            ->orderBy('status')
            ->get()
            ->keyBy('status');

        return array_map(fn (string $status): array => [
            'status' => $status,
            'payrolls' => isset($rows[$status]) ? (int) $rows[$status]->payrolls : 0,
            'net' => round((float) ($rows[$status]->net ?? 0), 2),
        ], Payroll::STATUSES);
    }

    /**
     * Top departments by staff count, using the same staff filters as every
     * other report (a single aggregate query, never one query per department).
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array{department: string, code: string|null, staff: int, active: int}>
     */
    private function departmentBreakdown(array $filters): array
    {
        $rows = Department::query()
            ->when($filters['department_id'] ?? null, fn (Builder $q, $id) => $q->whereKey($id))
            ->withCount($this->staffCounts($filters))
            ->orderByDesc('staff_count')
            ->orderBy('name')
            ->orderBy('id')
            ->limit(10)
            ->get(['id', 'name', 'code']);

        return $rows
            ->filter(fn (Department $department): bool => (int) $department->staff_count > 0)
            ->map(fn (Department $department): array => [
                'department' => (string) $department->name,
                'code' => $department->code,
                'staff' => (int) $department->staff_count,
                'active' => (int) $department->active_staff_count,
            ])
            ->values()
            ->all();
    }

    /* ------------------------------------------------------------------ *\
     * Helpers
     * \* ------------------------------------------------------------------ */

    /**
     * The staff aggregates counted per department / designation row. The
     * `from` / `to` window of these reports is a joining window.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, callable>
     */
    private function staffCounts(array $filters): array
    {
        // The withCount closures run on a staff (Faculty) query, so the filters
        // apply to its own columns — no extra relationship hop is needed.
        $staff = fn (Builder $query) => $this->narrowStaffColumns($query, $filters, true);

        return [
            'employees as staff_count' => $staff,
            'employees as active_staff_count' => function (Builder $query) use ($staff): void {
                $staff($query);
                $query->where('status', 'active');
            },
            'employees as inactive_staff_count' => function (Builder $query) use ($staff): void {
                $staff($query);
                $query->where('status', 'inactive');
            },
            'employees as documented_staff_count' => function (Builder $query) use ($staff): void {
                $staff($query);
                $query->whereHas('documents');
            },
        ];
    }

    /**
     * Apply the staff-level filters to a report whose root is NOT the employee
     * table (documents / attendance / leave / payroll, and the staff counts of
     * the department / designation reports).
     *
     * The narrowing travels through the existing `employee` relationship, which
     * also applies the staff model's soft-delete rule: as soon as a staff
     * attribute is filtered on, records of removed staff can no longer match.
     * An unfiltered record report behaves like the operational screen and still
     * lists the row (with the staff name blank) rather than hiding history.
     *
     * @param  array<string, mixed>  $filters
     */
    private function narrowByStaff(Builder $query, array $filters, bool $narrowStaff, bool $applyJoiningWindow = false): void
    {
        if (! $narrowStaff) {
            return;
        }

        $query->whereHas('employee', function (Builder $staff) use ($filters, $applyJoiningWindow): void {
            $this->narrowStaffColumns($staff, $filters, $applyJoiningWindow);
        });
    }

    /**
     * The staff-level filters applied to a query whose model IS the staff /
     * employee record (the employee list or a department / designation count).
     *
     * @param  array<string, mixed>  $filters
     */
    private function narrowStaffColumns(Builder $query, array $filters, bool $applyJoiningWindow = false): void
    {
        $query
            ->when($filters['faculty_id'] ?? null, fn (Builder $q, $id) => $q->whereKey($id))
            ->when($filters['department_id'] ?? null, fn (Builder $q, $id) => $q->where('department_id', $id))
            ->when($filters['designation_id'] ?? null, fn (Builder $q, $id) => $q->where('designation_id', $id))
            ->when($filters['staff_status'] ?? null, fn (Builder $q, $status) => $q->where('status', $status))
            ->when($filters['employment_type'] ?? null, fn (Builder $q, $type) => $q->where('employment_type', $type))
            ->when($filters['search'] ?? null, fn (Builder $q, $search) => $this->searchStaff($q, $search))
            ->when($applyJoiningWindow && ($filters['from'] ?? null), fn (Builder $q, $date) => $q->whereDate('joining_date', '>=', $date))
            ->when($applyJoiningWindow && ($filters['to'] ?? null), fn (Builder $q, $date) => $q->whereDate('joining_date', '<=', $date));
    }

    /**
     * Whether the filter set engages the staff dimension at all. When it does
     * not, a record report lists every record of the active college exactly
     * like the operational screen; when it does, the narrowing runs through
     * the (soft-delete aware) employee relationship.
     *
     * @param  array<string, mixed>  $filters
     */
    private function hasStaffFilters(array $filters): bool
    {
        foreach (['faculty_id', 'department_id', 'designation_id', 'staff_status', 'employment_type', 'search'] as $key) {
            if (filled($filters[$key] ?? null)) {
                return true;
            }
        }

        return false;
    }

    private function searchStaff(Builder $query, string $search): void
    {
        $query->where(function (Builder $inner) use ($search): void {
            $inner->where('employee_code', 'like', "%{$search}%")
                ->orWhere('first_name', 'like', "%{$search}%")
                ->orWhere('middle_name', 'like', "%{$search}%")
                ->orWhere('last_name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%");
        });
    }

    /**
     * Filter documents by their derived status, computed from the stored
     * expiry date exactly like the report column renders it.
     */
    private function applyDocumentStatus(Builder $query, string $status): void
    {
        $today = CarbonImmutable::today();

        match ($status) {
            'expired' => $query->whereNotNull('expiry_date')->whereDate('expiry_date', '<', $today),
            'expiring' => $query
                ->whereNotNull('expiry_date')
                ->whereDate('expiry_date', '>=', $today)
                ->whereDate('expiry_date', '<=', $today->addDays(self::EXPIRING_WINDOW_DAYS)),
            default => $query->where(function (Builder $inner) use ($today): void {
                $inner->whereNull('expiry_date')
                    ->orWhereDate('expiry_date', '>', $today->addDays(self::EXPIRING_WINDOW_DAYS));
            }),
        };
    }

    /** The derived status of one document row (the same rule as the filter). */
    public static function documentStatus(EmployeeDocument $document, ?CarbonImmutable $today = null): string
    {
        if ($document->expiry_date === null) {
            return 'valid';
        }

        $today ??= CarbonImmutable::today();

        if ($document->expiry_date->lt($today)) {
            return 'expired';
        }

        return $document->expiry_date->lte($today->addDays(self::EXPIRING_WINDOW_DAYS)) ? 'expiring' : 'valid';
    }
}
