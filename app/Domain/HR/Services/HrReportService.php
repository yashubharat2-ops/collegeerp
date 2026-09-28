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
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;

/**
 * HrReportService — the READ side of the HR Reports module.
 *
 * Eight live reports over the EXISTING HR / Staff records (employees, staff
 * departments, designations, employee documents, staff attendance, leave
 * requests and leave types, payrolls). This module owns policy restrictions
 * only — it writes nothing, keeps no report tables and no snapshots:
 *
 *  - pay figures are the payroll's OWN stored amounts (basic / gross /
 *    deductions / net). PayrollService::calculate() remains the one place where
 *    those amounts are computed, and is never re-implemented here;
 *  - leave figures are the leave request's OWN stored `days`; approved and
 *    pending are distinguished by the record's own status;
 *  - staff counts come from the existing Faculty/Employee records and their
 *    department / designation relations.
 *
 * Rules honoured by every method:
 *
 *  - Tenant safety — every root query goes through a model carrying
 *    CollegeScope, and cross-table narrowing travels through the existing
 *    relationships, so a forged foreign filter id can only ever produce an
 *    empty report.
 *  - Deterministic pagination — 20 rows per page with a unique tiebreak (the id)
 *    so pages never overlap or skip rows, and filters survive pagination.
 *  - Constant query counts per page — counts come from SQL aggregates and
 *    relations are eager-loaded; nothing is fetched per row.
 *  - Read-only — nothing in this class writes.
 */
class HrReportService
{
    /** Rows per page, matching the other REPORTS modules. */
    public const PER_PAGE = 20;

    /** Documents expiring within this window are reported as expiring soon. */
    public const EXPIRING_WINDOW_DAYS = 30;

    /** The Department model exposes no constant; staff departments use these. */
    public const DEPARTMENT_STATUSES = ['active', 'inactive'];

    /** Derived document compliance states (the report's own vocabulary). */
    public const DOCUMENT_STATES = ['expired', 'expiring', 'valid', 'none'];

    /* ------------------------------------------------------------------ *
     * 1. Employee / Staff Directory Report
     * ------------------------------------------------------------------ */

    /**
     * One row per staff member of the active college with their department,
     * designation, employment type and status. Inactive employees are listed
     * (they are part of the record) and can be excluded with the status filter.
     *
     * @param  array<string, mixed>  $filters
     * @return array{rows: LengthAwarePaginator, totals: array<string, mixed>}
     */
    public function directory(array $filters): array
    {
        $query = $this->directoryQuery($filters);

        $rows = (clone $query)
            ->with(['department:id,name,code', 'designationMaster:id,name'])
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return [
            'rows' => $rows,
            'totals' => [
                'employees' => (clone $query)->count(),
                'active' => (clone $query)->where('faculties.status', 'active')->count(),
                'inactive' => (clone $query)->where('faculties.status', 'inactive')->count(),
                'departments' => (clone $query)->whereNotNull('faculties.department_id')->distinct()->count('faculties.department_id'),
            ],
        ];
    }

    /* ------------------------------------------------------------------ *
     * 2. Department-wise Staff Report
     * ------------------------------------------------------------------ */

    /**
     * One row per staff department with its live headcount, plus a tile row
     * covering the staff that no department holds.
     *
     * @param  array<string, mixed>  $filters
     * @return array{rows: LengthAwarePaginator, totals: array<string, mixed>}
     */
    public function department(array $filters): array
    {
        $search = trim((string) ($filters['search'] ?? ''));

        $query = Department::query()
            ->when(
                in_array($filters['status'] ?? null, self::DEPARTMENT_STATUSES, true),
                fn (Builder $q) => $q->where('departments.status', $filters['status'])
            )
            ->when($search !== '', fn (Builder $q) => $q->where(function (Builder $inner) use ($search): void {
                $inner->where('departments.name', 'like', "%{$search}%")
                    ->orWhere('departments.code', 'like', "%{$search}%");
            }))
            ->orderBy('departments.name')
            ->orderBy('departments.id');

        $rows = (clone $query)
            ->withCount([
                'employees as employees_count',
                'employees as active_employees_count' => fn (Builder $q) => $q->where('faculties.status', 'active'),
            ])
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return [
            'rows' => $rows,
            'totals' => [
                'departments' => (clone $query)->count(),
                'staff' => (int) Faculty::query()
                    ->whereIn('faculties.department_id', (clone $query)->select('departments.id')->toBase())
                    ->count(),
                'unassigned' => (int) Faculty::query()->whereNull('faculties.department_id')->count(),
            ],
        ];
    }

    /* ------------------------------------------------------------------ *
     * 3. Designation-wise Staff Report
     * ------------------------------------------------------------------ */

    /**
     * One row per designation with its live headcount.
     *
     * @param  array<string, mixed>  $filters
     * @return array{rows: LengthAwarePaginator, totals: array<string, mixed>}
     */
    public function designation(array $filters): array
    {
        $search = trim((string) ($filters['search'] ?? ''));

        $query = Designation::query()
            ->when(
                in_array($filters['status'] ?? null, Designation::STATUSES, true),
                fn (Builder $q) => $q->where('designations.status', $filters['status'])
            )
            ->when($search !== '', fn (Builder $q) => $q->where(function (Builder $inner) use ($search): void {
                $inner->where('designations.name', 'like', "%{$search}%")
                    ->orWhere('designations.code', 'like', "%{$search}%");
            }))
            ->orderBy('designations.name')
            ->orderBy('designations.id');

        $rows = (clone $query)
            ->withCount([
                'employees as employees_count',
                'employees as active_employees_count' => fn (Builder $q) => $q->where('faculties.status', 'active'),
            ])
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return [
            'rows' => $rows,
            'totals' => [
                'designations' => (clone $query)->count(),
                'staff' => (int) Faculty::query()
                    ->whereIn('faculties.designation_id', (clone $query)->select('designations.id')->toBase())
                    ->count(),
                'unassigned' => (int) Faculty::query()->whereNull('faculties.designation_id')->count(),
            ],
        ];
    }

    /* ------------------------------------------------------------------ *
     * 4. Staff Attendance Report
     * ------------------------------------------------------------------ */

    /**
     * One row per employee and attendance status with the number of days
     * recorded in the filtered range — the module's own attendance records,
     * counted, never recalculated. The tiles add the same records up.
     *
     * @param  array<string, mixed>  $filters
     * @return array{rows: LengthAwarePaginator, totals: array<string, mixed>}
     */
    public function attendance(array $filters): array
    {
        $base = $this->attendanceQuery($filters);

        $rows = (clone $base)
            ->select('staff_attendances.faculty_id', 'staff_attendances.status')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('MAX(staff_attendances.attendance_date) as last_date')
            ->groupBy('staff_attendances.faculty_id', 'staff_attendances.status')
            // Deterministic pagination over the grouped set.
            ->orderBy('staff_attendances.faculty_id')
            ->orderBy('staff_attendances.status')
            ->with('employee.department:id,name')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $totals = [
            'days' => (clone $base)->count(),
            'employees' => (clone $base)->distinct()->count('staff_attendances.faculty_id'),
        ];

        foreach (StaffAttendance::STATUSES as $status) {
            $totals[$status] = (clone $base)->where('staff_attendances.status', $status)->count();
        }

        // A recorded holiday is not a working day, so it leaves the rate alone.
        $countable = $totals['days'] - $totals['holiday'];
        $totals['attendance_rate'] = $countable > 0
            ? round(100 * ($totals['present'] + $totals['late']) / $countable, 1)
            : null;

        return ['rows' => $rows, 'totals' => $totals];
    }

    /* ------------------------------------------------------------------ *
     * 5. Leave Report
     * ------------------------------------------------------------------ */

    /**
     * One row per leave request with its employee, leave type, dates and the
     * request's OWN stored day count.
     *
     * @param  array<string, mixed>  $filters
     * @return array{rows: LengthAwarePaginator, totals: array<string, mixed>}
     */
    public function leave(array $filters): array
    {
        $query = $this->leaveQuery($filters)
            ->orderByDesc('leave_requests.from_date')
            ->orderByDesc('leave_requests.id');

        $rows = (clone $query)
            ->with(['employee.department:id,name', 'leaveType:id,name,code'])
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $totals = [
            'requests' => (clone $query)->count(),
            'days' => round((float) (clone $query)->sum('leave_requests.days'), 2),
        ];

        foreach (LeaveRequest::STATUSES as $status) {
            $totals[$status] = (clone $query)->where('leave_requests.status', $status)->count();
            $totals[$status.'_days'] = round((float) (clone $query)->where('leave_requests.status', $status)->sum('leave_requests.days'), 2);
        }

        return ['rows' => $rows, 'totals' => $totals];
    }

    /* ------------------------------------------------------------------ *
     * 6. Leave Balance Report
     * ------------------------------------------------------------------ */

    /**
     * One row per employee with their annual leave entitlement (the active
     * leave types' own max_days_per_year), the days they have taken in the
     * filtered period (approved), the days still awaiting a decision (pending)
     * and what remains. Balances are derived from the recorded requests.
     *
     * @param  array<string, mixed>  $filters
     * @return array{rows: LengthAwarePaginator, totals: array<string, mixed>}
     */
    public function leaveBalance(array $filters): array
    {
        $entitlement = $this->annualEntitlement();

        $query = $this->directoryQuery($filters);

        $rows = (clone $query)
            ->addSelect([
                'approved_days' => $this->leaveDaysSubquery('approved', $filters),
                'pending_days' => $this->leaveDaysSubquery('pending', $filters),
            ])
            ->with(['department:id,name', 'designationMaster:id,name'])
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $rows->getCollection()->each(function (Faculty $employee) use ($entitlement): void {
            $approved = (float) ($employee->approved_days ?? 0);
            $employee->setAttribute('entitlement_days', $entitlement);
            $employee->setAttribute('availed_days', round($approved, 2));
            $employee->setAttribute('balance_days', round($entitlement - $approved, 2));
        });

        $facultyIds = (clone $query)->select('faculties.id')->toBase();
        $approved = $this->leaveDaysTotal($filters, (clone $facultyIds), 'approved');
        $pending = $this->leaveDaysTotal($filters, (clone $facultyIds), 'pending');
        $employees = (clone $query)->count();

        return [
            'rows' => $rows,
            'totals' => [
                'employees' => $employees,
                'entitlement' => round($entitlement * $employees, 2),
                'approved_days' => $approved,
                'pending_days' => $pending,
                'balance_days' => round(($entitlement * $employees) - $approved, 2),
            ],
        ];
    }

    /* ------------------------------------------------------------------ *
     * 7. Payroll / Salary Register
     * ------------------------------------------------------------------ */

    /**
     * One row per payroll run with the amounts the payroll itself recorded
     * (gross, deductions, net) — PayrollService stays the only place those
     * figures are computed.
     *
     * @param  array<string, mixed>  $filters
     * @return array{rows: LengthAwarePaginator, totals: array<string, mixed>}
     */
    public function payroll(array $filters): array
    {
        $query = $this->payrollQuery($filters)
            ->orderByDesc('payrolls.pay_period')
            ->orderByDesc('payrolls.id');

        $rows = (clone $query)
            ->with(['employee.department:id,name', 'salaryStructure:id,name,code', 'processor:id,name'])
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $status = in_array($filters['status'] ?? null, Payroll::STATUSES, true) ? (string) $filters['status'] : null;

        $sum = fn (string $column): float => round((float) (clone $query)->sum($column), 2);

        return [
            'rows' => $rows,
            'totals' => [
                'payrolls' => (clone $query)->count(),
                'employees' => (clone $query)->distinct()->count('payrolls.faculty_id'),
                'basic' => $sum('payrolls.basic_amount'),
                'gross' => $sum('payrolls.gross_amount'),
                'deductions' => $sum('payrolls.total_deductions'),
                'net' => $sum('payrolls.net_amount'),
                // Cancelled runs are never paid out; the tiles say what the
                // listed set holds, and what of it is actually processed.
                'processed' => (clone $query)->where('payrolls.status', 'processed')->count(),
                'status' => $status,
            ],
        ];
    }

    /* ------------------------------------------------------------------ *
     * 8. Employee Document / Compliance Report
     * ------------------------------------------------------------------ */

    /**
     * One row per employee document with its issue / expiry dates and the
     * compliance state they imply (expired, expiring within the warning
     * window, valid, or no expiry at all). Only metadata is reported — the
     * stored file path is never rendered.
     *
     * @param  array<string, mixed>  $filters
     * @return array{rows: LengthAwarePaginator, totals: array<string, mixed>}
     */
    public function documents(array $filters): array
    {
        $base = $this->documentsQuery($filters);

        $state = in_array($filters['state'] ?? null, self::DOCUMENT_STATES, true) ? (string) $filters['state'] : null;

        $rows = (clone $base)
            ->when($state !== null, fn (Builder $q) => $this->constrainDocumentState($q, $state))
            ->orderByDesc('employee_documents.expiry_date')
            ->orderByDesc('employee_documents.id')
            ->with('employee.department:id,name')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        [$today, $soon] = $this->documentWindow();

        // The state each listed document is in, derived from its own expiry date.
        $rows->getCollection()->each(function (EmployeeDocument $document) use ($today, $soon): void {
            $expiry = $document->expiry_date?->toDateString();

            $document->setAttribute('compliance_state', match (true) {
                $expiry === null => 'none',
                $expiry < $today => 'expired',
                $expiry <= $soon => 'expiring',
                default => 'valid',
            });
        });

        $totals = [
            'documents' => (clone $base)->count(),
            'employees' => (clone $base)->distinct()->count('employee_documents.faculty_id'),
            'expired' => (clone $base)->whereNotNull('employee_documents.expiry_date')->whereDate('employee_documents.expiry_date', '<', $today)->count(),
            'expiring' => (clone $base)->whereDate('employee_documents.expiry_date', '>=', $today)->whereDate('employee_documents.expiry_date', '<=', $soon)->count(),
            'valid' => (clone $base)->whereDate('employee_documents.expiry_date', '>', $soon)->count(),
            'none' => (clone $base)->whereNull('employee_documents.expiry_date')->count(),
            'state' => $state,
        ];

        return ['rows' => $rows, 'totals' => $totals];
    }

    /* ------------------------------------------------------------------ *
     * Shared query builders
     * ------------------------------------------------------------------ */

    /**
     * Staff of the active college, narrowed by the directory filters the
     * Directory, Leave Balance and Headcount reports share.
     *
     * @param  array<string, mixed>  $filters
     */
    private function directoryQuery(array $filters): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));

        return Faculty::query()
            ->when($filters['faculty_id'] ?? null, fn (Builder $q, $value) => $q->where('faculties.id', $value))
            ->when($filters['department_id'] ?? null, fn (Builder $q, $value) => $q->where('faculties.department_id', $value))
            ->when($filters['designation_id'] ?? null, fn (Builder $q, $value) => $q->where('faculties.designation_id', $value))
            ->when(
                in_array($filters['employment_type'] ?? null, Faculty::EMPLOYMENT_TYPES, true),
                fn (Builder $q) => $q->where('faculties.employment_type', $filters['employment_type'])
            )
            ->when(
                in_array($filters['status'] ?? null, Faculty::STATUSES, true),
                fn (Builder $q) => $q->where('faculties.status', $filters['status'])
            )
            ->when($search !== '', fn (Builder $q) => $q->where(function (Builder $inner) use ($search): void {
                $inner->where('faculties.employee_code', 'like', "%{$search}%")
                    ->orWhere('faculties.first_name', 'like', "%{$search}%")
                    ->orWhere('faculties.last_name', 'like', "%{$search}%")
                    ->orWhere('faculties.email', 'like', "%{$search}%");
            }))
            // Deterministic pagination order; the id breaks name ties.
            ->orderBy('faculties.last_name')
            ->orderBy('faculties.first_name')
            ->orderBy('faculties.id');
    }

    /**
     * Attendance records of the active college, narrowed by the attendance
     * filters (row aggregation and tiles read the same set).
     *
     * @param  array<string, mixed>  $filters
     */
    private function attendanceQuery(array $filters): Builder
    {
        return StaffAttendance::query()
            // Attendance of a soft-deleted employee is no longer reported.
            ->whereHas('employee')
            ->when($filters['faculty_id'] ?? null, fn (Builder $q, $value) => $q->where('staff_attendances.faculty_id', $value))
            ->when($filters['department_id'] ?? null, fn (Builder $q, $value) => $q->whereHas(
                'employee',
                fn (Builder $employee) => $employee->where('faculties.department_id', $value)
            ))
            ->when(
                in_array($filters['status'] ?? null, StaffAttendance::STATUSES, true),
                fn (Builder $q) => $q->where('staff_attendances.status', $filters['status'])
            )
            ->when($filters['from'] ?? null, fn (Builder $q, $value) => $q->whereDate('staff_attendances.attendance_date', '>=', $value))
            ->when($filters['to'] ?? null, fn (Builder $q, $value) => $q->whereDate('staff_attendances.attendance_date', '<=', $value));
    }

    /**
     * Leave requests of the active college. A date range selects the requests
     * that OVERLAP the period (the rule the Leave Management screen uses).
     *
     * @param  array<string, mixed>  $filters
     */
    private function leaveQuery(array $filters): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));

        return LeaveRequest::query()
            // Requests of a soft-deleted employee are no longer reported.
            ->whereHas('employee')
            ->when($filters['faculty_id'] ?? null, fn (Builder $q, $value) => $q->where('leave_requests.faculty_id', $value))
            ->when($filters['department_id'] ?? null, fn (Builder $q, $value) => $q->whereHas(
                'employee',
                fn (Builder $employee) => $employee->where('faculties.department_id', $value)
            ))
            ->when($filters['leave_type_id'] ?? null, fn (Builder $q, $value) => $q->where('leave_requests.leave_type_id', $value))
            ->when(
                in_array($filters['status'] ?? null, LeaveRequest::STATUSES, true),
                fn (Builder $q) => $q->where('leave_requests.status', $filters['status'])
            )
            ->when($filters['from'] ?? null, fn (Builder $q, $value) => $q
                ->whereDate('leave_requests.from_date', '<=', $filters['to'] ?? $value)
                ->whereDate('leave_requests.to_date', '>=', $value))
            ->when(
                ! ($filters['from'] ?? null) && ($filters['to'] ?? null),
                fn (Builder $q) => $q->whereDate('leave_requests.from_date', '<=', $filters['to'])
            )
            ->when($search !== '', fn (Builder $q) => $q->whereHas(
                'employee',
                fn (Builder $employee) => $employee->where(function (Builder $inner) use ($search): void {
                    $inner->where('faculties.employee_code', 'like', "%{$search}%")
                        ->orWhere('faculties.first_name', 'like', "%{$search}%")
                        ->orWhere('faculties.last_name', 'like', "%{$search}%");
                })
            ));
    }

    /**
     * Payroll runs of the active college. `from` / `to` are YEAR-MONTH values and
     * compare against the stored pay period date.
     *
     * @param  array<string, mixed>  $filters
     */
    private function payrollQuery(array $filters): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));

        return Payroll::query()
            // Payroll of a soft-deleted employee is no longer reported.
            ->whereHas('employee')
            ->when($filters['faculty_id'] ?? null, fn (Builder $q, $value) => $q->where('payrolls.faculty_id', $value))
            ->when($filters['department_id'] ?? null, fn (Builder $q, $value) => $q->whereHas(
                'employee',
                fn (Builder $employee) => $employee->where('faculties.department_id', $value)
            ))
            ->when($filters['salary_structure_id'] ?? null, fn (Builder $q, $value) => $q->where('payrolls.salary_structure_id', $value))
            ->when(
                in_array($filters['status'] ?? null, Payroll::STATUSES, true),
                fn (Builder $q) => $q->where('payrolls.status', $filters['status'])
            )
            ->when($filters['from'] ?? null, fn (Builder $q, $value) => $q->whereDate('payrolls.pay_period', '>=', $value.'-01'))
            ->when($filters['to'] ?? null, fn (Builder $q, $value) => $q->whereDate('payrolls.pay_period', '<=', $value.'-01'))
            ->when($search !== '', fn (Builder $q) => $q->whereHas(
                'employee',
                fn (Builder $employee) => $employee->where(function (Builder $inner) use ($search): void {
                    $inner->where('faculties.employee_code', 'like', "%{$search}%")
                        ->orWhere('faculties.first_name', 'like', "%{$search}%")
                        ->orWhere('faculties.last_name', 'like', "%{$search}%");
                })
            ));
    }

    /**
     * Employee documents of the active college, narrowed by every filter EXCEPT
     * the derived compliance state (so the tiles can still count all states of
     * the same filtered set).
     *
     * @param  array<string, mixed>  $filters
     */
    private function documentsQuery(array $filters): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));

        return EmployeeDocument::query()
            // Documents of a soft-deleted employee are no longer reported.
            ->whereHas('employee')
            ->when($filters['faculty_id'] ?? null, fn (Builder $q, $value) => $q->where('employee_documents.faculty_id', $value))
            ->when($filters['department_id'] ?? null, fn (Builder $q, $value) => $q->whereHas(
                'employee',
                fn (Builder $employee) => $employee->where('faculties.department_id', $value)
            ))
            ->when(
                filled($filters['document_type'] ?? null),
                fn (Builder $q) => $q->where('employee_documents.document_type', $filters['document_type'])
            )
            ->when($filters['from'] ?? null, fn (Builder $q, $value) => $q->whereDate('employee_documents.expiry_date', '>=', $value))
            ->when($filters['to'] ?? null, fn (Builder $q, $value) => $q->whereDate('employee_documents.expiry_date', '<=', $value))
            ->when($search !== '', fn (Builder $q) => $q->where(function (Builder $inner) use ($search): void {
                $inner->where('employee_documents.document_name', 'like', "%{$search}%")
                    ->orWhereHas('employee', fn (Builder $employee) => $employee->where(function (Builder $staff) use ($search): void {
                        $staff->where('faculties.employee_code', 'like', "%{$search}%")
                            ->orWhere('faculties.first_name', 'like', "%{$search}%")
                            ->orWhere('faculties.last_name', 'like', "%{$search}%");
                    }));
            }));
    }

    /**
     * The slice of the compliance vocabulary the state filter selects.
     */
    private function constrainDocumentState(Builder $query, string $state): Builder
    {
        [$today, $soon] = $this->documentWindow();

        return match ($state) {
            'expired' => $query
                ->whereNotNull('employee_documents.expiry_date')
                ->whereDate('employee_documents.expiry_date', '<', $today),
            'expiring' => $query
                ->whereDate('employee_documents.expiry_date', '>=', $today)
                ->whereDate('employee_documents.expiry_date', '<=', $soon),
            'valid' => $query->whereDate('employee_documents.expiry_date', '>', $soon),
            default => $query->whereNull('employee_documents.expiry_date'),
        };
    }

    /**
     * [today, end of the warning window] as database-comparable date strings.
     *
     * @return array{0: string, 1: string}
     */
    private function documentWindow(): array
    {
        $today = Carbon::now()->startOfDay();

        return [
            $today->toDateString(),
            $today->copy()->addDays(self::EXPIRING_WINDOW_DAYS)->toDateString(),
        ];
    }

    /**
     * Total leave days one employee carries in the given status over the
     * filtered period, as a correlated subquery of the employee query.
     *
     * @param  array<string, mixed>  $filters
     */
    private function leaveDaysSubquery(string $status, array $filters): Builder
    {
        return LeaveRequest::query()
            ->selectRaw('COALESCE(SUM(leave_requests.days), 0)')
            ->whereColumn('leave_requests.faculty_id', 'faculties.id')
            ->where('leave_requests.status', $status)
            ->when($filters['from'] ?? null, fn (Builder $q, $value) => $q->whereDate('leave_requests.to_date', '>=', $value))
            ->when($filters['to'] ?? null, fn (Builder $q, $value) => $q->whereDate('leave_requests.from_date', '<=', $value));
    }

    /**
     * The same leave-day total over a whole set of employees (period rules
     * identical to the per-row subquery above).
     *
     * @param  array<string, mixed>  $filters
     */
    private function leaveDaysTotal(array $filters, Builder|QueryBuilder $facultyIds, string $status): float
    {
        return round((float) LeaveRequest::query()
            ->whereIn('leave_requests.faculty_id', $facultyIds)
            ->where('leave_requests.status', $status)
            ->when($filters['from'] ?? null, fn (Builder $q, $value) => $q->whereDate('leave_requests.to_date', '>=', $value))
            ->when($filters['to'] ?? null, fn (Builder $q, $value) => $q->whereDate('leave_requests.from_date', '<=', $value))
            ->sum('leave_requests.days'), 2);
    }

    /**
     * The annual entitlement one employee holds: the active leave types' own
     * max_days_per_year, added up. Leave types without a limit add nothing.
     */
    private function annualEntitlement(): float
    {
        return (float) LeaveType::query()->where('leave_types.status', 'active')->sum('leave_types.max_days_per_year');
    }
}
