<?php

namespace Tests\Feature\Hostel;

use App\Models\College;
use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

/**
 * Sidebar navigation for the Hostel Management module.
 *
 * Guards the invariants the module depends on:
 *   - a single "Hostel Management" section, rendered exactly once;
 *   - it lists exactly the five Phase 1 entries
 *     (dashboard, hostels, buildings / blocks, rooms, beds);
 *   - every entry is individually gated on its own view permission;
 *   - the section is hidden entirely without any hostel permission;
 *   - Phase 2 / Phase 3 entries (allocation, fees, attendance, visitors and
 *     reports) are not yet rendered.
 */
class HostelNavigationTest extends TestCase
{
    use HostelTestHelpers;

    /**
     * The five Phase 1 navigation entries, in order:
     * label => [permission, route].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const ENTRIES = [
        'Hostel Dashboard' => ['hostel_dashboard.view', 'hostels.dashboard'],
        'Hostels' => ['hostels.view', 'hostels.index'],
        'Buildings / Blocks' => ['hostel_buildings.view', 'hostel-buildings.index'],
        'Rooms' => ['hostel_rooms.view', 'hostel-rooms.index'],
        'Beds' => ['hostel_beds.view', 'hostel-beds.index'],
    ];

    /**
     * The Hostel Management sidebar group: from its heading until the next
     * group heading (same extraction technique as the Library tests).
     */
    private function hostelNavGroup(string $html): string
    {
        $start = strpos($html, '>Hostel Management</div>');
        $this->assertNotFalse($start, 'The sidebar must have a Hostel Management group heading.');

        $after = $start + strlen('>Hostel Management</div>');
        $end = strpos($html, 'uppercase tracking-widest', $after);

        return $end === false ? substr($html, $after) : substr($html, $after, $end - $after);
    }

    /**
     * @return array<int, string>
     */
    private function allViewPermissions(): array
    {
        return array_values(array_map(fn (array $entry) => $entry[0], self::ENTRIES));
    }

    public function test_the_hostel_section_lists_exactly_the_five_phase_one_entries(): void
    {
        $college = $this->makeCollege('HNAV1');
        $user = $this->makeUserWithPermissions($college, $this->allViewPermissions());

        $html = $this->asCollege($college, $user)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, '>Hostel Management</div>'), 'There must be exactly one Hostel Management section.');
        $this->assertSame(1, substr_count($html, '<aside'), 'The layout must keep one sidebar.');

        $group = $this->hostelNavGroup($html);

        $this->assertSame(5, substr_count($group, 'class="nav-link"'), 'The Hostel Management group must list exactly the five Phase 1 entries.');

        foreach (self::ENTRIES as $label => [$permission, $route]) {
            $this->assertStringContainsString(route($route), $group, "Missing hostel entry route: {$label}");
            $this->assertStringContainsString($label, $group, "Missing hostel entry label: {$label}");
        }

        // Phase 2 / Phase 3 entries are not rendered.
        foreach (['Hostel Allocation', 'Hostel Fees', 'Hostel Attendance', 'Visitors', 'Hostel Reports'] as $future) {
            $this->assertStringNotContainsString($future, $group, "{$future} must not be rendered in Phase 1.");
        }
    }

    public function test_each_entry_is_gated_on_its_own_view_permission(): void
    {
        $college = $this->makeCollege('HNAV2');
        $viewer = $this->makeUserWithPermissions($college, ['hostel_rooms.view']);

        $html = $this->asCollege($college, $viewer)->get(route('dashboard'))->assertOk()->getContent();
        $group = $this->hostelNavGroup($html);

        $this->assertSame(1, substr_count($group, 'class="nav-link"'), 'Only the permitted entry may be rendered.');
        $this->assertStringContainsString(route('hostel-rooms.index'), $group);
        $this->assertStringNotContainsString(route('hostels.dashboard'), $group);
        $this->assertStringNotContainsString(route('hostels.index'), $group);
        $this->assertStringNotContainsString(route('hostel-buildings.index'), $group);
        $this->assertStringNotContainsString(route('hostel-beds.index'), $group);
    }

    public function test_the_section_is_hidden_without_any_hostel_permission(): void
    {
        $college = $this->makeCollege('HNAV3');
        $stranger = $this->makeUserWithPermissions($college, ['students.view', 'fee_categories.view']);

        $this->asCollege($college, $stranger)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('>Hostel Management</div>', false)
            ->assertDontSee(route('hostels.dashboard'), false)
            ->assertDontSee(route('hostels.index'), false)
            ->assertDontSee('Hostel Dashboard')
            ->assertDontSee('Buildings / Blocks');
    }

    public function test_the_section_does_not_disturb_the_library_group(): void
    {
        $college = $this->makeCollege('HNAV4');
        $super = $this->makeSuperAdmin($college);

        $html = $this->asCollege($college, $super)->get(route('dashboard'))->assertOk()->getContent();

        // The Library Management group keeps its ten entries…
        $libraryStart = strpos($html, '>Library Management</div>');
        $this->assertNotFalse($libraryStart);
        $this->assertSame(1, substr_count($html, '>Library Management</div>'));

        $after = $libraryStart + strlen('>Library Management</div>');
        $end = strpos($html, 'uppercase tracking-widest', $after);
        $library = substr($html, $after, $end - $after);
        $this->assertSame(10, substr_count($library, 'class="nav-link"'));

        // …the Hostel Management section closes the module groups after it,
        // fully populated…
        $hostelStart = (int) strpos($html, '>Hostel Management</div>');
        $this->assertGreaterThan($libraryStart, $hostelStart);
        $this->assertSame(5, substr_count($this->hostelNavGroup($html), 'class="nav-link"'));

        // …and Platform / Settings still closes the sidebar after it.
        $this->assertGreaterThan($hostelStart, (int) strrpos($html, '>Platform</div>'));
    }

    public function test_a_seeded_college_admin_sees_every_hostel_entry_and_can_open_each_screen(): void
    {
        $college = College::query()->where('code', 'DEMO')->firstOrFail();
        $role = Role::query()->where('college_id', $college->id)->where('slug', 'college-admin')->firstOrFail();

        $user = User::create([
            'name' => 'Seeded Hostel Admin',
            'email' => 'seeded-hostel-admin@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->colleges()->attach($college->id, ['is_default' => true]);
        $user->roles()->attach($role->id, ['college_id' => $college->id]);

        $this->assertFalse($user->isSuperAdmin());

        $response = $this->asCollege($college, $user)->get(route('dashboard'))->assertOk();

        foreach (self::ENTRIES as $label => [$permission, $route]) {
            $response->assertSee(route($route), false)->assertSee($label);
        }

        foreach (['hostels.dashboard', 'hostels.index', 'hostels.create', 'hostel-buildings.index', 'hostel-buildings.create', 'hostel-rooms.index', 'hostel-rooms.create', 'hostel-beds.index', 'hostel-beds.create'] as $route) {
            $this->asCollege($college, $user)->get(route($route))->assertOk();
        }
    }
}
