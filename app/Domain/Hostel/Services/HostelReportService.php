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
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * HostelReportService — READ-ONLY Hostel reporting (Phase 3).
 *
 * Every figure is aggregated live from the existing Hostel, Student and
 * Finance records. There are no report tables, no second copy of any business
 * fact, and NOTHING in this service writes.
 *
 * Occupancy uses HostelAllocation as the source of truth for who occupies a
 * bed (not the denormalized bed status flag). Fee figures reuse
 * HostelFeeService::ledgerFor(), which is the shared FeeLedger arithmetic over
 * fee_payments / fee_refunds, so a report cannot disagree with Hostel Fees.
 *
 * Queries run through tenant-scoped models (CollegeScope) with explicit
 * college_id predicates on joined Finance rows. Aggregates are COUNT / SUM /
 * GROUP BY / JOIN constructs that behave the same on SQLite and MySQL.
 */
class HostelReportService
{
    public function __construct(private readonly HostelFeeService $fees)
    {
    }

    /**
     * Occupancy summary, hostel-wise occupancy and building/room occupancy.
     *
     * Physical capacity follows the hostel/building filters. Year, term and
     * date filters narrow who counts as occupying a bed: with no dates, only
     * active allocations; with a date window, allocations that overlapped it
     * (cancelled allocations never occupy a bed).
     *
     * @param  array<string, mixed>  $filters
     * @return array{summary: array<string, int|float|null>, hostels: array<int, array<string, mixed>>, buildings: array<int, array<string, mixed>>, rooms: array<int, array<string, mixed>>}
     */
    public function occupancy(array $filters): array
    {
        $collegeId = $this->collegeId();

        $bedCounts = $this->bedQuery($filters)
            ->reorder()
            ->toBase()
            ->select('hostel_id', 'building_id', 'room_id')
            ->selectRaw('COUNT(*) as beds')
            ->selectRaw('SUM(CASE WHEN hostel_beds.status = ? THEN 1 ELSE 0 END) as inactive_beds', [HostelBed::STATUS_INACTIVE])
            ->groupBy('hostel_id', 'building_id', 'room_id')
            ->get();

        $occupiedByRoom = $this->residencyQuery($filters)
            ->whereIn('hostel_bed_id', $this->bedQuery($filters)->select('hostel_beds.id'))
            ->reorder()
            ->toBase()
            ->select('hostel_room_id')
            ->selectRaw('COUNT(DISTINCT hostel_bed_id) as occupied')
            ->groupBy('hostel_room_id')
            ->pluck('occupied', 'hostel_room_id');

        $hostels = Hostel::query()
            ->when($filters['hostel_id'] ?? null, fn (Builder $query, $id) => $query->whereKey($id))
            ->when($filters['hostel_building_id'] ?? null, fn (Builder $query, $id) => $query->whereHas(
                'buildings',
                fn (Builder $buildings) => $buildings->whereKey($id)
            ))
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'code', 'status']);

        $buildings = HostelBuilding::query()
            ->with('hostel:id,name')
            ->when($filters['hostel_id'] ?? null, fn (Builder $query, $id) => $query->where('hostel_id', $id))
            ->when($filters['hostel_building_id'] ?? null, fn (Builder $query, $id) => $query->whereKey($id))
            ->orderBy('hostel_id')
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'hostel_id', 'name', 'code', 'status']);

        $rooms = HostelRoom::query()
            ->with(['hostel:id,name', 'building:id,name'])
            ->when($filters['hostel_id'] ?? null, fn (Builder $query, $id) => $query->where('hostel_id', $id))
            ->when($filters['hostel_building_id'] ?? null, fn (Builder $query, $id) => $query->where('building_id', $id))
            ->orderBy('hostel_id')
            ->orderBy('building_id')
            ->orderBy('room_number')
            ->orderBy('id')
            ->get(['id', 'hostel_id', 'building_id', 'room_number', 'capacity', 'status']);

        $bedsByRoom = [];
        $inactiveByRoom = [];
        foreach ($bedCounts as $row) {
            $bedsByRoom[(int) $row->room_id] = (int) $row->beds;
            $inactiveByRoom[(int) $row->room_id] = (int) $row->inactive_beds;
        }

        $roomRows = [];
        $buildingRollup = [];
        $hostelRollup = [];
        $totalBeds = 0;
        $totalOccupied = 0;
        $totalInactive = 0;

        // Roll occupancy up from the grouped bed query so a missing label row
        // cannot drop beds or occupied counts from the summary.
        foreach ($bedCounts as $row) {
            $roomId = (int) $row->room_id;
            $beds = (int) $row->beds;
            $inactive = (int) $row->inactive_beds;
            $occupied = (int) ($occupiedByRoom[$roomId] ?? 0);
            $totalBeds += $beds;
            $totalInactive += $inactive;
            $totalOccupied += $occupied;

            $buildingId = (int) $row->building_id;
            $hostelId = (int) $row->hostel_id;
            $buildingRollup[$buildingId]['beds'] = ($buildingRollup[$buildingId]['beds'] ?? 0) + $beds;
            $buildingRollup[$buildingId]['occupied'] = ($buildingRollup[$buildingId]['occupied'] ?? 0) + $occupied;
            $buildingRollup[$buildingId]['inactive_beds'] = ($buildingRollup[$buildingId]['inactive_beds'] ?? 0) + $inactive;
            $hostelRollup[$hostelId]['beds'] = ($hostelRollup[$hostelId]['beds'] ?? 0) + $beds;
            $hostelRollup[$hostelId]['occupied'] = ($hostelRollup[$hostelId]['occupied'] ?? 0) + $occupied;
            $hostelRollup[$hostelId]['inactive_beds'] = ($hostelRollup[$hostelId]['inactive_beds'] ?? 0) + $inactive;
        }

        foreach ($rooms as $room) {
            $beds = $bedsByRoom[$room->id] ?? 0;
            $occupied = (int) ($occupiedByRoom[$room->id] ?? 0);
            $roomRows[] = [
                'room' => $room,
                'capacity' => (int) $room->capacity,
                'beds' => $beds,
                'inactive_beds' => $inactiveByRoom[$room->id] ?? 0,
                'occupied' => $occupied,
                'vacant' => max(0, $beds - $occupied),
                'occupancy_percentage' => $this->percentage($occupied, $beds),
            ];
        }

        $hostelRows = $hostels->map(function (Hostel $hostel) use ($hostelRollup) {
            $beds = (int) ($hostelRollup[$hostel->id]['beds'] ?? 0);
            $occupied = (int) ($hostelRollup[$hostel->id]['occupied'] ?? 0);

            return [
                'hostel' => $hostel,
                'beds' => $beds,
                'inactive_beds' => (int) ($hostelRollup[$hostel->id]['inactive_beds'] ?? 0),
                'occupied' => $occupied,
                'vacant' => max(0, $beds - $occupied),
                'occupancy_percentage' => $this->percentage($occupied, $beds),
            ];
        })->all();

        $buildingRows = $buildings->map(function (HostelBuilding $building) use ($buildingRollup) {
            $beds = (int) ($buildingRollup[$building->id]['beds'] ?? 0);
            $occupied = (int) ($buildingRollup[$building->id]['occupied'] ?? 0);

            return [
                'building' => $building,
                'beds' => $beds,
                'inactive_beds' => (int) ($buildingRollup[$building->id]['inactive_beds'] ?? 0),
                'occupied' => $occupied,
                'vacant' => max(0, $beds - $occupied),
                'occupancy_percentage' => $this->percentage($occupied, $beds),
            ];
        })->all();

        return [
            'summary' => [
                'hostels' => $hostels->count(),
                'buildings' => $buildings->count(),
                'rooms' => $rooms->count(),
                'beds' => $totalBeds,
                'inactive_beds' => $totalInactive,
                'occupied' => $totalOccupied,
                'vacant' => max(0, $totalBeds - $totalOccupied),
                'occupancy_percentage' => $this->percentage($totalOccupied, $totalBeds),
            ],
            'hostels' => $hostelRows,
            'buildings' => $buildingRows,
            'rooms' => $roomRows,
            'college_id' => $collegeId,
        ];
    }

    /**
     * Active allocation summary: live status counts plus the filtered active
     * allocation register.
     *
     * @param  array<string, mixed>  $filters
     * @return array{counts: array<string, int>, rows: LengthAwarePaginator}
     */
    public function allocations(array $filters): array
    {
        $this->collegeId();

        $counts = $this->allocationQuery($filters, false)
            ->reorder()
            ->toBase()
            ->select('status')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $rows = $this->allocationQuery($filters, true)
            ->with([
                'studentEnrollment.student:id,first_name,middle_name,last_name,student_number',
                'studentEnrollment:id,student_id,enrollment_number,academic_year_id',
                'academicYear:id,name',
                'hostel:id,name,code',
                'building:id,name',
                'room:id,room_number',
                'bed:id,bed_number',
            ])
            ->orderByDesc('allocation_date')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        $active = (int) ($counts[HostelAllocation::STATUS_ACTIVE] ?? 0);
        $vacated = (int) ($counts[HostelAllocation::STATUS_VACATED] ?? 0);
        $cancelled = (int) ($counts[HostelAllocation::STATUS_CANCELLED] ?? 0);

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
     * Attendance summary: present / absent / leave, percentage where the
     * denominator is non-zero, and the same figures per hostel.
     *
     * The attendance-status filter narrows the register only. Summary counts
     * stay complete so a percentage is not forced to 100% by hiding the other
     * statuses.
     *
     * @param  array<string, mixed>  $filters
     * @return array{summary: array<string, int|float|null>, hostels: array<int, array<string, mixed>>, rows: LengthAwarePaginator}
     */
    public function attendance(array $filters): array
    {
        $collegeId = $this->collegeId();

        $grouped = $this->attendanceAggregate($filters)
            ->select('hostel_allocations.hostel_id', 'hostel_attendances.attendance_status')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('hostel_allocations.hostel_id', 'hostel_attendances.attendance_status')
            ->get();

        $summary = [
            HostelAttendance::STATUS_PRESENT => 0,
            HostelAttendance::STATUS_ABSENT => 0,
            HostelAttendance::STATUS_LEAVE => 0,
        ];
        $byHostel = [];

        foreach ($grouped as $row) {
            $status = (string) $row->attendance_status;
            $total = (int) $row->total;
            if (! array_key_exists($status, $summary)) {
                continue;
            }
            $summary[$status] += $total;
            $hostelId = (int) $row->hostel_id;
            $byHostel[$hostelId][$status] = ($byHostel[$hostelId][$status] ?? 0) + $total;
        }

        $names = Hostel::query()
            ->whereIn('id', array_keys($byHostel))
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'code'])
            ->keyBy('id');

        $hostelRows = [];
        foreach ($names as $hostel) {
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

        $marked = array_sum($summary);

        $rows = $this->attendanceQuery($filters, true)
            ->with([
                'studentEnrollment.student:id,first_name,middle_name,last_name,student_number',
                'studentEnrollment:id,student_id,enrollment_number',
                'allocation.hostel:id,name',
                'allocation.building:id,name',
                'allocation.room:id,room_number',
                'allocation:id,hostel_id,hostel_building_id,hostel_room_id,hostel_bed_id,student_enrollment_id',
            ])
            ->orderByDesc('attendance_date')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

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
            'college_id' => $collegeId,
        ];
    }

    /**
     * Hostel fee summary. Assigned / paid / outstanding are the shared
     * FeeLedger figures of the filtered hostel fee assignments — summed per
     * assignment so an over-collection on one student cannot reduce another's
     * outstanding. Collections are the existing Finance fee_payments rows.
     *
     * @param  array<string, mixed>  $filters
     * @return array{totals: array<string, int|float|string|null>, hostels: array<int, array<string, mixed>>, rows: LengthAwarePaginator}
     */
    public function fees(array $filters): array
    {
        $this->collegeId();

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

        $this->feeQuery($filters)
            ->with('hostelAllocation:id,hostel_id')
            ->reorder()
            ->orderBy('id')
            ->chunkById(200, function ($chunk) use (&$totals, &$byHostel): void {
                $ledger = $this->fees->ledgerFor($chunk);
                foreach ($chunk as $assignment) {
                    $summary = $ledger[$assignment->getKey()] ?? null;
                    if ($summary === null) {
                        continue;
                    }

                    $totals['assigned'] += $summary['assigned'];
                    $totals['paid'] += $summary['paid'];
                    $totals['refunded'] += $summary['refunded'];
                    $totals['net_collected'] += $summary['net_collected'];
                    $totals['outstanding'] += $summary['outstanding'];
                    $totals['assignments']++;
                    if ($summary['outstanding'] > FeeLedger::TOLERANCE) {
                        $totals['outstanding_assignments']++;
                    }

                    $hostelId = (int) ($assignment->hostelAllocation?->hostel_id ?? 0);
                    if (! isset($byHostel[$hostelId])) {
                        $byHostel[$hostelId] = [
                            'assigned' => 0.0,
                            'paid' => 0.0,
                            'net_collected' => 0.0,
                            'outstanding' => 0.0,
                            'assignments' => 0,
                        ];
                    }
                    $byHostel[$hostelId]['assigned'] += $summary['assigned'];
                    $byHostel[$hostelId]['paid'] += $summary['paid'];
                    $byHostel[$hostelId]['net_collected'] += $summary['net_collected'];
                    $byHostel[$hostelId]['outstanding'] += $summary['outstanding'];
                    $byHostel[$hostelId]['assignments']++;
                }
            });

        foreach (['assigned', 'paid', 'refunded', 'net_collected', 'outstanding'] as $key) {
            $totals[$key] = FeeLedger::money($totals[$key]);
        }
        $totals['status'] = $totals['assignments'] === 0
            ? null
            : FeeLedger::status($totals['assigned'], 0.0, $totals['paid'], $totals['refunded']);

        $names = Hostel::query()
            ->whereIn('id', array_keys($byHostel))
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'code']);

        $hostelRows = $names->map(function (Hostel $hostel) use ($byHostel) {
            $row = $byHostel[$hostel->id];

            return [
                'hostel' => $hostel,
                'assigned' => FeeLedger::money($row['assigned']),
                'paid' => FeeLedger::money($row['paid']),
                'net_collected' => FeeLedger::money($row['net_collected']),
                'outstanding' => FeeLedger::money($row['outstanding']),
                'assignments' => $row['assignments'],
            ];
        })->all();

        $rows = $this->feeQuery($filters)
            ->with([
                'hostelAllocation.studentEnrollment.student:id,first_name,middle_name,last_name,student_number',
                'hostelAllocation.hostel:id,name',
                'hostelAllocation:id,student_enrollment_id,hostel_id,hostel_bed_id',
                'feeStructure:id,name,code',
                'academicYear:id,name',
            ])
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        $ledger = $this->fees->ledgerFor($rows->getCollection());
        foreach ($rows->getCollection() as $assignment) {
            $assignment->setAttribute('ledger', $ledger[$assignment->getKey()] ?? null);
        }

        return [
            'totals' => $totals,
            'hostels' => $hostelRows,
            'rows' => $rows,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function bedQuery(array $filters): Builder
    {
        return HostelBed::query()
            ->when($filters['hostel_id'] ?? null, fn (Builder $query, $id) => $query->where('hostel_beds.hostel_id', $id))
            ->when($filters['hostel_building_id'] ?? null, fn (Builder $query, $id) => $query->where('hostel_beds.building_id', $id));
    }

    /**
     * Allocations that occupy a bed for the active filters.
     *
     * @param  array<string, mixed>  $filters
     */
    private function residencyQuery(array $filters): Builder
    {
        $query = HostelAllocation::query()->where('status', '!=', HostelAllocation::STATUS_CANCELLED);
        $this->constrainAllocations($query, $filters);

        if ($this->hasDateWindow($filters)) {
            $from = $filters['from'] ?? '0001-01-01';
            $to = $filters['to'] ?? '9999-12-31';
            $query->whereDate('allocation_date', '<=', $to)
                ->where(function (Builder $inner) use ($from) {
                    $inner->where(function (Builder $active) {
                        $active->where('status', HostelAllocation::STATUS_ACTIVE)->whereNull('vacated_date');
                    })->orWhere(function (Builder $vacated) use ($from) {
                        $vacated->where('status', HostelAllocation::STATUS_VACATED)
                            ->whereDate('vacated_date', '>=', $from);
                    });
                });
        } else {
            $query->where('status', HostelAllocation::STATUS_ACTIVE);
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function allocationQuery(array $filters, bool $activeOnly): Builder
    {
        $query = HostelAllocation::query();
        $this->constrainAllocations($query, $filters);

        if ($activeOnly) {
            $query->where('status', HostelAllocation::STATUS_ACTIVE);
        }

        if ($filters['from'] ?? null) {
            $query->whereDate('allocation_date', '>=', $filters['from']);
        }
        if ($filters['to'] ?? null) {
            $query->whereDate('allocation_date', '<=', $filters['to']);
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function attendanceQuery(array $filters, bool $includeStatus): Builder
    {
        return HostelAttendance::query()
            ->when($filters['from'] ?? null, fn (Builder $query, $from) => $query->whereDate('hostel_attendances.attendance_date', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $query, $to) => $query->whereDate('hostel_attendances.attendance_date', '<=', $to))
            ->when($includeStatus && ($filters['attendance_status'] ?? null), fn (Builder $query) => $query->where('attendance_status', $filters['attendance_status']))
            ->whereHas('allocation', fn (Builder $query) => $this->constrainAllocations($query, $filters));
    }

    /**
     * Tenant-safe attendance aggregate. Uses the query builder (not an Eloquent
     * join) so soft-delete columns stay qualified — an Eloquent global scope is
     * applied before a later join and would leave deleted_at ambiguous.
     *
     * The attendance-status filter is intentionally not applied here: summary
     * counts must stay complete so a percentage is not forced to 100%.
     *
     * @param  array<string, mixed>  $filters
     */
    private function attendanceAggregate(array $filters): QueryBuilder
    {
        $collegeId = $this->collegeId();

        $query = DB::table('hostel_attendances')
            ->join('hostel_allocations', function ($join) use ($collegeId) {
                $join->on('hostel_allocations.id', '=', 'hostel_attendances.hostel_allocation_id')
                    ->where('hostel_allocations.college_id', $collegeId)
                    ->whereNull('hostel_allocations.deleted_at');
            })
            ->where('hostel_attendances.college_id', $collegeId)
            ->whereNull('hostel_attendances.deleted_at')
            ->when($filters['from'] ?? null, fn (QueryBuilder $inner, $from) => $inner->whereDate('hostel_attendances.attendance_date', '>=', $from))
            ->when($filters['to'] ?? null, fn (QueryBuilder $inner, $to) => $inner->whereDate('hostel_attendances.attendance_date', '<=', $to));

        $this->constrainJoinedAllocations($query, $filters);

        return $query;
    }

    /**
     * Payable hostel fee assignments. Cancelled assignments are excluded so a
     * voided charge cannot inflate Assigned. Date filters overlap the
     * assignment's effective period.
     *
     * @param  array<string, mixed>  $filters
     */
    private function feeQuery(array $filters): Builder
    {
        return HostelFeeAssignment::query()
            ->whereIn('status', HostelFeeAssignment::PAYABLE_STATUSES)
            ->when($filters['academic_year_id'] ?? null, fn (Builder $query, $id) => $query->where('academic_year_id', $id))
            ->when($filters['from'] ?? null, function (Builder $query, $from) {
                $query->where(function (Builder $inner) use ($from) {
                    $inner->whereNull('effective_until')->orWhereDate('effective_until', '>=', $from);
                });
            })
            ->when($filters['to'] ?? null, fn (Builder $query, $to) => $query->whereDate('effective_from', '<=', $to))
            ->whereHas('hostelAllocation', fn (Builder $query) => $this->constrainAllocations($query, $filters));
    }

    /**
     * Hostel, building, academic year and academic term — shared by every
     * allocation-based report. Does not apply a date window; callers do.
     *
     * @param  array<string, mixed>  $filters
     */
    private function constrainAllocations(Builder $query, array $filters): void
    {
        $collegeId = $this->collegeId();

        $query
            ->when($filters['hostel_id'] ?? null, fn (Builder $inner, $id) => $inner->where('hostel_allocations.hostel_id', $id))
            ->when($filters['hostel_building_id'] ?? null, fn (Builder $inner, $id) => $inner->where('hostel_allocations.hostel_building_id', $id))
            ->when($filters['academic_year_id'] ?? null, fn (Builder $inner, $id) => $inner->where('hostel_allocations.academic_year_id', $id))
            ->when($filters['academic_term_id'] ?? null, function (Builder $inner, $termId) use ($collegeId, $filters) {
                $inner->whereExists(function ($sub) use ($termId, $collegeId, $filters) {
                    $sub->select(DB::raw('1'))
                        ->from('student_academic_records')
                        ->whereColumn('student_academic_records.enrollment_id', 'hostel_allocations.student_enrollment_id')
                        ->where('student_academic_records.college_id', $collegeId)
                        ->where('student_academic_records.academic_term_id', $termId)
                        ->whereNull('student_academic_records.deleted_at')
                        ->when($filters['academic_year_id'] ?? null, fn ($year) => $year->where('student_academic_records.academic_year_id', $filters['academic_year_id']));
                });
            });
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function constrainJoinedAllocations(QueryBuilder $query, array $filters): void
    {
        $collegeId = $this->collegeId();

        $query
            ->when($filters['hostel_id'] ?? null, fn (QueryBuilder $inner, $id) => $inner->where('hostel_allocations.hostel_id', $id))
            ->when($filters['hostel_building_id'] ?? null, fn (QueryBuilder $inner, $id) => $inner->where('hostel_allocations.hostel_building_id', $id))
            ->when($filters['academic_year_id'] ?? null, fn (QueryBuilder $inner, $id) => $inner->where('hostel_allocations.academic_year_id', $id))
            ->when($filters['academic_term_id'] ?? null, function (QueryBuilder $inner, $termId) use ($collegeId, $filters) {
                $inner->whereExists(function ($sub) use ($termId, $collegeId, $filters) {
                    $sub->select(DB::raw('1'))
                        ->from('student_academic_records')
                        ->whereColumn('student_academic_records.enrollment_id', 'hostel_allocations.student_enrollment_id')
                        ->where('student_academic_records.college_id', $collegeId)
                        ->where('student_academic_records.academic_term_id', $termId)
                        ->whereNull('student_academic_records.deleted_at')
                        ->when($filters['academic_year_id'] ?? null, fn ($year) => $year->where('student_academic_records.academic_year_id', $filters['academic_year_id']));
                });
            });
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function hasDateWindow(array $filters): bool
    {
        return ($filters['from'] ?? null) || ($filters['to'] ?? null);
    }

    private function percentage(int $part, int $whole): ?float
    {
        if ($whole <= 0) {
            return null;
        }

        return round($part / $whole * 100, 2);
    }

    private function collegeId(): int
    {
        $id = app(TenantContext::class)->id();
        abort_unless($id !== null, 403);

        return (int) $id;
    }
}
