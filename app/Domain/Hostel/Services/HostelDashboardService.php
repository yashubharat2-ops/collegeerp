<?php

namespace App\Domain\Hostel\Services;

use App\Models\Hostel;
use App\Models\HostelAllocation;
use App\Models\HostelBed;
use App\Models\HostelBuilding;
use App\Models\HostelFeeAssignment;
use App\Models\HostelRoom;
use Illuminate\Support\Collection;

/**
 * HostelDashboardService — read-only, live aggregations for the Hostel
 * Dashboard (Hostel Management, Phase 1).
 *
 * Deliberately creates NO dashboard or summary tables: every figure is
 * computed from the Phase 1 masters through the tenant-scoped models, so the
 * numbers can never drift from the records and never leak across colleges.
 * Archived (soft-deleted) rows are excluded automatically.
 */
class HostelDashboardService
{
    /**
     * Headline counters for the active college.
     *
     * @return array<string, int>
     */
    public function totals(): array
    {
        return [
            'hostels' => Hostel::query()->count(),
            'active_hostels' => Hostel::query()->where('status', Hostel::STATUS_ACTIVE)->count(),
            'buildings' => HostelBuilding::query()->count(),
            'rooms' => HostelRoom::query()->count(),
            'beds' => HostelBed::query()->count(),
            'available_beds' => HostelBed::query()->where('status', HostelBed::STATUS_AVAILABLE)->count(),
            'occupied_beds' => HostelBed::query()->where('status', HostelBed::STATUS_OCCUPIED)->count(),
            'inactive_beds' => HostelBed::query()->where('status', HostelBed::STATUS_INACTIVE)->count(),
            // Phase 2 — allocations and fee assignments (live counts, tenant-scoped).
            'allocations' => class_exists(HostelAllocation::class) ? HostelAllocation::query()->count() : 0,
            'active_allocations' => class_exists(HostelAllocation::class) ? HostelAllocation::query()->where('status', HostelAllocation::STATUS_ACTIVE)->count() : 0,
            'fee_assignments' => class_exists(HostelFeeAssignment::class) ? HostelFeeAssignment::query()->count() : 0,
        ];
    }

    /**
     * Per-hostel breakdown of the hierarchy with live occupancy counts.
     * Deterministically ordered by name, then id.
     *
     * @return Collection<int, Hostel>
     */
    public function perHostel(): Collection
    {
        return Hostel::query()
            ->withCount([
                'buildings',
                'rooms',
                'beds',
                'beds as available_beds_count' => fn ($query) => $query->where('status', HostelBed::STATUS_AVAILABLE),
                'beds as occupied_beds_count' => fn ($query) => $query->where('status', HostelBed::STATUS_OCCUPIED),
            ])
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'code', 'hostel_type', 'gender', 'status']);
    }
}
