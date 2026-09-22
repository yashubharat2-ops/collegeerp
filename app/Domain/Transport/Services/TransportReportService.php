<?php

namespace App\Domain\Transport\Services;

use App\Models\{StudentTransportAssignment, StudentTransportFeeAssignment, TransportDriver, TransportRoute, TransportStop, Vehicle, VehicleDocument};
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * TransportReportService — READ-ONLY Transport reporting.
 *
 * Every figure is aggregated live from the EXISTING Transport, Student and
 * Finance records (vehicles, vehicle_documents, transport_drivers,
 * transport_routes/stops, student_transport_assignments,
 * student_transport_fee_assignments and the shared fee_payments rows). There
 * are no report tables, no second copy of any business fact, and NOTHING in
 * this service writes.
 *
 * Fee figures reuse the same TransportFeeService ledger derivation (itself the
 * shared FeeLedger arithmetic over fee_payments / fee_refunds), so a report can
 * never disagree with the Transport Fees screen.
 *
 * All queries run through the tenant-scoped models (CollegeScope) with
 * deterministic ordering, and every aggregation uses plain COUNT / SUM /
 * GROUP BY / JOIN constructs that behave identically on SQLite and MySQL.
 */
class TransportReportService
{
    public function __construct(private readonly TransportFeeService $fees)
    {
    }

    /**
     * Report 1 — Vehicle Summary: every vehicle with its live document counts.
     *
     * @param  array<string, mixed>  $filters
     */
    public function vehicleSummary(array $filters): LengthAwarePaginator
    {
        $query = Vehicle::query()
            ->withCount(['documents as documents_count', 'documents as expiring_documents_count' => fn (Builder $q) => $q->whereNotNull('expiry_date')->whereBetween('expiry_date', [now()->toDateString(), now()->addDays(VehicleDocument::EXPIRING_SOON_DAYS)->toDateString()])])
            ->when($filters['vehicle_id'] ?? null, fn (Builder $q, $value) => $q->whereKey($value))
            ->when(in_array($filters['status'] ?? null, Vehicle::STATUSES, true), fn (Builder $q) => $q->where('status', $filters['status']))
            // Deterministic pagination order.
            ->orderBy('registration_number')
            ->orderBy('id');

        return $query->paginate(15)->withQueryString();
    }

    /**
     * Report 2 — Vehicle Status: live counts per vehicle status.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array{status: string, vehicles: int}>
     */
    public function vehicleStatus(array $filters): array
    {
        $rows = Vehicle::query()
            ->reorder()
            ->toBase()
            ->select('status', DB::raw('COUNT(*) as vehicles'))
            ->groupBy('status')
            ->orderBy('status')
            ->get();

        return array_map(fn ($row) => ['status' => (string) $row->status, 'vehicles' => (int) $row->vehicles], $rows->all());
    }

    /**
     * Report 3 — Vehicle Document Expiry: documents with an expiry date,
     * nearest expiry first (expired ones included — they need attention).
     *
     * @param  array<string, mixed>  $filters
     */
    public function vehicleDocumentExpiry(array $filters): LengthAwarePaginator
    {
        $query = VehicleDocument::query()
            ->with(['vehicle:id,college_id,registration_number'])
            ->whereNotNull('expiry_date')
            ->when($filters['vehicle_id'] ?? null, fn (Builder $q, $value) => $q->where('vehicle_id', $value))
            ->when(($filters['status'] ?? null) === 'expired', fn (Builder $q) => $q->where('expiry_date', '<', now()->toDateString()))
            ->when(($filters['status'] ?? null) === 'expiring', fn (Builder $q) => $q->whereBetween('expiry_date', [now()->toDateString(), now()->addDays(VehicleDocument::EXPIRING_SOON_DAYS)->toDateString()]))
            ->when(($filters['status'] ?? null) === 'active', fn (Builder $q) => $q->where('expiry_date', '>', now()->addDays(VehicleDocument::EXPIRING_SOON_DAYS)->toDateString()))
            ->when($filters['from'] ?? null, fn (Builder $q, $value) => $q->whereDate('expiry_date', '>=', $value))
            ->when($filters['to'] ?? null, fn (Builder $q, $value) => $q->whereDate('expiry_date', '<=', $value))
            ->orderBy('expiry_date')
            ->orderBy('id');

        return $query->paginate(15)->withQueryString();
    }

    /**
     * Report 4 — Driver Summary: drivers with their existing staff records.
     *
     * @param  array<string, mixed>  $filters
     */
    public function driverSummary(array $filters): LengthAwarePaginator
    {
        $query = TransportDriver::query()
            ->with('faculty:id,first_name,middle_name,last_name,employee_code')
            ->when(in_array($filters['status'] ?? null, TransportDriver::STATUSES, true), fn (Builder $q) => $q->where('status', $filters['status']))
            ->when($filters['from'] ?? null, fn (Builder $q, $value) => $q->whereDate('license_expiry', '>=', $value))
            ->when($filters['to'] ?? null, fn (Builder $q, $value) => $q->whereDate('license_expiry', '<=', $value))
            ->orderBy('license_number')
            ->orderBy('id');

        return $query->paginate(15)->withQueryString();
    }

    /**
     * Report 5 — Route Summary: routes with live stop and assignment counts.
     *
     * @param  array<string, mixed>  $filters
     */
    public function routeSummary(array $filters): array
    {
        return TransportRoute::query()
            ->withCount([
                'stops',
                'stops as active_stops_count' => fn (Builder $q) => $q->where('status', 'active'),
            ])
            ->when($filters['route_id'] ?? null, fn (Builder $q, $value) => $q->whereKey($value))
            ->when(in_array($filters['status'] ?? null, TransportRoute::STATUSES, true), fn (Builder $q) => $q->where('status', $filters['status']))
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->map(fn (TransportRoute $route) => [
                'route' => $route,
                'students' => $this->assignmentCount(['route_id' => $route->id, 'status' => 'active']),
            ])
            ->all();
    }

    /**
     * Report 6 — Stop-wise Student Count: per stop, how many students ride.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array{stop: TransportStop, active_students: int, total_assignments: int}>
     */
    public function stopWiseStudentCount(array $filters): array
    {
        $stops = TransportStop::query()
            ->with('route:id,name,code')
            ->when($filters['route_id'] ?? null, fn (Builder $q, $value) => $q->where('route_id', $value))
            ->when($filters['stop_id'] ?? null, fn (Builder $q, $value) => $q->whereKey($value))
            ->when(in_array($filters['status'] ?? null, TransportStop::STATUSES, true), fn (Builder $q) => $q->where('status', $filters['status']))
            ->orderBy('route_id')
            ->orderBy('sequence')
            ->orderBy('id')
            ->get();

        return $stops->map(fn (TransportStop $stop) => [
            'stop' => $stop,
            'active_students' => $this->assignmentCount(['stop_id' => $stop->id, 'status' => 'active']),
            'total_assignments' => $this->assignmentCount(['stop_id' => $stop->id]),
        ])->all();
    }

    /**
     * Report 7 — Student Transport Assignment Report (filterable, paginated).
     *
     * @param  array<string, mixed>  $filters
     */
    public function studentAssignmentReport(array $filters): LengthAwarePaginator
    {
        return $this->assignmentQuery($filters)
            ->with([
                'studentEnrollment.student:id,first_name,middle_name,last_name,student_number',
                'studentEnrollment:id,student_id,enrollment_number,academic_year_id,program_id',
                'academicYear:id,name',
                'transportRoute:id,name,code',
                'transportStop:id,name,code,route_id,sequence',
            ])
            ->paginate(15)
            ->withQueryString();
    }

    /**
     * Report 10 — Active / Inactive Transport Assignments: counts per status
     * plus the filtered row list (status=active|inactive, where inactive means
     * completed or cancelled).
     *
     * @param  array<string, mixed>  $filters
     * @return array{counts: array<string, int>, rows: LengthAwarePaginator}
     */
    public function activeInactiveAssignments(array $filters): array
    {
        $counts = StudentTransportAssignment::query()
            ->reorder()
            ->toBase()
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->orderBy('status')
            ->pluck('total', 'status')
            ->map(fn ($value) => (int) $value)
            ->all();

        $rows = $this->assignmentQuery($filters)
            ->with([
                'studentEnrollment.student:id,first_name,middle_name,last_name,student_number',
                'academicYear:id,name',
                'transportRoute:id,name,code',
                'transportStop:id,name,code,route_id',
            ])
            ->when(($filters['status'] ?? null) === 'active', fn (Builder $q) => $q->where('status', StudentTransportAssignment::STATUS_ACTIVE))
            ->when(($filters['status'] ?? null) === 'inactive', fn (Builder $q) => $q->whereIn('status', [
                StudentTransportAssignment::STATUS_COMPLETED,
                StudentTransportAssignment::STATUS_CANCELLED,
            ]))
            ->paginate(15)
            ->withQueryString();

        return ['counts' => $counts, 'rows' => $rows];
    }

    /**
     * Report 8 — Transport Fee Summary: every fee assignment with its live
     * ledger (assigned / collected / outstanding) from the shared Finance rows.
     *
     * @param  array<string, mixed>  $filters
     * @return array{rows: LengthAwarePaginator, totals: array<string, mixed>}
     */
    public function transportFeeSummary(array $filters): array
    {
        $query = $this->feeQuery($filters);
        $rows = $query->clone()
            ->with([
                'studentTransportAssignment.studentEnrollment.student:id,first_name,middle_name,last_name,student_number',
                'studentTransportAssignment:id,student_enrollment_id,transport_route_id,transport_stop_id',
                'transportFeeStructure:id,name,code',
                'academicYear:id,name',
            ])
            ->paginate(15)
            ->withQueryString();

        $ledger = $this->fees->ledgerFor($rows->getCollection());
        foreach ($rows->getCollection() as $assignment) {
            $assignment->setAttribute('ledger', $ledger[$assignment->getKey()] ?? null);
        }

        return ['rows' => $rows, 'totals' => $this->feeTotals($filters)];
    }

    /**
     * Report 9 — Transport Fee Outstanding Summary: the full filtered set's
     * money totals plus every assignment still carrying a balance, filtered in
     * SQL so pagination stays honest.
     *
     * @param  array<string, mixed>  $filters
     * @return array{totals: array<string, mixed>, rows: LengthAwarePaginator}
     */
    public function transportFeeOutstandingSummary(array $filters): array
    {
        $rows = $this->outstandingQuery($filters)
            ->with([
                'studentTransportAssignment.studentEnrollment.student:id,first_name,middle_name,last_name,student_number',
                'academicYear:id,name',
            ])
            ->paginate(15)
            ->withQueryString();

        $ledger = $this->fees->ledgerFor($rows->getCollection());
        $rows->getCollection()->each(function (StudentTransportFeeAssignment $assignment) use ($ledger): void {
            $assignment->setAttribute('ledger', $ledger[$assignment->getKey()] ?? null);
        });

        return ['totals' => $this->feeTotals($filters), 'rows' => $rows];
    }

    // ------------------------------------------------------------- helpers

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
            ->when(in_array($filters['status'] ?? null, StudentTransportAssignment::STATUSES, true), fn (Builder $q) => $q->where('status', $filters['status']))
            ->when($filters['from'] ?? null, fn (Builder $q, $value) => $q->whereDate('start_date', '>=', $value))
            ->when($filters['to'] ?? null, fn (Builder $q, $value) => $q->whereDate('start_date', '<=', $value))
            // Deterministic pagination order.
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
            ->when(in_array($filters['status'] ?? null, StudentTransportFeeAssignment::STATUSES, true), fn (Builder $q) => $q->where('status', $filters['status']))
            ->when($filters['from'] ?? null, fn (Builder $q, $value) => $q->whereDate('effective_from', '>=', $value))
            ->when($filters['to'] ?? null, fn (Builder $q, $value) => $q->whereDate('effective_from', '<=', $value))
            ->orderByDesc('id');
    }

    /**
     * The fee query restricted to assignments with a positive derived balance.
     *
     * The same formula as FeeLedger::outstanding() (transport has no
     * concessions), expressed in portable SQL over the pre-aggregated Finance
     * totals so the page count and the rows can never disagree with the ledger.
     *
     * @param  array<string, mixed>  $filters
     */
    private function outstandingQuery(array $filters): Builder
    {
        $collegeId = app(\App\Support\Tenancy\TenantContext::class)->id();

        $payments = DB::table('fee_payments')
            ->when($collegeId !== null, fn ($q) => $q->where('college_id', $collegeId))
            ->whereNull('deleted_at')
            ->where('status', \App\Models\FeePayment::STATUS_COMPLETED)
            ->groupBy('transport_fee_assignment_id')
            ->selectRaw('transport_fee_assignment_id as assignment_id, SUM(amount) as total');

        $refunds = DB::table('fee_refunds')
            ->join('fee_payments', 'fee_payments.id', '=', 'fee_refunds.fee_payment_id')
            ->when($collegeId !== null, fn ($q) => $q->where('fee_refunds.college_id', $collegeId)->where('fee_payments.college_id', $collegeId))
            ->whereNull('fee_payments.deleted_at')
            ->where('fee_payments.status', \App\Models\FeePayment::STATUS_COMPLETED)
            ->whereNotIn('fee_refunds.status', \App\Models\FeeRefund::INVALID_STATUSES)
            ->groupBy('fee_payments.transport_fee_assignment_id')
            ->selectRaw('fee_payments.transport_fee_assignment_id as assignment_id, SUM(fee_refunds.amount) as total');

        return $this->feeQuery($filters)
            ->select('student_transport_fee_assignments.*')
            ->leftJoinSub($payments, 'ledger_payments', 'ledger_payments.assignment_id', '=', 'student_transport_fee_assignments.id')
            ->leftJoinSub($refunds, 'ledger_refunds', 'ledger_refunds.assignment_id', '=', 'student_transport_fee_assignments.id')
            ->whereRaw('(student_transport_fee_assignments.amount - COALESCE(ledger_payments.total, 0) + COALESCE(ledger_refunds.total, 0)) > 0');
    }

    /**
     * Money totals across the whole filtered set (not just the page), computed
     * from the same ledger derivation in bounded batches.
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

    /** @param  array<string, mixed>  $filters */
    private function assignmentCount(array $filters): int
    {
        return (int) StudentTransportAssignment::query()
            ->when($filters['route_id'] ?? null, fn (Builder $q, $value) => $q->where('transport_route_id', $value))
            ->when($filters['stop_id'] ?? null, fn (Builder $q, $value) => $q->where('transport_stop_id', $value))
            ->when(($filters['status'] ?? null) !== null, fn (Builder $q) => $q->where('status', $filters['status']))
            ->count();
    }
}
