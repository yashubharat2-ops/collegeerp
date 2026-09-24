<?php

namespace Tests\Feature\Communication;

use App\Models\College;
use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

/**
 * Sidebar navigation for Communication Management — Phases 1 + 2.
 *
 *   - a single "Communication Management" group, rendered exactly once;
 *   - EXACTLY eight entries: Communication Dashboard, Notices /
 *     Announcements, Circulars, Notifications, SMS / Email Templates,
 *     SMS / Email Logs, Delivery / Read Tracking, Communication Reports —
 *     no other Communication module (WhatsApp or gateway screens);
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
        'SMS / Email Templates' => ['communication_templates.view', 'communication-templates.index'],
        'SMS / Email Logs' => ['communication_logs.view', 'communication-logs.index'],
        'Delivery / Read Tracking' => ['communication_tracking.view', 'communication-tracking.index'],
        'Communication Reports' => ['communication_reports.view', 'communication-reports.index'],
    ];

    private const FUTURE = ['WhatsApp', 'Email Gateway', 'SMS Gateway', 'Push Notifications'];

    private const HEADING = '>Communication Management</div>';

    /**
     * The exact href attribute of a nav entry. Matching the full attribute
     * (instead of the bare URL) keeps assertions precise now that some
     * Communication URLs are prefixes of others (/communication vs
     * /communication-templates).
     */
    private function href(string $routeName): string
    {
        return 'href="'.route($routeName).'"';
    }

    private function communicationNavGroup(string $html): string
    {
        $start = strpos($html, self::HEADING);
        $this->assertNotFalse($start, 'The sidebar must have a Communication Management group heading.');

        $after = $start + strlen(self::HEADING);
        $end = strpos($html, 'uppercase tracking-widest', $after);

        return $end === false ? substr($html, $after) : substr($html, $after, $end - $after);
    }

    public function test_the_group_lists_exactly_the_eight_communication_entries_in_order(): void
    {
        $college = $this->makeCollege('CNAV1');
        $user = $this->makeUserWithPermissions($college, array_column(self::ENTRIES, 0));

        $html = $this->asCollege($college, $user)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, self::HEADING), 'There must be exactly one Communication Management group.');
        $this->assertSame(1, substr_count($html, '<aside'), 'The layout keeps a single sidebar.');

        $group = $this->communicationNavGroup($html);
        $this->assertSame(8, substr_count($group, 'class="nav-link"'), 'Exactly eight Communication entries.');

        $cursor = -1;
        foreach (self::ENTRIES as $label => [$permission, $route]) {
            $position = strpos($group, $this->href($route));
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
        $response = $this->asCollege($college, $stranger)->get(route('dashboard'))->assertOk()->assertDontSee(self::HEADING, false);

        foreach (self::ENTRIES as $label => [$permission, $entryRoute]) {
            $response->assertDontSee($this->href($entryRoute), false);
        }

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
            $this->assertStringContainsString($this->href($route), $group);

            foreach (self::ENTRIES as $otherLabel => [$otherPermission, $otherRoute]) {
                if ($otherRoute !== $route) {
                    $this->assertStringNotContainsString($this->href($otherRoute), $group, "{$otherLabel} must be hidden without {$otherPermission}.");
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

        $this->assertSame(8, substr_count($group, 'class="nav-link"'));
        foreach (self::ENTRIES as $label => [$permission, $route]) {
            $this->assertStringContainsString($this->href($route), $group);
        }

        // The group sits after Hostel Management and before the closing Platform / Settings section.
        $communication = strpos($html, self::HEADING);
        $this->assertGreaterThan((int) strpos($html, '>Hostel Management</div>'), $communication);
        $this->assertGreaterThan($communication, (int) strrpos($html, '>Platform</div>'));

        // …and its screens open for the super admin.
        foreach ([
            'communication.dashboard', 'notices.index', 'notices.create', 'circulars.index', 'circulars.create',
            'notifications.index', 'notifications.create', 'communication-templates.index',
            'communication-templates.create', 'communication-logs.index', 'communication-tracking.index',
            'communication-reports.index',
        ] as $route) {
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
            $response->assertSee($this->href($route), false)->assertSee($label);
        }

        foreach ([
            'communication.dashboard', 'notices.index', 'notices.create', 'circulars.index', 'circulars.create',
            'notifications.index', 'notifications.create', 'communication-templates.index',
            'communication-templates.create', 'communication-logs.index', 'communication-tracking.index',
            'communication-reports.index',
        ] as $route) {
            $this->asCollege($college, $user)->get(route($route))->assertOk();
        }
    }
}
