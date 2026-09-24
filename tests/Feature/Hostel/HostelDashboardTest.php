<?php

namespace Tests\Feature\Hostel;

use App\Models\Hostel;
use App\Models\HostelBed;
use Tests\TestCase;

/**
 * Hostel Management — Hostel Dashboard.
 *
 * The dashboard is a read-only aggregation of the Phase 1 masters: its numbers
 * must match the records of the active college only, it must be gated by
 * `hostel_dashboard.view`, and it must render sensibly when the module is
 * empty. No dashboard table exists, so nothing here asserts on stored rows.
 */
class HostelDashboardTest extends TestCase
{
    use HostelTestHelpers;

    public function test_the_dashboard_shows_live_counts_for_the_active_college_only(): void
    {
        $college = $this->makeCollege('HDB01');
        $other = $this->makeCollege('HDB01X');
        $user = $this->makeUserWithPermissions($college, ['hostel_dashboard.view']);

        // Active hostel with 1 building, 2 rooms, 3 beds (1 available, 1 occupied, 1 inactive).
        $hostelA = $this->makeHostel($college, ['name' => 'Active Hostel', 'status' => Hostel::STATUS_ACTIVE]);
        $buildingA = $this->makeHostelBuilding($college, $hostelA);
        $roomA = $this->makeHostelRoom($college, $buildingA, ['capacity' => 3]);
        $roomB = $this->makeHostelRoom($college, $buildingA, ['capacity' => 3]);
        $this->makeHostelBed($college, $roomA, ['status' => HostelBed::STATUS_AVAILABLE]);
        $this->makeHostelBed($college, $roomA, ['status' => HostelBed::STATUS_OCCUPIED]);
        $this->makeHostelBed($college, $roomB, ['status' => HostelBed::STATUS_INACTIVE]);

        // An inactive hostel still counts toward total, but not active, hostels.
        $hostelB = $this->makeHostel($college, ['name' => 'Inactive Hostel', 'status' => Hostel::STATUS_INACTIVE]);
        $this->makeHostelBuilding($college, $hostelB);

        // An archived building must be excluded from every figure.
        $archived = $this->makeHostelBuilding($college, $hostelA);
        $archived->delete();

        // Another college's infrastructure must never leak in.
        $foreignHostel = $this->makeHostel($other, ['name' => 'Foreign Hostel']);
        $foreignBuilding = $this->makeHostelBuilding($other, $foreignHostel);
        $foreignRoom = $this->makeHostelRoom($other, $foreignBuilding, ['capacity' => 5]);
        $this->makeHostelBed($other, $foreignRoom, ['status' => HostelBed::STATUS_OCCUPIED]);

        $this->asCollege($college, $user)
            ->get(route('hostels.dashboard'))
            ->assertOk()
            ->assertViewIs('hostel_dashboard.index')
            ->assertViewHas('totalHostels', 2)
            ->assertViewHas('activeHostels', 1)
            ->assertViewHas('totalBuildings', 2)
            ->assertViewHas('totalRooms', 2)
            ->assertViewHas('totalBeds', 3)
            ->assertViewHas('availableBeds', 1)
            ->assertViewHas('occupiedBeds', 1)
            ->assertViewHas('totals', fn (array $totals) => $totals['inactive_beds'] === 1)
            ->assertViewHas('perHostel', function ($rows) use ($hostelA) {
                $a = $rows->firstWhere('id', $hostelA->id);

                return $rows->count() === 2
                    && $a->buildings_count === 1
                    && $a->rooms_count === 2
                    && $a->beds_count === 3
                    && $a->available_beds_count === 1
                    && $a->occupied_beds_count === 1;
            })
            ->assertSee('Hostel Dashboard')
            ->assertSee('Active Hostel')
            ->assertSee('Inactive Hostel')
            ->assertDontSee('Foreign Hostel');
    }

    public function test_the_dashboard_renders_an_empty_module(): void
    {
        $college = $this->makeCollege('HDB02');
        $user = $this->makeUserWithPermissions($college, ['hostel_dashboard.view']);

        $this->asCollege($college, $user)
            ->get(route('hostels.dashboard'))
            ->assertOk()
            ->assertViewHas('totalHostels', 0)
            ->assertViewHas('activeHostels', 0)
            ->assertViewHas('totalBuildings', 0)
            ->assertViewHas('totalRooms', 0)
            ->assertViewHas('totalBeds', 0)
            ->assertSee('Getting started')
            ->assertSee('No hostels recorded yet for this college.');
    }

    public function test_the_dashboard_is_permission_gated(): void
    {
        $college = $this->makeCollege('HDB03');
        $nobody = $this->makeUserWithPermissions($college, ['dashboard.view']);
        $mastersOnly = $this->makeUserWithPermissions($college, ['hostels.view', 'hostel_beds.view']);

        // Guests are sent to login before any policy runs.
        $this->get(route('hostels.dashboard'))->assertRedirect(route('login'));

        $this->asCollege($college, $nobody)->get(route('hostels.dashboard'))->assertForbidden();
        // Masters permissions alone do not open the dashboard.
        $this->asCollege($college, $mastersOnly)->get(route('hostels.dashboard'))->assertForbidden();
    }

    public function test_the_dashboard_writes_nothing_when_visited(): void
    {
        $college = $this->makeCollege('HDB04');
        $super = $this->makeSuperAdmin($college);
        $this->makeHostelBed($college, null, ['status' => HostelBed::STATUS_OCCUPIED]);

        $count = fn () => $this->withTenant($college, fn () => [
            'hostels' => Hostel::query()->count(),
            'beds' => HostelBed::query()->count(),
        ]);

        $before = $count();

        $this->asCollege($college, $super)->get(route('hostels.dashboard'))->assertOk();

        $this->assertSame($before, $count());
    }
}
