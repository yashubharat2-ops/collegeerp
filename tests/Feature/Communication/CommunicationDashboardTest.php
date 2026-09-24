<?php

namespace Tests\Feature\Communication;

use Tests\TestCase;

/**
 * Communication Management — Communication Dashboard.
 *
 * A read-only aggregation of the communication records: it must be gated by
 * `communication_dashboard.view`, count only the active college's records
 * (archived / soft-deleted excluded), and only reveal recent titles to users
 * who may view the corresponding module. No dashboard table exists.
 */
class CommunicationDashboardTest extends TestCase
{
    use CommunicationTestHelpers;

    public function test_the_dashboard_is_permission_gated(): void
    {
        $college = $this->makeCollege('CDB01');
        $this->makeNotice($college, ['title' => 'Hidden draft title']);

        $noDashboard = $this->makeUserWithPermissions($college, ['notices.view', 'circulars.view', 'notifications.view']);
        $this->asCollege($college, $noDashboard)->get(route('communication.dashboard'))->assertForbidden();

        // Dashboard permission alone: counters yes, record titles no.
        $countsOnly = $this->makeUserWithPermissions($college, ['communication_dashboard.view']);
        $this->asCollege($college, $countsOnly)
            ->get(route('communication.dashboard'))
            ->assertOk()
            ->assertViewIs('communication.dashboard')
            ->assertViewHas('totals', fn (array $totals) => $totals['notices'] === 1 && $totals['draft_notices'] === 1)
            ->assertSee('You do not have permission to view notices.')
            ->assertSee('You do not have permission to view circulars.')
            ->assertSee('You do not have permission to view notifications.')
            ->assertDontSee('Hidden draft title');

        $full = $this->makeUserWithPermissions($college, ['communication_dashboard.view', 'notices.view', 'circulars.view', 'notifications.view']);
        $this->asCollege($college, $full)
            ->get(route('communication.dashboard'))
            ->assertOk()
            ->assertSee('Hidden draft title')
            ->assertDontSee('You do not have permission to view notices.');

        $this->app['auth']->forgetGuards();
        $this->get(route('communication.dashboard'))->assertRedirect(route('login'));
    }

    public function test_statistics_are_live_and_scoped_to_the_active_college(): void
    {
        $college = $this->makeCollege('CDB02');
        $other = $this->makeCollege('CDB02X');
        $viewer = $this->makeUserWithPermissions($college, ['communication_dashboard.view', 'notices.view', 'circulars.view', 'notifications.view']);
        $member = $this->makeMember($college);
        $student = $this->makeStudent($college);

        // Notices: 2 published (1 live, 1 expired), 3 draft, 1 archived, 1 soft-deleted.
        $this->makeNotice($college, ['title' => 'Live notice', 'status' => 'published', 'publish_at' => now()->subDay(), 'created_at' => now()->subMinutes(10)]);
        $this->makeNotice($college, ['title' => 'Expired notice', 'status' => 'published', 'publish_at' => now()->subDays(5), 'expires_at' => now()->subDay(), 'created_at' => now()->subMinutes(20)]);
        foreach (range(1, 3) as $i) {
            $this->makeNotice($college, ['title' => "Draft notice {$i}", 'created_at' => now()->subHours($i)]);
        }
        $this->makeNotice($college, ['title' => 'Archived notice', 'status' => 'archived', 'created_at' => now()->subDays(2)]);
        $this->makeNotice($college, ['title' => 'Deleted notice', 'status' => 'published', 'created_at' => now()])->delete();

        // Circulars: 2 published, 1 draft, 1 soft-deleted.
        $this->makeCircular($college, ['title' => 'Published circular A', 'status' => 'published', 'publish_at' => now()->subDay()]);
        $this->makeCircular($college, ['title' => 'Published circular B', 'status' => 'published', 'publish_at' => now()->subDay()]);
        $this->makeCircular($college, ['title' => 'Draft circular']);
        $this->makeCircular($college, ['title' => 'Deleted circular'])->delete();

        // Notifications: 4 total, 3 unread (1 of them addressed to the viewer).
        $this->makeNotification($college, 'user', $viewer->id, ['title' => 'For the viewer']);
        $this->makeNotification($college, 'user', $member->id, ['title' => 'For a member']);
        $this->makeNotification($college, 'student', $student->id, ['title' => 'For a student']);
        $this->makeNotification($college, 'user', $member->id, ['title' => 'Already read', 'read_at' => now()]);

        // Another college's records must never leak in.
        $this->makeNotice($other, ['title' => 'Foreign notice', 'status' => 'published']);
        $this->makeNotice($other, ['title' => 'Foreign draft']);
        $this->makeCircular($other, ['title' => 'Foreign circular', 'status' => 'published', 'publish_at' => now()]);
        $this->makeNotification($other, 'user', $this->makeMember($other)->id, ['title' => 'Foreign notification']);

        $response = $this->asCollege($college, $viewer)->get(route('communication.dashboard'))->assertOk();

        $totals = $response->viewData('totals');
        $this->assertSame(6, $totals['notices']);
        $this->assertSame(2, $totals['published_notices']);
        $this->assertSame(3, $totals['draft_notices']);
        $this->assertSame(1, $totals['archived_notices']);
        $this->assertSame(1, $totals['live_notices']);
        $this->assertSame(3, $totals['circulars']);
        $this->assertSame(2, $totals['published_circulars']);
        $this->assertSame(1, $totals['draft_circulars']);
        $this->assertSame(4, $totals['notifications']);
        $this->assertSame(3, $totals['unread_notifications']);
        $this->assertSame(1, $totals['my_unread_notifications']);

        $response->assertSee('data-stat="notices">6<', false)
            ->assertSee('data-stat="unread_notifications">3<', false)
            ->assertDontSee('Foreign notice')
            ->assertDontSee('Foreign circular')
            ->assertDontSee('Foreign notification')
            ->assertDontSee('Deleted notice')
            ->assertDontSee('Deleted circular');

        // Recent notices: newest first, limited, active college only.
        $recent = $response->viewData('recentNotices')->pluck('title')->all();
        $this->assertSame(['Live notice', 'Expired notice', 'Draft notice 1', 'Draft notice 2', 'Draft notice 3'], $recent);
        $this->assertCount(3, $response->viewData('recentCirculars'));
        $this->assertCount(4, $response->viewData('recentNotifications'));

        // The other college sees only its own figures.
        $otherViewer = $this->makeUserWithPermissions($other, ['communication_dashboard.view']);
        $otherTotals = $this->asCollege($other, $otherViewer)->get(route('communication.dashboard'))->assertOk()->viewData('totals');
        $this->assertSame(2, $otherTotals['notices']);
        $this->assertSame(1, $otherTotals['circulars']);
        $this->assertSame(1, $otherTotals['notifications']);
    }

    public function test_the_dashboard_renders_an_empty_college(): void
    {
        $college = $this->makeCollege('CDB03');
        $viewer = $this->makeUserWithPermissions($college, ['communication_dashboard.view', 'notices.view', 'circulars.view', 'notifications.view']);

        $this->asCollege($college, $viewer)
            ->get(route('communication.dashboard'))
            ->assertOk()
            ->assertViewHas('totals', fn (array $totals) => array_sum($totals) === 0)
            ->assertSee('No notices yet.')
            ->assertSee('No circulars yet.')
            ->assertSee('No notifications yet.');
    }
}
