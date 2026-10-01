<?php

namespace Tests\Feature\Hostel;

use App\Models\College;
use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

/**
 * Hostel navigation after Phase 3.
 *
 * Phase 2 entries remain available.
 * Phase 3 adds Attendance and Reports.
 * Later hostel modules must not appear.
 */
class HostelPhase2NavigationTest extends TestCase
{
    use HostelTestHelpers;

    private const ENTRIES = [
        'Hostel Dashboard' => [
            'hostel_dashboard.view',
            'hostels.dashboard',
        ],
        'Hostels' => [
            'hostels.view',
            'hostels.index',
        ],
        'Buildings / Blocks' => [
            'hostel_buildings.view',
            'hostel-buildings.index',
        ],
        'Rooms' => [
            'hostel_rooms.view',
            'hostel-rooms.index',
        ],
        'Beds' => [
            'hostel_beds.view',
            'hostel-beds.index',
        ],
        'Hostel Allocation' => [
            'hostel_allocations.view',
            'hostel-allocations.index',
        ],
        'Hostel Fees' => [
            'hostel_fees.view',
            'hostel-fees.index',
        ],
        'Hostel Attendance' => [
            'hostel_attendance.view',
            'hostel-attendance.index',
        ],
    ];

    private const FUTURE_NOT_ALLOWED = [
        'Visitors',
        'Mess Management',
        'Hostel Maintenance',
        'Hostel Leave Management',
        'Warden Management',
        'Hostel Certificates',
        'Hostel Analytics',
    ];

    private function hostelNavGroup(string $html): string
    {
        $start = strpos(
            $html,
            '>Hostel</div>'
        );

        $this->assertNotFalse(
            $start,
            'The sidebar must have a Hostel Management group heading.'
        );

        $after = $start + strlen(
            '>Hostel</div>'
        );

        $end = strpos(
            $html,
            'nav-group__head',
            $after
        );

        return $end === false
            ? substr($html, $after)
            : substr($html, $after, $end - $after);
    }

    private function reportsNavGroup(string $html): string
    {
        $start = strpos($html, '>Reports</div>');
        $this->assertNotFalse($start, 'The sidebar must have a REPORTS group.');
        $after = $start + strlen('>Reports</div>');
        $end = strpos($html, 'nav-group__head', $after);

        return $end === false ? substr($html, $after) : substr($html, $after, $end - $after);
    }

    private function allViewPermissions(): array
    {
        return array_values(
            array_map(
                fn (array $entry) => $entry[0],
                self::ENTRIES
            )
        );
    }

    public function test_hostel_navigation_keeps_eight_operational_entries_after_phase_three(): void
    {
        $college = $this->makeCollege('H2NAV1');

        $user = $this->makeUserWithPermissions(
            $college,
            [...$this->allViewPermissions(), 'hostel_reports.view', 'transport_reports.view']
        );

        $html = $this->asCollege($college, $user)
            ->get(route('dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertSame(
            1,
            substr_count($html, '>Hostel</div>'),
            'Exactly one Hostel Management section.'
        );

        $group = $this->hostelNavGroup($html);

        $this->assertSame(
            8,
            substr_count($group, 'class="nav-link"'),
            'Must keep exactly 8 Hostel Management operations.'
        );

        foreach (self::ENTRIES as $label => [$permission, $route]) {
            $this->assertStringContainsString(
                route($route),
                $group,
                "Missing route for {$label}"
            );

            $this->assertStringContainsString(
                $label,
                $group,
                "Missing label for {$label}"
            );
        }

        foreach (self::FUTURE_NOT_ALLOWED as $future) {
            $this->assertStringNotContainsString(
                $future,
                $group,
                "{$future} must NOT appear."
            );
        }

        $reports = $this->reportsNavGroup($html);
        $this->assertSame(1, substr_count($html, '>Reports</div>'));
        $this->assertStringNotContainsString(route('hostel-reports.index'), $group);
        $this->assertStringContainsString(route('hostel-reports.index'), $reports);
        $this->assertGreaterThan(strpos($reports, 'Transport Reports'), strpos($reports, 'Hostel Reports'));
    }

    public function test_each_phase2_entry_is_permission_gated(): void
    {
        $college = $this->makeCollege('H2NAV2');

        $viewer = $this->makeUserWithPermissions(
            $college,
            ['hostel_allocations.view']
        );

        $html = $this->asCollege($college, $viewer)
            ->get(route('dashboard'))
            ->assertOk()
            ->getContent();

        $group = $this->hostelNavGroup($html);

        $this->assertSame(
            1,
            substr_count($group, 'class="nav-link"')
        );

        $this->assertStringContainsString(
            route('hostel-allocations.index'),
            $group
        );

        $this->assertStringNotContainsString(
            route('hostel-fees.index'),
            $group
        );
    }

    public function test_future_hostel_modules_must_not_appear_even_for_super_admin(): void
    {
        $college = $this->makeCollege('H2NAV3');

        $super = $this->makeSuperAdmin($college);

        $html = $this->asCollege($college, $super)
            ->get(route('dashboard'))
            ->assertOk()
            ->getContent();

        $group = $this->hostelNavGroup($html);

        $this->assertSame(
            8,
            substr_count($group, 'class="nav-link"')
        );

        $this->assertStringContainsString(
            'Hostel Attendance',
            $group
        );

        $this->assertStringNotContainsString('Hostel Reports', $group);
        $this->assertStringContainsString('Hostel Reports', $this->reportsNavGroup($html));

        foreach (self::FUTURE_NOT_ALLOWED as $future) {
            $this->assertStringNotContainsString(
                $future,
                $group,
                "{$future} must NOT appear even for super admin."
            );
        }
    }

    public function test_seeded_college_admin_sees_hostel_operations_and_reports_link(): void
    {
        $college = College::query()
            ->where('code', 'DEMO')
            ->firstOrFail();

        $role = Role::query()
            ->where('college_id', $college->id)
            ->where('slug', 'college-admin')
            ->firstOrFail();

        $user = User::create([
            'name' => 'Seeded Hostel Phase2 Admin',
            'email' => 'seeded-hostel-phase2-admin@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);

        $user->colleges()->attach(
            $college->id,
            ['is_default' => true]
        );

        $user->roles()->attach(
            $role->id,
            ['college_id' => $college->id]
        );

        $response = $this->asCollege($college, $user)
            ->get(route('dashboard'))
            ->assertOk();

        foreach (self::ENTRIES as $label => [$permission, $route]) {
            $response
                ->assertSee(route($route), false)
                ->assertSee($label);
        }
        $this->assertStringContainsString(route('hostel-reports.index'), $this->reportsNavGroup($response->getContent()));

        foreach ([
            'hostels.dashboard',
            'hostels.index',
            'hostel-buildings.index',
            'hostel-rooms.index',
            'hostel-beds.index',
            'hostel-allocations.index',
            'hostel-allocations.create',
            'hostel-fees.index',
            'hostel-fees.create',
            'hostel-fee-structures.index',
            'hostel-fee-structures.create',
            'hostel-attendance.index',
            'hostel-attendance.create',
            'hostel-attendance.bulk',
            'hostel-reports.index',
        ] as $route) {
            $this->asCollege($college, $user)
                ->get(route($route))
                ->assertOk();
        }
    }
}
