<?php

namespace App\Domain\Hostel\Services;

use App\Domain\Finance\Support\FeeLedger;
use App\Models\Hostel;
use App\Models\HostelAllocation;
use App\Models\HostelAttendance;
use App\Models\HostelBed;
use App\Models\HostelBuilding;
use App\Models\HostelFeeAssignment;
use App\Models\HostelRoom;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read-only Hostel reporting over the existing Hostel, Student and Finance data.
 *
 * No report rows are persisted. HostelAllocation remains the source of truth
 * for bed occupancy and HostelFeeService::ledgerFor() / FeeLedger remain the
 * source of truth for assigned, paid, refunded and outstanding fee amounts.
 *
 * Eloquent queries retain CollegeScope and SoftDeletes. The joined attendance
 * and vacated-student aggregates explicitly constrain every tenant-owned row,
 * including tenant and soft-delete columns.
 */
class HostelReportService
{
    private const PER_PAGE = 15;

    private const FEE_CHUNK_SIZE = 200;

    public function __construct(
        private readonly HostelFeeService $fees,
        private readonly HostelDashboardService $dashboard,
    ) {
    }

    /**
     * Hostel and building/block registers with live, scoped child counts.
     * Each register has its own paginator so neither list is loaded in full.
     *
     * @param  array<string, mixed>  $filters
     * @return array{hostels: LengthAwarePaginator, buildings: LengthAwarePaginator}
     */
    public function hostelBuildingReport(array $filters): array
    {
        $this->collegeId();

        $hostels = $this->hostelQuery($filters)
            ->withCount(['buildings', 'rooms', 'beds'])
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(self::PER_PAGE, ['*'], 'hostels_page')
            ->withQueryString();

        $buildings = $this->buildingQuery($filters)
            ->with('hostel:id,name,code')
            ->withCount(['rooms', 'beds'])
            ->orderBy('hostel_id')
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(self::PER_PAGE, ['*'], 'buildings_page')
            ->withQueryString();

        return compact('hostels', 'buildings');
    }

    /**
     * Room and bed occupancy. Room/bed totals are grouped in SQL, while the
     * resident count uses live non-cancelled HostelAllocation rows (the
     * existing occupancy source of truth), not a second status calculation.
     *
     * @param  array<string, mixed>  $filters
     * @return array{summary: array<string, int|float|null>, rooms: LengthAwarePaginator, beds: LengthAwarePaginator}
     */
    public function occupancy(array $filters): array
    {
        $this->collegeId();

        $bedTotals = $this->bedQuery($filters)
            ->reorder()
            ->toBase()
            ->selectRaw('COUNT(*) as total_beds')
            ->selectRaw(
                'SUM(CASE WHEN hostel_beds.status = ? THEN 1 ELSE 0 END) as inactive_beds',
                [HostelBed::STATUS_INACTIVE]
            )
            ->first();
        $totalBeds = (int) ($bedTotals->total_beds ?? 0);
        $totalInactive = (int) ($bedTotals->inactive_beds ?? 0);
        $totalOccupied = (int) $this->residencyQuery($filters)
            ->whereIn('hostel_allocations.hostel_bed_id', $this->bedQuery($filters)->select('hostel_beds.id'))
            ->reorder()
            ->toBase()
            ->selectRaw('COUNT(DISTINCT hostel_allocations.hostel_bed_id) as occupied_beds')
            ->value('occupied_beds');

        $rooms = $this->roomQuery($filters)
            ->with(['hostel:id,name,code', 'building:id,hostel_id,name,code'])
            ->orderBy('hostel_id')
            ->orderBy('building_id')
            ->orderBy('room_number')
            ->orderBy('id')
            ->paginate(self::PER_PAGE, ['*'], 'rooms_page')
            ->withQueryString();

        $roomIds = $rooms->getCollection()->pluck('id')->all();
        $bedCountsByRoom = $roomIds === []
            ? collect()
            : $this->bedQuery($filters)
                ->whereIn('hostel_beds.room_id', $roomIds)
                ->reorder()
                ->toBase()
                ->select('hostel_beds.room_id')
                ->selectRaw('COUNT(*) as total_beds')
                ->selectRaw(
                    'SUM(CASE WHEN hostel_beds.status = ? THEN 1 ELSE 0 END) as inactive_beds',
                    [HostelBed::STATUS_INACTIVE]
                )
                ->groupBy('hostel_beds.room_id')
                ->get()
                ->keyBy('room_id');
        $occupiedByRoom = $roomIds === []
            ? collect()
            : $this->residencyQuery($filters)
                ->whereIn('hostel_allocations.hostel_room_id', $roomIds)
                ->whereIn('hostel_allocations.hostel_bed_id', $this->bedQuery($filters)->select('hostel_beds.id'))
                ->reorder()
                ->toBase()
                ->select('hostel_allocations.hostel_room_id')
                ->selectRaw('COUNT(DISTINCT hostel_allocations.hostel_bed_id) as occupied_beds')
                ->groupBy('hostel_allocations.hostel_room_id')
                ->pluck('occupied_beds', 'hostel_room_id');

        foreach ($rooms as $room) {
            $bedCounts = $bedCountsByRoom->get($room->id);
            $counts = [
                'beds' => (int) ($bedCounts->total_beds ?? 0),
                'inactive' => (int) ($bedCounts->inactive_beds ?? 0),
                'occupied' => (int) ($occupiedByRoom[$room->id] ?? 0),
            ];
            $usable = max(0, $counts['beds'] - $counts['inactive']);
            $available = max(0, $usable - $counts['occupied']);

            $room->setAttribute('total_beds_count', $counts['beds']);
            $room->setAttribute('occupied_beds_count', $counts['occupied']);
            $room->setAttribute('available_beds_count', $available);
            $room->setAttribute('inactive_beds_count', $counts['inactive']);
            $room->setAttribute('occupancy_status', $this->occupancyStatus($counts['occupied'], $usable));
        }

        $beds = $this->bedQuery($filters)
            ->with([
                'hostel:id,name,code',
                'building:id,hostel_id,name,code',
                'room:id,hostel_id,building_id,room_number,capacity',
            ])
            ->orderBy('hostel_id')
            ->orderBy('building_id')
            ->orderBy('room_id')
            ->orderBy('bed_number')
            ->orderBy('id')
            ->paginate(self::PER_PAGE, ['*'], 'beds_page')
            ->withQueryString();

        $pageBedIds = $beds->getCollection()->pluck('id')->all();
        $allocationByBed = $pageBedIds === []
            ? collect()
            : $this->residencyQuery($filters)
                ->whereIn('hostel_allocations.hostel_bed_id', $pageBedIds)
                ->whereHas('studentEnrollment.student')
                ->with([
                    'studentEnrollment.student:id,first_name,middle_name,last_name,student_number',
                    'studentEnrollment:id,student_id,enrollment_number',
                ])
                ->orderByDesc('allocation_date')
                ->orderByDesc('id')
                ->get()
                ->groupBy('hostel_bed_id')
                ->map(fn (Collection $allocations) => $allocations->first());

        foreach ($beds as $bed) {
            $allocation = $allocationByBed->get($bed->id);
            $bed->setRelation('occupancyAllocation', $allocation);
            $bed->setAttribute('occupancy_status', $allocation
                ? 'Occupied'
                : ($bed->status === HostelBed::STATUS_INACTIVE ? 'Inactive' : 'Available'));
        }

        $usableBeds = max(0, $totalBeds - $totalInactive);

        return [
            'summary' => [
                'beds' => $totalBeds,
                'occupied' => $totalOccupied,
                'available' => max(0, $usableBeds - $totalOccupied),
                'inactive_beds' => $totalInactive,
                'occupancy_percentage' => $this->percentage($totalOccupied, $totalBeds),
            ],
            'rooms' => $rooms,
            'beds' => $beds,
        ];
    }

    /**
     * Full allocation register, including active, vacated and cancelled rows.
     *
     * @param  array<string, mixed>  $filters
     * @return array{counts: array<string, int>, rows: LengthAwarePaginator}
     */
    public function allocations(array $filters): array
    {
        $query = $this->allocationQuery($filters)
            ->whereHas('studentEnrollment.student');
        $this->applyDateRange($query, $filters, 'hostel_allocations.allocation_date');

        $statusCounts = (clone $query)
            ->reorder()
            ->toBase()
            ->select('hostel_allocations.status')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('hostel_allocations.status')
            ->pluck('total', 'status');

        $rows = $query
            ->with($this->allocationRelations())
            ->orderByDesc('allocation_date')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $active = (int) ($statusCounts[HostelAllocation::STATUS_ACTIVE] ?? 0);
        $vacated = (int) ($statusCounts[HostelAllocation::STATUS_VACATED] ?? 0);
        $cancelled = (int) ($statusCounts[HostelAllocation::STATUS_CANCELLED] ?? 0);

        return [
            'counts' => [
                'active' => $active,
                'vacated' => $vacated,
                'cancelled' => $cancelled,
                'total' => $active + $vacated + $cancelled,
            ],
            'rows' => $rows,
        ];
    }

    /**
     * Attendance summary and paginated attendance register. The status filter
     * narrows only the register; summary totals remain complete for the
     * selected tenant/location/student/date filters.
     *
     * @param  array<string, mixed>  $filters
     * @return array{summary: array<string, int|float|null>, hostels: array<int, array<string, mixed>>, rows: LengthAwarePaginator}
     */
    public function attendance(array $filters): array
    {
        $groups = $this->attendanceAggregate($filters)
            ->select('hostel_allocations.hostel_id', 'hostel_attendances.attendance_status')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('hostel_allocations.hostel_id', 'hostel_attendances.attendance_status')
            ->get();

        $summary = $this->emptyAttendanceCounts();
        $byHostel = [];

        foreach ($groups as $row) {
            $status = (string) $row->attendance_status;
            if (! array_key_exists($status, $summary)) {
                continue;
            }

            $total = (int) $row->total;
            $hostelId = (int) $row->hostel_id;
            $summary[$status] += $total;
            $byHostel[$hostelId][$status] = ($byHostel[$hostelId][$status] ?? 0) + $total;
        }

        $hostelNames = Hostel::query()
            ->whereIn('id', array_keys($byHostel))
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'code'])
            ->keyBy('id');

        $hostelRows = [];
        foreach ($hostelNames as $hostel) {
            $present = (int) ($byHostel[$hostel->id][HostelAttendance::STATUS_PRESENT] ?? 0);
            $absent = (int) ($byHostel[$hostel->id][HostelAttendance::STATUS_ABSENT] ?? 0);
            $leave = (int) ($byHostel[$hostel->id][HostelAttendance::STATUS_LEAVE] ?? 0);
            $total = $present + $absent + $leave;
            $hostelRows[] = [
                'hostel' => $hostel,
                'present' => $present,
                'absent' => $absent,
                'leave' => $leave,
                'total' => $total,
                'attendance_percentage' => $this->percentage($present, $total),
            ];
        }

        $rows = $this->attendanceQuery($filters)
            ->with([
                'studentEnrollment.student:id,first_name,middle_name,last_name,student_number',
                'studentEnrollment:id,student_id,enrollment_number,academic_year_id,program_id,section_id',
                'studentEnrollment.academicYear:id,name,code',
                'studentEnrollment.program:id,name,code',
                'studentEnrollment.section:id,name,code',
                'allocation.hostel:id,name,code',
                'allocation.building:id,name,code',
                'allocation.room:id,room_number',
                'allocation.bed:id,bed_number',
                'allocation:id,student_enrollment_id,academic_year_id,hostel_id,hostel_building_id,hostel_room_id,hostel_bed_id,status',
            ])
            ->orderByDesc('attendance_date')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $marked = array_sum($summary);

        return [
            'summary' => [
                'present' => $summary[HostelAttendance::STATUS_PRESENT],
                'absent' => $summary[HostelAttendance::STATUS_ABSENT],
                'leave' => $summary[HostelAttendance::STATUS_LEAVE],
                'total' => $marked,
                'attendance_percentage' => $this->percentage($summary[HostelAttendance::STATUS_PRESENT], $marked),
            ],
            'hostels' => $hostelRows,
            'rows' => $rows,
        ];
    }

    /**
     * Hostel fee report. Financial columns and the paid/due ledger status come
     * only from HostelFeeService::ledgerFor() and its shared FeeLedger math.
     * Fee date filters are an overlap with the existing assignment effective
     * period; this report does not invent a dated payment balance.
     *
     * @param  array<string, mixed>  $filters
     * @return array{totals: array<string, int|float|string|null>, hostels: array<int, array<string, mixed>>, rows: LengthAwarePaginator}
     */
    public function fees(array $filters): array
    {
        return $this->feeReportData($filters, true);
    }

    /**
     * Vacated allocation history, with vacated-date filtering and its existing
     * remarks field shown as the recorded note/reason when present.
     *
     * @param  array<string, mixed>  $filters
     * @return array{count: int, rows: LengthAwarePaginator}
     */
    public function vacated(array $filters): array
    {
        $query = $this->allocationQuery($filters)
            ->where('hostel_allocations.status', HostelAllocation::STATUS_VACATED)
            ->whereHas('studentEnrollment.student');
        $this->applyDateRange($query, $filters, 'hostel_allocations.vacated_date');

        $count = (clone $query)->count();
        $rows = $query
            ->with($this->allocationRelations())
            ->orderByDesc('vacated_date')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return compact('count', 'rows');
    }

    /**
     * College-wide read-only overview, using the existing Hostel dashboard
     * counters and live attendance / finance sources.
     *
     * @return array{summary: array<string, mixed>}
     */
    public function summary(): array
    {
        $dashboard = $this->dashboard->totals();
        $attendance = $this->attendanceCounts([]);
        $marked = array_sum($attendance);
        $fees = $this->feeReportData([], false)['totals'];

        return [
            'summary' => [
                'total_hostels' => $dashboard['hostels'],
                'total_buildings' => $dashboard['buildings'],
                'total_rooms' => $dashboard['rooms'],
                'total_beds' => $dashboard['beds'],
                'occupied_beds' => $dashboard['occupied_beds'],
                'available_beds' => $dashboard['available_beds'],
                'inactive_beds' => $dashboard['inactive_beds'],
                'active_allocations' => $dashboard['active_allocations'],
                'vacated_students' => $this->vacatedStudentCount(),
                'attendance' => [
                    'present' => $attendance[HostelAttendance::STATUS_PRESENT],
                    'absent' => $attendance[HostelAttendance::STATUS_ABSENT],
                    'leave' => $attendance[HostelAttendance::STATUS_LEAVE],
                    'total' => $marked,
                    'attendance_percentage' => $this->percentage($attendance[HostelAttendance::STATUS_PRESENT], $marked),
                ],
                'fees' => $fees,
            ],
        ];
    }

    /** @param  array<string, mixed>  $filters */
    private function hostelQuery(array $filters): Builder
    {
        return Hostel::query()
            ->when($filters['hostel_id'] ?? null, fn (Builder $query, $id) => $query->whereKey($id))
            ->when($filters['hostel_building_id'] ?? null, fn (Builder $query, $id) => $query->whereHas(
                'buildings',
                fn (Builder $buildings) => $buildings->whereKey($id)
            ));
    }

    /** @param  array<string, mixed>  $filters */
    private function buildingQuery(array $filters): Builder
    {
        return HostelBuilding::query()
            ->when($filters['hostel_id'] ?? null, fn (Builder $query, $id) => $query->where('hostel_id', $id))
            ->when($filters['hostel_building_id'] ?? null, fn (Builder $query, $id) => $query->whereKey($id));
    }

    /** @param  array<string, mixed>  $filters */
    private function roomQuery(array $filters): Builder
    {
        return HostelRoom::query()
            ->when($filters['hostel_id'] ?? null, fn (Builder $query, $id) => $query->where('hostel_id', $id))
            ->when($filters['hostel_building_id'] ?? null, fn (Builder $query, $id) => $query->where('building_id', $id))
            ->when($filters['hostel_room_id'] ?? null, fn (Builder $query, $id) => $query->whereKey($id))
            ->when($filters['hostel_bed_id'] ?? null, fn (Builder $query, $id) => $query->whereHas(
                'beds',
                fn (Builder $beds) => $beds->whereKey($id)
            ));
    }

    /** @param  array<string, mixed>  $filters */
    private function bedQuery(array $filters): Builder
    {
        return HostelBed::query()
            ->when($filters['hostel_id'] ?? null, fn (Builder $query, $id) => $query->where('hostel_beds.hostel_id', $id))
            ->when($filters['hostel_building_id'] ?? null, fn (Builder $query, $id) => $query->where('hostel_beds.building_id', $id))
            ->when($filters['hostel_room_id'] ?? null, fn (Builder $query, $id) => $query->where('hostel_beds.room_id', $id))
            ->when($filters['hostel_bed_id'] ?? null, fn (Builder $query, $id) => $query->whereKey($id));
    }

    /** @param  array<string, mixed>  $filters */
    private function residencyQuery(array $filters): Builder
    {
        $query = HostelAllocation::query()
            ->where('hostel_allocations.status', '!=', HostelAllocation::STATUS_CANCELLED)
            ->whereHas('studentEnrollment.student');
        $this->constrainAllocations($query, $filters);

        if ($this->hasDateWindow($filters)) {
            $from = $filters['from'] ?? null;
            $to = $filters['to'] ?? now()->toDateString();
            $query->whereDate('hostel_allocations.allocation_date', '<=', $to)
                ->where(function (Builder $inner) use ($from) {
                    $inner->where(function (Builder $active) {
                        $active->where('hostel_allocations.status', HostelAllocation::STATUS_ACTIVE)
                            ->whereNull('hostel_allocations.vacated_date');
                    })->orWhere(function (Builder $vacated) use ($from) {
                        $vacated->where('hostel_allocations.status', HostelAllocation::STATUS_VACATED)
                            ->whereNotNull('hostel_allocations.vacated_date')
                            ->when($from, fn (Builder $query) => $query->whereDate('hostel_allocations.vacated_date', '>=', $from));
                    });
                });
        } else {
            $query->where('hostel_allocations.status', HostelAllocation::STATUS_ACTIVE)
                ->whereNull('hostel_allocations.vacated_date');
        }

        return $query;
    }

    /** @param  array<string, mixed>  $filters */
    private function allocationQuery(array $filters): Builder
    {
        $query = HostelAllocation::query();
        $this->constrainAllocations($query, $filters);

        if ($filters['allocation_status'] ?? null) {
            $query->where('hostel_allocations.status', $filters['allocation_status']);
        }

        return $query;
    }

    /** @param  array<string, mixed>  $filters */
    private function attendanceQuery(array $filters): Builder
    {
        $query = HostelAttendance::query()
            ->whereHas('studentEnrollment.student')
            ->whereHas('allocation.hostel');

        if ($filters['attendance_status'] ?? null) {
            $query->where('hostel_attendances.attendance_status', $filters['attendance_status']);
        }
        $this->applyDateRange($query, $filters, 'hostel_attendances.attendance_date');
        $query->whereHas('allocation', fn (Builder $allocation) => $this->constrainAllocations($allocation, $filters));

        return $query;
    }

    /**
     * Query-builder attendance aggregate. Every joined/source row is explicitly
     * scoped by college_id and deleted_at because Eloquent scopes cannot safely
     * qualify an unaliased multi-table join after it is built.
     *
     * @param  array<string, mixed>  $filters
     */
    private function attendanceAggregate(array $filters): QueryBuilder
    {
        $collegeId = $this->collegeId();

        $query = DB::table('hostel_attendances')
            ->join('hostel_allocations', function ($join) use ($collegeId): void {
                $join->on('hostel_allocations.id', '=', 'hostel_attendances.hostel_allocation_id')
                    ->where('hostel_allocations.college_id', $collegeId)
                    ->whereNull('hostel_allocations.deleted_at');
            })
            ->where('hostel_attendances.college_id', $collegeId)
            ->whereNull('hostel_attendances.deleted_at')
            ->whereExists(function (QueryBuilder $hostels) use ($collegeId): void {
                $hostels->selectRaw('1')
                    ->from('hostels')
                    ->whereColumn('hostels.id', 'hostel_allocations.hostel_id')
                    ->where('hostels.college_id', $collegeId)
                    ->whereNull('hostels.deleted_at');
            });

        $this->applyDateRange($query, $filters, 'hostel_attendances.attendance_date');
        $this->constrainJoinedAllocations($query, $filters, $collegeId);

        return $query;
    }

    /** @param  array<string, mixed>  $filters */
    private function feeQuery(array $filters): Builder
    {
        return HostelFeeAssignment::query()
            ->whereIn('hostel_fee_assignments.status', HostelFeeAssignment::PAYABLE_STATUSES)
            ->when($filters['academic_year_id'] ?? null, fn (Builder $query, $id) => $query->where('hostel_fee_assignments.academic_year_id', $id))
            ->when($filters['from'] ?? null, function (Builder $query, $from): void {
                $query->where(function (Builder $inner) use ($from): void {
                    $inner->whereNull('hostel_fee_assignments.effective_until')
                        ->orWhereDate('hostel_fee_assignments.effective_until', '>=', $from);
                });
            })
            ->when($filters['to'] ?? null, fn (Builder $query, $to) => $query->whereDate('hostel_fee_assignments.effective_from', '<=', $to))
            ->whereHas('hostelAllocation', function (Builder $allocation) use ($filters): void {
                $this->constrainAllocations($allocation, $filters);
                $allocation->whereHas('studentEnrollment.student')
                    ->whereHas('hostel');
            });
    }

    /**
     * Aggregated hostel fee ledger and (for the report page) a bounded manual
     * paginator. Scanning assignments in fixed-size chunks lets the fee-status
     * filter use the authoritative FeeLedger status without materializing a
     * huge list of IDs or duplicating ledger arithmetic in SQL.
     *
     * @param  array<string, mixed>  $filters
     * @return array{totals: array<string, int|float|string|null>, hostels: array<int, array<string, mixed>>, rows: LengthAwarePaginator}
     */
    private function feeReportData(array $filters, bool $withRows): array
    {
        $totals = [
            'assigned' => 0.0,
            'paid' => 0.0,
            'refunded' => 0.0,
            'net_collected' => 0.0,
            'outstanding' => 0.0,
            'assignments' => 0,
            'outstanding_assignments' => 0,
        ];
        $byHostel = [];
        $pageItems = collect();
        $matchingCount = 0;
        $page = $withRows ? Paginator::resolveCurrentPage('page') : 1;
        $offset = ($page - 1) * self::PER_PAGE;

        $relations = $withRows
            ? [
                'hostelAllocation.studentEnrollment.student:id,first_name,middle_name,last_name,student_number',
                'hostelAllocation.studentEnrollment:id,student_id,enrollment_number,academic_year_id,program_id,section_id',
                'hostelAllocation.studentEnrollment.program:id,name,code',
                'hostelAllocation.studentEnrollment.section:id,name,code',
                'hostelAllocation.hostel:id,name,code',
                'hostelAllocation.building:id,name,code',
                'hostelAllocation.room:id,room_number',
                'hostelAllocation.bed:id,bed_number',
                'hostelAllocation:id,student_enrollment_id,academic_year_id,hostel_id,hostel_building_id,hostel_room_id,hostel_bed_id',
                'feeStructure:id,name,code',
                'academicYear:id,name,code',
            ]
            : ['hostelAllocation:id,hostel_id'];

        $query = $this->feeQuery($filters)->with($relations)->reorder();
        $query->chunkByIdDesc(self::FEE_CHUNK_SIZE, function (Collection $chunk) use (
            &$totals,
            &$byHostel,
            &$pageItems,
            &$matchingCount,
            $filters,
            $withRows,
            $offset,
        ): void {
            $ledgerRows = $this->fees->ledgerFor($chunk);

            foreach ($chunk as $assignment) {
                $ledger = $ledgerRows[$assignment->getKey()] ?? null;
                if ($ledger === null) {
                    continue;
                }
                if (($filters['fee_status'] ?? null) && $ledger['status'] !== $filters['fee_status']) {
                    continue;
                }

                $position = $matchingCount++;
                $totals['assigned'] += $ledger['assigned'];
                $totals['paid'] += $ledger['paid'];
                $totals['refunded'] += $ledger['refunded'];
                $totals['net_collected'] += $ledger['net_collected'];
                $totals['outstanding'] += $ledger['outstanding'];
                $totals['assignments']++;
                if ($ledger['outstanding'] > FeeLedger::TOLERANCE) {
                    $totals['outstanding_assignments']++;
                }

                $hostelId = (int) ($assignment->hostelAllocation?->hostel_id ?? 0);
                if (! isset($byHostel[$hostelId])) {
                    $byHostel[$hostelId] = [
                        'assigned' => 0.0,
                        'paid' => 0.0,
                        'refunded' => 0.0,
                        'net_collected' => 0.0,
                        'outstanding' => 0.0,
                        'assignments' => 0,
                    ];
                }
                $byHostel[$hostelId]['assigned'] += $ledger['assigned'];
                $byHostel[$hostelId]['paid'] += $ledger['paid'];
                $byHostel[$hostelId]['refunded'] += $ledger['refunded'];
                $byHostel[$hostelId]['net_collected'] += $ledger['net_collected'];
                $byHostel[$hostelId]['outstanding'] += $ledger['outstanding'];
                $byHostel[$hostelId]['assignments']++;

                if ($withRows && $position >= $offset && $position < $offset + self::PER_PAGE) {
                    $assignment->setAttribute('ledger', $ledger);
                    $pageItems->push($assignment);
                }
            }
        }, 'hostel_fee_assignments.id', 'id');

        foreach (['assigned', 'paid', 'refunded', 'net_collected', 'outstanding'] as $key) {
            $totals[$key] = FeeLedger::money($totals[$key]);
        }
        $totals['status'] = $totals['assignments'] === 0
            ? null
            : FeeLedger::status($totals['assigned'], 0.0, $totals['paid'], $totals['refunded']);

        $hostelNames = Hostel::query()
            ->whereIn('id', array_keys($byHostel))
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'code']);

        $hostels = $hostelNames->map(function (Hostel $hostel) use ($byHostel): array {
            $row = $byHostel[$hostel->id];

            return [
                'hostel' => $hostel,
                'assigned' => FeeLedger::money($row['assigned']),
                'paid' => FeeLedger::money($row['paid']),
                'refunded' => FeeLedger::money($row['refunded']),
                'net_collected' => FeeLedger::money($row['net_collected']),
                'outstanding' => FeeLedger::money($row['outstanding']),
                'assignments' => $row['assignments'],
            ];
        })->all();

        $rows = new LengthAwarePaginator(
            $pageItems,
            $matchingCount,
            self::PER_PAGE,
            $page,
            [
                'path' => Paginator::resolveCurrentPath(),
                'pageName' => 'page',
            ]
        );
        $rows->withQueryString();

        return compact('totals', 'hostels', 'rows');
    }

    /** @param  array<string, mixed>  $filters */
    private function constrainAllocations(Builder $query, array $filters): void
    {
        $collegeId = $this->collegeId();

        $query
            ->when($filters['hostel_id'] ?? null, fn (Builder $inner, $id) => $inner->where('hostel_allocations.hostel_id', $id))
            ->when($filters['hostel_building_id'] ?? null, fn (Builder $inner, $id) => $inner->where('hostel_allocations.hostel_building_id', $id))
            ->when($filters['hostel_room_id'] ?? null, fn (Builder $inner, $id) => $inner->where('hostel_allocations.hostel_room_id', $id))
            ->when($filters['hostel_bed_id'] ?? null, fn (Builder $inner, $id) => $inner->where('hostel_allocations.hostel_bed_id', $id))
            ->when($filters['academic_year_id'] ?? null, fn (Builder $inner, $id) => $inner->where('hostel_allocations.academic_year_id', $id))
            ->when($filters['student_id'] ?? null, fn (Builder $inner, $id) => $inner->whereHas(
                'studentEnrollment',
                fn (Builder $enrollment) => $enrollment->where('student_id', $id)
            ))
            ->when($filters['program_id'] ?? null, fn (Builder $inner, $id) => $inner->whereHas(
                'studentEnrollment',
                fn (Builder $enrollment) => $enrollment->where('program_id', $id)
            ))
            ->when($filters['section_id'] ?? null, fn (Builder $inner, $id) => $inner->whereHas(
                'studentEnrollment',
                fn (Builder $enrollment) => $enrollment->where('section_id', $id)
            ))
            ->when($filters['academic_term_id'] ?? null, function (Builder $inner, $termId) use ($collegeId, $filters): void {
                $inner->whereExists(function ($subquery) use ($termId, $collegeId, $filters): void {
                    $subquery->selectRaw('1')
                        ->from('student_academic_records')
                        ->whereColumn('student_academic_records.enrollment_id', 'hostel_allocations.student_enrollment_id')
                        ->where('student_academic_records.college_id', $collegeId)
                        ->where('student_academic_records.academic_term_id', $termId)
                        ->whereNull('student_academic_records.deleted_at')
                        ->when(
                            $filters['academic_year_id'] ?? null,
                            fn (QueryBuilder $year) => $year->where('student_academic_records.academic_year_id', $filters['academic_year_id'])
                        )
                        ->whereExists(function (QueryBuilder $terms) use ($collegeId, $termId): void {
                            $terms->selectRaw('1')
                                ->from('academic_terms')
                                ->where('academic_terms.id', $termId)
                                ->where('academic_terms.college_id', $collegeId)
                                ->whereNull('academic_terms.deleted_at');
                        });
                });
            });
    }

    /** @param  array<string, mixed>  $filters */
    private function constrainJoinedAllocations(QueryBuilder $query, array $filters, int $collegeId): void
    {
        $query
            ->when($filters['hostel_id'] ?? null, fn (QueryBuilder $inner, $id) => $inner->where('hostel_allocations.hostel_id', $id))
            ->when($filters['hostel_building_id'] ?? null, fn (QueryBuilder $inner, $id) => $inner->where('hostel_allocations.hostel_building_id', $id))
            ->when($filters['hostel_room_id'] ?? null, fn (QueryBuilder $inner, $id) => $inner->where('hostel_allocations.hostel_room_id', $id))
            ->when($filters['hostel_bed_id'] ?? null, fn (QueryBuilder $inner, $id) => $inner->where('hostel_allocations.hostel_bed_id', $id))
            ->when($filters['academic_year_id'] ?? null, fn (QueryBuilder $inner, $id) => $inner->where('hostel_allocations.academic_year_id', $id))
            ->when($filters['student_id'] ?? null, fn (QueryBuilder $inner, $id) => $inner->whereExists(
                fn (QueryBuilder $enrollments) => $this->liveEnrollmentSubquery($enrollments, $collegeId, ['student_id' => $id])
            ))
            ->when($filters['program_id'] ?? null, fn (QueryBuilder $inner, $id) => $inner->whereExists(
                fn (QueryBuilder $enrollments) => $this->liveEnrollmentSubquery($enrollments, $collegeId, ['program_id' => $id])
            ))
            ->when($filters['section_id'] ?? null, fn (QueryBuilder $inner, $id) => $inner->whereExists(
                fn (QueryBuilder $enrollments) => $this->liveEnrollmentSubquery($enrollments, $collegeId, ['section_id' => $id])
            ))
            ->whereExists(fn (QueryBuilder $enrollments) => $this->liveEnrollmentSubquery($enrollments, $collegeId, []))
            ->when($filters['academic_term_id'] ?? null, function (QueryBuilder $inner, $termId) use ($collegeId, $filters): void {
                $inner->whereExists(function (QueryBuilder $records) use ($termId, $collegeId, $filters): void {
                    $records->selectRaw('1')
                        ->from('student_academic_records')
                        ->whereColumn('student_academic_records.enrollment_id', 'hostel_allocations.student_enrollment_id')
                        ->where('student_academic_records.college_id', $collegeId)
                        ->where('student_academic_records.academic_term_id', $termId)
                        ->whereNull('student_academic_records.deleted_at')
                        ->when(
                            $filters['academic_year_id'] ?? null,
                            fn (QueryBuilder $year) => $year->where('student_academic_records.academic_year_id', $filters['academic_year_id'])
                        )
                        ->whereExists(function (QueryBuilder $terms) use ($collegeId, $termId): void {
                            $terms->selectRaw('1')
                                ->from('academic_terms')
                                ->where('academic_terms.id', $termId)
                                ->where('academic_terms.college_id', $collegeId)
                                ->whereNull('academic_terms.deleted_at');
                        });
                });
            });
    }

    /** @param  array<string, mixed>  $filters */
    private function liveEnrollmentSubquery(QueryBuilder $query, int $collegeId, array $filters): void
    {
        $query->selectRaw('1')
            ->from('student_enrollments')
            ->whereColumn('student_enrollments.id', 'hostel_allocations.student_enrollment_id')
            ->where('student_enrollments.college_id', $collegeId)
            ->whereNull('student_enrollments.deleted_at')
            ->when($filters['student_id'] ?? null, fn (QueryBuilder $enrollment, $id) => $enrollment->where('student_enrollments.student_id', $id))
            ->when($filters['program_id'] ?? null, fn (QueryBuilder $enrollment, $id) => $enrollment->where('student_enrollments.program_id', $id))
            ->when($filters['section_id'] ?? null, fn (QueryBuilder $enrollment, $id) => $enrollment->where('student_enrollments.section_id', $id))
            ->whereExists(function (QueryBuilder $students) use ($collegeId): void {
                $students->selectRaw('1')
                    ->from('students')
                    ->whereColumn('students.id', 'student_enrollments.student_id')
                    ->where('students.college_id', $collegeId)
                    ->whereNull('students.deleted_at');
            });
    }

    /** @param  array<string, mixed>  $filters */
    private function attendanceCounts(array $filters): array
    {
        $counts = $this->emptyAttendanceCounts();
        $rows = $this->attendanceAggregate($filters)
            ->select('hostel_attendances.attendance_status')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('hostel_attendances.attendance_status')
            ->get();

        foreach ($rows as $row) {
            if (array_key_exists($row->attendance_status, $counts)) {
                $counts[$row->attendance_status] = (int) $row->total;
            }
        }

        return $counts;
    }

    /** @return array<string, int> */
    private function emptyAttendanceCounts(): array
    {
        return [
            HostelAttendance::STATUS_PRESENT => 0,
            HostelAttendance::STATUS_ABSENT => 0,
            HostelAttendance::STATUS_LEAVE => 0,
        ];
    }

    /** @param  array<string, mixed>  $filters */
    private function applyDateRange(Builder|QueryBuilder $query, array $filters, string $column): void
    {
        if ($filters['from'] ?? null) {
            $query->whereDate($column, '>=', $filters['from']);
        }
        if ($filters['to'] ?? null) {
            $query->whereDate($column, '<=', $filters['to']);
        }
    }

    /** @param  array<string, mixed>  $filters */
    private function hasDateWindow(array $filters): bool
    {
        return ($filters['from'] ?? null) !== null || ($filters['to'] ?? null) !== null;
    }

    private function occupancyStatus(int $occupied, int $usableBeds): string
    {
        if ($usableBeds <= 0) {
            return 'No usable beds';
        }
        if ($occupied <= 0) {
            return 'Available';
        }
        if ($occupied >= $usableBeds) {
            return 'Full';
        }

        return 'Partially occupied';
    }

    private function percentage(int $part, int $whole): ?float
    {
        if ($whole <= 0) {
            return null;
        }

        return round($part / $whole * 100, 2);
    }

    /** @return array<int, string> */
    private function allocationRelations(): array
    {
        return [
            'studentEnrollment.student:id,first_name,middle_name,last_name,student_number',
            'studentEnrollment:id,student_id,enrollment_number,academic_year_id,program_id,section_id',
            'studentEnrollment.academicYear:id,name,code',
            'studentEnrollment.program:id,name,code',
            'studentEnrollment.section:id,name,code',
            'academicYear:id,name,code',
            'hostel:id,name,code',
            'building:id,name,code',
            'room:id,room_number',
            'bed:id,bed_number',
        ];
    }

    private function vacatedStudentCount(): int
    {
        $collegeId = $this->collegeId();

        return (int) DB::table('hostel_allocations')
            ->join('student_enrollments', function ($join) use ($collegeId): void {
                $join->on('student_enrollments.id', '=', 'hostel_allocations.student_enrollment_id')
                    ->where('student_enrollments.college_id', $collegeId)
                    ->whereNull('student_enrollments.deleted_at');
            })
            ->join('students', function ($join) use ($collegeId): void {
                $join->on('students.id', '=', 'student_enrollments.student_id')
                    ->where('students.college_id', $collegeId)
                    ->whereNull('students.deleted_at');
            })
            ->where('hostel_allocations.college_id', $collegeId)
            ->whereNull('hostel_allocations.deleted_at')
            ->where('hostel_allocations.status', HostelAllocation::STATUS_VACATED)
            ->distinct()
            ->count('students.id');
    }

    private function collegeId(): int
    {
        $id = app(TenantContext::class)->id();
        abort_unless($id !== null, 403);

        return (int) $id;
    }
}
