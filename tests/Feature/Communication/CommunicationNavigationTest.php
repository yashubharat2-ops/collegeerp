<?php

namespace Tests\Feature\Communication;

use App\Models\College;
use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

/**
 * Sidebar navigation for Communication Management — Phase 1.
 *
 *   - a single "Communication Management" group, rendered exactly once;
 *   - EXACTLY four entries: Communication Dashboard, Notices / Announcements,
 *     Circulars, Notifications — no future modules (SMS, e-mail, WhatsApp,
 *     templates, delivery logs, reports);
 *   - the group appears only when the user holds at least one of the four
 *     view permissions; every entry is gated on its own permission;
 *   - Super Admin sees the entries through the centralized RBAC.
 */
class CommunicationNavigationTest extends TestCase
{
    use CommunicationTestHelpers;

    /**
     * label => [permission, route], in display order.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const ENTRIES = [
        'Communication Dashboard' => ['communication_dashboard.view', 'communication.dashboard'],
        'Notices / Announcements' => ['notices.view', 'notices.index'],
        'Circulars' => ['circulars.view', 'circulars.index'],
        'Notifications' => ['notifications.view', 'notifications.index'],
    ];

    private const FUTURE = ['SMS', 'Email Gateway', 'E-mail', 'WhatsApp', 'Templates', 'Delivery Logs', 'Communication Reports'];

    private const HEADING = '>Communication Management</div>';

    private function communicationNavGroup(string $html): string
    {
        $start = strpos($html, self::HEADING);
        $this->assertNotFalse($start, 'The sidebar must have a Communication Management group heading.');

        $after = $start + strlen(self::HEADING);
        $end = strpos($html, 'uppercase tracking-widest', $after);

        return $end === false ? substr($html, $after) : substr($html, $after, $end - $after);
    }

    public function test_the_group_lists_exactly_the_four_phase_one_entries_in_order(): void
    {
        $college = $this->makeCollege('CNAV1');
        $user = $this->makeUserWithPermissions($college, array_column(self::ENTRIES, 0));

        $html = $this->asCollege($college, $user)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, self::HEADING), 'There must be exactly one Communication Management group.');
        $this->assertSame(1, substr_count($html, '<aside'), 'The layout keeps a single sidebar.');

        $group = $this->communicationNavGroup($html);
        $this->assertSame(4, substr_count($group, 'class="nav-link"'), 'Exactly four Communication entries.');

        $cursor = -1;
        foreach (self::ENTRIES as $label => [$permission, $route]) {
            $position = strpos($group, route($route));
            $this->assertNotFalse($position, "Missing entry route: {$label}");
            $this->assertStringContainsString($label, $group);
            $this->assertGreaterThan($cursor, $position, "{$label} is out of order.");
            $cursor = $position;
        }

        foreach (self::FUTURE as $future) {
            $this->assertStringNotContainsString($future, $group, "{$future} is a future phase and must not be rendered.");
        }
    }

    public function test_the_group_appears_only_with_a_relevant_permission(): void
    {
        $college = $this->makeCollege('CNAV2');

        $stranger = $this->makeUserWithPermissions($college, ['students.view', 'hostels.view']);
        $this->asCollege($college, $stranger)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(self::HEADING, false)
            ->assertDontSee(route('communication.dashboard'), false)
            ->assertDontSee(route('notices.index'), false)
            ->assertDontSee(route('circulars.index'), false)
            ->assertDontSee(route('notifications.index'), false);

        // Write-only permissions open no screen, so they do not surface the group.
        $writer = $this->makeUserWithPermissions($college, ['notices.create', 'circulars.publish', 'notifications.create']);
        $this->asCollege($college, $writer)->get(route('dashboard'))->assertOk()->assertDontSee(self::HEADING, false);

        foreach (self::ENTRIES as $label => [$permission, $route]) {
            $user = $this->makeUserWithPermissions($college, [$permission]);
            $html = $this->asCollege($college, $user)->get(route('dashboard'))->assertOk()->getContent();

            $this->assertSame(1, substr_count($html, self::HEADING), "{$permission} alone must show the group.");
        }
    }

    public function test_each_entry_is_gated_on_its_own_permission(): void
    {
        $college = $this->makeCollege('CNAV3');

        foreach (self::ENTRIES as $label => [$permission, $route]) {
            $user = $this->makeUserWithPermissions($college, [$permission]);
            $group = $this->communicationNavGroup($this->asCollege($college, $user)->get(route('dashboard'))->assertOk()->getContent());

            $this->assertSame(1, substr_count($group, 'class="nav-link"'), "Only the {$label} entry may render for {$permission}.");
            $this->assertStringContainsString(route($route), $group);

            foreach (self::ENTRIES as $otherLabel => [$otherPermission, $otherRoute]) {
                if ($otherRoute !== $route) {
                    $this->assertStringNotContainsString(route($otherRoute), $group, "{$otherLabel} must be hidden without {$otherPermission}.");
                }
            }
        }
    }

    public function test_super_admin_sees_every_entry_through_the_centralized_rbac(): void
    {
        $college = $this->makeCollege('CNAV4');
        $super = $this->makeSuperAdmin($college);

        $html = $this->asCollege($college, $super)->get(route('dashboard'))->assertOk()->getContent();
        $group = $this->communicationNavGroup($html);

        $this->assertSame(4, substr_count($group, 'class="nav-link"'));
        foreach (self::ENTRIES as $label => [$permission, $route]) {
            $this->assertStringContainsString(route($route), $group);
        }

        // The group sits after Hostel Management and before the closing Platform / Settings section.
        $communication = strpos($html, self::HEADING);
        $this->assertGreaterThan((int) strpos($html, '>Hostel Management</div>'), $communication);
        $this->assertGreaterThan($communication, (int) strrpos($html, '>Platform</div>'));

        // …and its screens open for the super admin.
        foreach (['communication.dashboard', 'notices.index', 'notices.create', 'circulars.index', 'circulars.create', 'notifications.index', 'notifications.create'] as $route) {
            $this->asCollege($college, $super)->get(route($route))->assertOk();
        }
    }

    public function test_a_seeded_college_admin_sees_every_entry_and_can_open_each_screen(): void
    {
        $college = College::query()->where('code', 'DEMO')->firstOrFail();
        $role = Role::query()->where('college_id', $college->id)->where('slug', 'college-admin')->firstOrFail();

        $user = User::create(['name' => 'Seeded Communication Admin', 'email' => 'seeded-communication-admin@example.test', 'password' => 'password', 'is_active' => true]);
        $user->colleges()->attach($college->id, ['is_default' => true]);
        $user->roles()->attach($role->id, ['college_id' => $college->id]);

        $this->assertFalse($user->isSuperAdmin());

        $response = $this->asCollege($college, $user)->get(route('dashboard'))->assertOk();
        foreach (self::ENTRIES as $label => [$permission, $route]) {
            $response->assertSee(route($route), false)->assertSee($label);
        }

        foreach (['communication.dashboard', 'notices.index', 'notices.create', 'circulars.index', 'circulars.create', 'notifications.index', 'notifications.create'] as $route) {
            $this->asCollege($college, $user)->get(route($route))->assertOk();
        }
    }
}
