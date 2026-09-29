<?php

namespace App\Domain\Transport\Services;

use App\Models\{StudentTransportAssignment, StudentTransportFeeAssignment, TransportDriver, TransportRoute, TransportStop, Vehicle};
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * TransportReportService — the READ side of the Transport Reports module.
 *
 * Six live reports over the EXISTING Transport, Student and Finance records:
 *
 *   Vehicle Report                        → vehicles
 *   Driver Report                         → transport_drivers + their existing staff record
 *   Route / Stop Report                   → transport_routes + their existing stops
 *   Student Transport Assignment Report   → student_transport_assignments + enrollment/student
 *   Transport Fee Report                  → student_transport_fee_assignments + shared fee_payments
 *   Transport Summary                     → live aggregates of all of the above
 *
 * There are no report tables, no snapshots and no second copy of any Transport
 * fact: every row and every figure is read live from the records the
 * operational Transport / Student / Finance modules already own. Nothing in
 * this class writes and no Transport rule is re-implemented here — statuses,
 * route → stop relationships and fee amounts are the ones the operational
 * services stored (TransportMasterService, StudentTransportAssignmentService,
 * TransportFeeService).
 *
 * Money reuses the same TransportFeeService ledger derivation (itself the
 * shared FeeLedger arithmetic over the existing fee_payments / fee_refunds
 * rows), so the report can never disagree with the Transport Fees screen. The
 * balance shown is never recalculated here.
 *
 * Rules honoured by every method:
 *
 *  - Tenant safety — every root query starts from a model carrying
 *    CollegeScope (BelongsToCollege), so a forged foreign filter id can only
 *    ever produce an empty report.
 *  - Deterministic pagination — 20 rows per page with a unique id tiebreak, so
 *    pages never overlap or skip rows, and filters survive pagination.
 *  - Constant query counts per page — counts and sums are SQL aggregates and
 *    relations are eager loaded; the per-stop / per-route student counts of the
 *    Route / Stop Report are pre-aggregated for the whole page in one query.
 *  - Respect existing rules — soft-deleted masters never appear (SoftDeletes
 *    on every Transport model) and inactive / completed / cancelled rows stay
 *    visible exactly as the operational screens show their history.
 *  - Read-only — nothing in this class writes.
 *
 * Filter vocabulary of the service (the controller normalises the query string
 * into it): `status` is always the status of the row being listed (vehicle
 * status, driver status, route status, assignment status, fee assignment
 * status) and `from` / `to` always mean the date window of the selected
 * report. `vehicle_type` is the stored vehicle type text.
 */
class TransportReportService
{
    /** Rows per page, matching the other REPORTS modules. */
    public const PER_PAGE = 20;

    public function __construct(private readonly TransportFeeService $fees)
    {
    }

    /**
     * Report 1 — Vehicle Report: every existing vehicle with the stored fleet
     * details. Filters: vehicle, status, vehicle type.
     *
     * @param  array<string, mixed>  $filters
     * @return array{rows: LengthAwarePaginator, totals: array<string, int>}
     */
    public function vehicles(array $filters): array
    {
        $query = $this->vehicleQuery($filters);

        $rows = (clone $query)->paginate(self::PER_PAGE)->withQueryString();

        $byStatus = (clone $query)->reorder()->toBase()
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $totals = [
            'total' => (int) $byStatus->sum(),
            'active' => (int) ($byStatus['active'] ?? 0),
            'inactive' => (int) ($byStatus['inactive'] ?? 0),
            'maintenance' => (int) ($byStatus['maintenance'] ?? 0),
            'retired' => (int) ($byStatus['retired'] ?? 0),
        ];

        return ['rows' => $rows, 'totals' => $totals];
    }

    /**
     * Report 2 — Driver Report: every existing driver with the contact and
     * license details of the staff record it references. Filters: driver,
     * status, license-expiry window.
     *
     * @param  array<string, mixed>  $filters
     * @return array{rows: LengthAwarePaginator, totals: array<string, int>}
     */
    public function drivers(array $filters): array
    {
        $query = $this->driverQuery($filters);

        $rows = (clone $query)->paginate(self::PER_PAGE)->withQueryString();

        $byStatus = (clone $query)->reorder()->toBase()
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $totals = [
            'total' => (int) $byStatus->sum(),
            'active' => (int) ($byStatus['active'] ?? 0),
            'inactive' => (int) ($byStatus['inactive'] ?? 0),
        ];

        return ['rows' => $rows, 'totals' => $totals];
    }

    /**
     * Report 3 — Route / Stop Report: every existing route with its existing
     * stops in their stored sequence (the route → stop relationship is the one
     * Transport Management maintains). Filters: route, stop, route status.
     *
     * @param  array<string, mixed>  $filters
     * @return array{
     *     rows: LengthAwarePaginator,
     *     routeCounts: array<int, array{active: int, total: int}>,
     *     stopCounts: array<int, array{active: int, total: int}>,
     *     totals: array<string, int>
     * }
     */
    public function routeStops(array $filters): array
    {
        $rows = TransportRoute::query()
            ->withCount([
                'stops',
                'stops as active_stops_count' => fn (Builder $q) => $q->where('status', 'active'),
            ])
            ->with('stops')
            ->when($filters['route_id'] ?? null, fn (Builder $q, $value) => $q->whereKey($value))
            ->when($filters['stop_id'] ?? null, fn (Builder $q, $value) => $q->whereHas('stops', fn (Builder $sub) => $sub->whereKey($value)))
            ->when(in_array($filters['status'] ?? null, TransportRoute::STATUSES, true), fn (Builder $q) => $q->where('status', $filters['status']))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $routeIds = $rows->getCollection()->pluck('id')->all();
        $stopIds = $rows->getCollection()->flatMap(fn (TransportRoute $route) => $route->stops->pluck('id'))->all();

        return [
            'rows' => $rows,
            'routeCounts' => $this->assignmentCounts('transport_route_id', $routeIds),
            'stopCounts' => $this->assignmentCounts('transport_stop_id', $stopIds),
            'totals' => $this->routeStopTotals($filters),
        ];
    }

    /**
     * Report 4 — Student Transport Assignment Report: the existing assignments
     * with the student / enrollment / program / section and route / stop they
     * reference. Filters: academic year, student, program (class), section,
     * route, stop, status, start-date window.
     *
     * @param  array<string, mixed>  $filters
     * @return array{rows: LengthAwarePaginator, totals: array<string, int>}
     */
    public function assignments(array $filters): array
    {
        $query = $this->assignmentQuery($filters);

        $rows = (clone $query)
            ->with([
                'studentEnrollment:id,student_id,academic_year_id,enrollment_number,program_id,section_id',
                'studentEnrollment.student:id,student_number,first_name,middle_name,last_name',
                'studentEnrollment.program:id,name,code',
                'studentEnrollment.section:id,name,code',
                'academicYear:id,name',
                'transportRoute:id,name,code',
                'transportStop:id,name,code,route_id,sequence',
            ])
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $byStatus = (clone $query)->reorder()->toBase()
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $totals = [
            'total' => (int) $byStatus->sum(),
            'active' => (int) ($byStatus['active'] ?? 0),
            'completed' => (int) ($byStatus['completed'] ?? 0),
            'cancelled' => (int) ($byStatus['cancelled'] ?? 0),
        ];

        return ['rows' => $rows, 'totals' => $totals];
    }

    /**
     * Report 5 — Transport Fee Report: every existing transport fee assignment
     * with its live ledger (assigned / collected / due) derived by the shared
     * TransportFeeService from the existing Finance fee_payments / fee_refunds
     * rows. Filters: academic year, student, route, stop, status, effective
     * date window.
     *
     * @param  array<string, mixed>  $filters
     * @return array{rows: LengthAwarePaginator, totals: array<string, mixed>}
     */
    public function fees(array $filters): array
    {
        $rows = $this->feeQuery($filters)
            ->with([
                'studentTransportAssignment:id,student_enrollment_id,academic_year_id,transport_route_id,transport_stop_id',
                'studentTransportAssignment.studentEnrollment:id,student_id,enrollment_number',
                'studentTransportAssignment.studentEnrollment.student:id,student_number,first_name,middle_name,last_name',
                'studentTransportAssignment.transportRoute:id,name,code',
                'studentTransportAssignment.transportStop:id,name,code,route_id',
                'transportFeeStructure:id,name,code',
                'academicYear:id,name',
            ])
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $ledger = $this->fees->ledgerFor($rows->getCollection());
        foreach ($rows->getCollection() as $assignment) {
            $assignment->setAttribute('ledger', $ledger[$assignment->getKey()] ?? null);
        }

        return ['rows' => $rows, 'totals' => $this->feeTotals($filters)];
    }

    /**
     * Report 6 — Transport Summary: the live transport position of the active
     * college. Every figure is a plain COUNT / SUM or the shared fee ledger —
     * nothing is invented and the summary has no filters.
     *
     * @return array<string, array<string, int|float>>
     */
    public function summary(): array
    {
        $count = fn (string $model, string $column = 'status'): array => collect(
            $model::query()->reorder()->toBase()
                ->select($column, DB::raw('COUNT(*) as total'))
                ->groupBy($column)
                ->pluck('total', $column)
        )->map(fn ($value) => (int) $value)->all();

        $vehicles = $count(Vehicle::class);
        $drivers = $count(TransportDriver::class);
        $routes = $count(TransportRoute::class);
        $stops = $count(TransportStop::class);
        $assignments = $count(StudentTransportAssignment::class);
        $feeStatuses = $count(StudentTransportFeeAssignment::class);
        $feeTotals = $this->feeTotals([]);

        return [
            'vehicles' => [
                'total' => array_sum($vehicles),
                'active' => $vehicles['active'] ?? 0,
                'inactive' => $vehicles['inactive'] ?? 0,
                'maintenance' => $vehicles['maintenance'] ?? 0,
                'retired' => $vehicles['retired'] ?? 0,
            ],
            'drivers' => [
                'total' => array_sum($drivers),
                'active' => $drivers['active'] ?? 0,
                'inactive' => $drivers['inactive'] ?? 0,
            ],
            'routes' => [
                'total' => array_sum($routes),
                'active' => $routes['active'] ?? 0,
                'inactive' => $routes['inactive'] ?? 0,
            ],
            'stops' => [
                'total' => array_sum($stops),
                'active' => $stops['active'] ?? 0,
                'inactive' => $stops['inactive'] ?? 0,
            ],
            'assignments' => [
                'total' => array_sum($assignments),
                'active' => $assignments['active'] ?? 0,
                'completed' => $assignments['completed'] ?? 0,
                'cancelled' => $assignments['cancelled'] ?? 0,
            ],
            'fees' => [
                'assignments' => array_sum($feeStatuses),
                'active' => $feeStatuses[StudentTransportFeeAssignment::STATUS_ACTIVE] ?? 0,
                'completed' => $feeStatuses[StudentTransportFeeAssignment::STATUS_COMPLETED] ?? 0,
                'cancelled' => $feeStatuses[StudentTransportFeeAssignment::STATUS_CANCELLED] ?? 0,
                'assigned' => $feeTotals['assigned'],
                'net_collected' => $feeTotals['net_collected'],
                'outstanding' => $feeTotals['outstanding'],
                'outstanding_assignments' => $feeTotals['outstanding_assignments'],
            ],
        ];
    }

    // ------------------------------------------------------------- helpers

    /**
     * @param  array<string, mixed>  $filters
     */
    private function vehicleQuery(array $filters): Builder
    {
        return Vehicle::query()
            ->when($filters['vehicle_id'] ?? null, fn (Builder $q, $value) => $q->whereKey($value))
            ->when(in_array($filters['status'] ?? null, Vehicle::STATUSES, true), fn (Builder $q) => $q->where('status', $filters['status']))
            ->when($filters['vehicle_type'] ?? null, fn (Builder $q, $value) => $q->where('vehicle_type', $value))
            // Deterministic pagination order.
            ->orderBy('registration_number')
            ->orderBy('id');
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function driverQuery(array $filters): Builder
    {
        return TransportDriver::query()
            ->with('faculty:id,employee_code,first_name,middle_name,last_name,email,phone')
            ->when($filters['driver_id'] ?? null, fn (Builder $q, $value) => $q->whereKey($value))
            ->when(in_array($filters['status'] ?? null, TransportDriver::STATUSES, true), fn (Builder $q) => $q->where('status', $filters['status']))
            ->when($filters['from'] ?? null, fn (Builder $q, $value) => $q->whereDate('license_expiry', '>=', $value))
            ->when($filters['to'] ?? null, fn (Builder $q, $value) => $q->whereDate('license_expiry', '<=', $value))
            ->orderBy('license_number')
            ->orderBy('id');
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function assignmentQuery(array $filters): Builder
    {
        return StudentTransportAssignment::query()
            ->when($filters['academic_year_id'] ?? null, fn (Builder $q, $value) => $q->where('academic_year_id', $value))
            ->when($filters['route_id'] ?? null, fn (Builder $q, $value) => $q->where('transport_route_id', $value))
            ->when($filters['stop_id'] ?? null, fn (Builder $q, $value) => $q->where('transport_stop_id', $value))
            ->when($filters['student_id'] ?? null, fn (Builder $q, $value) => $q->whereHas('studentEnrollment', fn (Builder $sub) => $sub->where('student_id', $value)))
            ->when($filters['program_id'] ?? null, fn (Builder $q, $value) => $q->whereHas('studentEnrollment', fn (Builder $sub) => $sub->where('program_id', $value)))
            ->when($filters['section_id'] ?? null, fn (Builder $q, $value) => $q->whereHas('studentEnrollment', fn (Builder $sub) => $sub->where('section_id', $value)))
            ->when(in_array($filters['status'] ?? null, StudentTransportAssignment::STATUSES, true), fn (Builder $q) => $q->where('status', $filters['status']))
            ->when($filters['from'] ?? null, fn (Builder $q, $value) => $q->whereDate('start_date', '>=', $value))
            ->when($filters['to'] ?? null, fn (Builder $q, $value) => $q->whereDate('start_date', '<=', $value))
            // Deterministic pagination order.
            ->orderByDesc('start_date')
            ->orderByDesc('id');
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function feeQuery(array $filters): Builder
    {
        return StudentTransportFeeAssignment::query()
            ->when($filters['academic_year_id'] ?? null, fn (Builder $q, $value) => $q->where('academic_year_id', $value))
            ->when($filters['route_id'] ?? null, fn (Builder $q, $value) => $q->whereHas('studentTransportAssignment', fn (Builder $sub) => $sub->where('transport_route_id', $value)))
            ->when($filters['stop_id'] ?? null, fn (Builder $q, $value) => $q->whereHas('studentTransportAssignment', fn (Builder $sub) => $sub->where('transport_stop_id', $value)))
            ->when($filters['student_id'] ?? null, fn (Builder $q, $value) => $q->whereHas('studentTransportAssignment.studentEnrollment', fn (Builder $sub) => $sub->where('student_id', $value)))
            ->when(in_array($filters['status'] ?? null, StudentTransportFeeAssignment::STATUSES, true), fn (Builder $q) => $q->where('status', $filters['status']))
            ->when($filters['from'] ?? null, fn (Builder $q, $value) => $q->whereDate('effective_from', '>=', $value))
            ->when($filters['to'] ?? null, fn (Builder $q, $value) => $q->whereDate('effective_from', '<=', $value))
            ->orderByDesc('effective_from')
            ->orderByDesc('id');
    }

    /**
     * Money totals across the whole filtered fee set (not just the page),
     * derived by the shared TransportFeeService ledger in bounded batches —
     * the report never recalculates a balance itself.
     *
     * @param  array<string, mixed>  $filters
     * @return array{assigned: float, net_collected: float, outstanding: float, assignments: int, outstanding_assignments: int}
     */
    private function feeTotals(array $filters): array
    {
        $assigned = 0.0;
        $collected = 0.0;
        $outstanding = 0.0;
        $assignments = 0;
        $owing = 0;

        $this->feeQuery($filters)->reorder()->orderBy('id')->chunkById(200, function ($chunk) use (&$assigned, &$collected, &$outstanding, &$assignments, &$owing): void {
            $ledger = $this->fees->ledgerFor($chunk);
            foreach ($chunk as $assignment) {
                $summary = $ledger[$assignment->getKey()] ?? null;
                if ($summary === null) {
                    continue;
                }
                $assigned += $summary['assigned'];
                $collected += $summary['net_collected'];
                $outstanding += $summary['outstanding'];
                $assignments++;
                if ($summary['outstanding'] > 0) {
                    $owing++;
                }
            }
        });

        return [
            'assigned' => round($assigned, 2),
            'net_collected' => round($collected, 2),
            'outstanding' => round($outstanding, 2),
            'assignments' => $assignments,
            'outstanding_assignments' => $owing,
        ];
    }

    /**
     * Live student assignment counts (total + active) per route or stop for
     * one page of the Route / Stop Report — one grouped query, never one per
     * row.
     *
     * @param  array<int>  $ids
     * @return array<int, array{active: int, total: int}>
     */
    private function assignmentCounts(string $column, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = StudentTransportAssignment::query()
            ->whereIn($column, $ids)
            ->reorder()
            ->toBase()
            ->select($column, 'status', DB::raw('COUNT(*) as total'))
            ->groupBy($column, 'status')
            ->get();

        $counts = [];
        foreach ($ids as $id) {
            $counts[$id] = ['active' => 0, 'total' => 0];
        }
        foreach ($rows as $row) {
            $counts[$row->{$column}]['total'] += (int) $row->total;
            if ($row->status === StudentTransportAssignment::STATUS_ACTIVE) {
                $counts[$row->{$column}]['active'] += (int) $row->total;
            }
        }

        return $counts;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, int>
     */
    private function routeStopTotals(array $filters): array
    {
        $routeQuery = TransportRoute::query()
            ->when($filters['route_id'] ?? null, fn (Builder $q, $value) => $q->whereKey($value))
            ->when($filters['stop_id'] ?? null, fn (Builder $q, $value) => $q->whereHas('stops', fn (Builder $sub) => $sub->whereKey($value)))
            ->when(in_array($filters['status'] ?? null, TransportRoute::STATUSES, true), fn (Builder $q) => $q->where('status', $filters['status']))
            ->reorder();

        $routeStatuses = (clone $routeQuery)->toBase()
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $stopStatuses = TransportStop::query()
            ->whereIn('route_id', (clone $routeQuery)->select('id'))
            ->reorder()
            ->toBase()
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'routes' => (int) $routeStatuses->sum(),
            'routes_active' => (int) ($routeStatuses['active'] ?? 0),
            'routes_inactive' => (int) ($routeStatuses['inactive'] ?? 0),
            'stops' => (int) $stopStatuses->sum(),
            'stops_active' => (int) ($stopStatuses['active'] ?? 0),
            'stops_inactive' => (int) ($stopStatuses['inactive'] ?? 0),
        ];
    }
}
