<?php

namespace Tests\Feature\Hostel;

use App\Models\College;
use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

/**
 * Hostel Management Phase 3 navigation.
 *
 * After Phase 3 the sidebar has exactly nine Hostel entries. Attendance and
 * Reports are individually gated. Visitors and every later hostel module stay
 * out. A seeded college admin can open all nine screens.
 */
class HostelPhase3NavigationTest extends TestCase
{
    use HostelTestHelpers;

    private const ENTRIES = [
        'Hostel Dashboard' => ['hostel_dashboard.view', 'hostels.dashboard'],
        'Hostels' => ['hostels.view', 'hostels.index'],
        'Buildings / Blocks' => ['hostel_buildings.view', 'hostel-buildings.index'],
        'Rooms' => ['hostel_rooms.view', 'hostel-rooms.index'],
        'Beds' => ['hostel_beds.view', 'hostel-beds.index'],
        'Hostel Allocation' => ['hostel_allocations.view', 'hostel-allocations.index'],
        'Hostel Fees' => ['hostel_fees.view', 'hostel-fees.index'],
        'Hostel Attendance' => ['hostel_attendance.view', 'hostel-attendance.index'],
        'Hostel Reports' => ['hostel_reports.view', 'hostel-reports.index'],
    ];

    private const FUTURE = [
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
        $start = strpos($html, '>Hostel Management</div>');
        $this->assertNotFalse($start, 'The sidebar must have a Hostel Management group heading.');
        $after = $start + strlen('>Hostel Management</div>');
        $end = strpos($html, 'uppercase tracking-widest', $after);

        return $end === false ? substr($html, $after) : substr($html, $after, $end - $after);
    }

    public function test_navigation_lists_exactly_nine_hostel_entries_and_no_future_modules(): void
    {
        $college = $this->makeCollege('H3NAV1');
        $user = $this->makeUserWithPermissions($college, array_column(self::ENTRIES, 0));

        $html = $this->asCollege($college, $user)->get(route('dashboard'))->assertOk()->getContent();
        $group = $this->hostelNavGroup($html);

        $this->assertSame(1, substr_count($html, '>Hostel Management</div>'));
        $this->assertSame(9, substr_count($group, 'class="nav-link"'));

        $cursor = 0;
        foreach (self::ENTRIES as $label => [$permission, $route]) {
            $position = strpos($group, $label);
            $this->assertNotFalse($position, "Missing hostel entry label: {$label}");
            $this->assertGreaterThan($cursor, $position, "{$label} is out of order.");
            $cursor = $position;
            $this->assertStringContainsString(route($route), $group);
        }

        foreach (self::FUTURE as $future) {
            $this->assertStringNotContainsString($future, $group);
        }
    }

    public function test_attendance_and_reports_are_individually_permission_gated(): void
    {
        $college = $this->makeCollege('H3NAV2');

        $attendanceOnly = $this->makeUserWithPermissions($college, ['hostel_attendance.view']);
        $attendanceGroup = $this->hostelNavGroup($this->asCollege($college, $attendanceOnly)->get(route('dashboard'))->assertOk()->getContent());
        $this->assertSame(1, substr_count($attendanceGroup, 'class="nav-link"'));
        $this->assertStringContainsString(route('hostel-attendance.index'), $attendanceGroup);
        $this->assertStringNotContainsString(route('hostel-reports.index'), $attendanceGroup);
        $this->assertStringNotContainsString(route('hostel-fees.index'), $attendanceGroup);

        $reportsOnly = $this->makeUserWithPermissions($college, ['hostel_reports.view']);
        $reportsGroup = $this->hostelNavGroup($this->asCollege($college, $reportsOnly)->get(route('dashboard'))->assertOk()->getContent());
        $this->assertSame(1, substr_count($reportsGroup, 'class="nav-link"'));
        $this->assertStringContainsString(route('hostel-reports.index'), $reportsGroup);
        $this->assertStringNotContainsString(route('hostel-attendance.index'), $reportsGroup);
    }

    public function test_hostel_group_is_hidden_without_any_hostel_permission(): void
    {
        $college = $this->makeCollege('H3NAV3');
        $stranger = $this->makeUserWithPermissions($college, ['students.view']);

        $this->asCollege($college, $stranger)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('>Hostel Management</div>', false)
            ->assertDontSee(route('hostel-attendance.index'), false)
            ->assertDontSee(route('hostel-reports.index'), false)
            ->assertDontSee('Hostel Attendance')
            ->assertDontSee('Hostel Reports')
            ->assertDontSee('Visitors');
    }

    public function test_seeded_college_admin_can_open_all_nine_screens(): void
    {
        $college = College::query()->where('code', 'DEMO')->firstOrFail();
        $role = Role::query()->where('college_id', $college->id)->where('slug', 'college-admin')->firstOrFail();

        $user = User::create([
            'name' => 'Seeded Hostel Phase3 Admin',
            'email' => 'seeded-hostel-phase3-admin@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->colleges()->attach($college->id, ['is_default' => true]);
        $user->roles()->attach($role->id, ['college_id' => $college->id]);

        $this->assertFalse($user->isSuperAdmin());
        $this->assertTrue($user->hasPermission('hostel_attendance.view', $college->id));
        $this->assertTrue($user->hasPermission('hostel_reports.view', $college->id));
        $this->assertFalse($user->hasPermission('hostel_visitors.view', $college->id));

        $response = $this->asCollege($college, $user)->get(route('dashboard'))->assertOk();
        foreach (self::ENTRIES as $label => [$permission, $route]) {
            $response->assertSee($label)->assertSee(route($route), false);
        }
        $response->assertDontSee('Visitors');

        foreach (array_column(self::ENTRIES, 1) as $route) {
            $this->asCollege($college, $user)->get(route($route))->assertOk();
        }

        foreach (['occupancy', 'allocations', 'attendance', 'fees'] as $report) {
            $this->asCollege($college, $user)
                ->get(route('hostel-reports.index', ['report' => $report]))
                ->assertOk();
        }
    }
}
