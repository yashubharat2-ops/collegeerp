<?php

namespace Tests\Feature\Communication;

use App\Domain\Communication\Services\CommunicationReportService;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Communication Management Phase 2 — Communication Reports.
 *
 * Read-only screen: live figures from the existing notices, circulars,
 * notifications and communication logs of the ACTIVE college only, gated by
 * `communication_reports.view`, with no reporting table and no write route.
 */
class CommunicationReportTest extends TestCase
{
    use CommunicationTestHelpers;

    public function test_the_report_screen_requires_its_own_permission(): void
    {
        $college = $this->makeCollege('CRP01');

        $viewer = $this->makeUserWithPermissions($college, ['communication_reports.view']);
        $this->asCollege($college, $viewer)->get(route('communication-reports.index'))->assertOk();

        $stranger = $this->makeUserWithPermissions($college, ['notices.view', 'communication_logs.view']);
        $this->asCollege($college, $stranger)->get(route('communication-reports.index'))->assertForbidden();
    }

    public function test_the_report_screen_is_read_only(): void
    {
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (str_starts_with((string) $route->getName(), 'communication-reports.')) {
                $this->assertSame(['GET', 'HEAD'], $route->methods());
            }
        }
    }

    public function test_the_summary_counts_live_records_of_the_active_college_only(): void
    {
        $college = $this->makeCollege('CRP02');
        $other = $this->makeCollege('CRP02X');
        $user = $this->makeUserWithPermissions($college, ['communication_reports.view']);

        $this->makeNotice($college, ['status' => 'published']);
        $this->makeNotice($college, ['status' => 'draft']);
        $this->makeCircular($college, ['status' => 'published']);
        $this->makeNotification($college, 'user', $user->id, ['sent_at' => now()]);
        $this->makeNotification($college, 'user', $user->id, ['sent_at' => now(), 'delivered_at' => now(), 'read_at' => now()]);

        $this->makeLog($college, ['channel' => 'sms', 'subject' => null, 'status' => 'delivered']);
        $this->makeLog($college, ['channel' => 'sms', 'subject' => null, 'status' => 'failed', 'failure_reason' => 'Invalid number']);
        $this->makeLog($college, ['channel' => 'email', 'status' => 'sent']);
        $this->makeLog($college, ['channel' => 'email', 'status' => 'failed', 'failure_reason' => 'Mailbox full']);

        // Another college's records must never be counted.
        $this->makeNotice($other, ['status' => 'published']);
        $this->makeCircular($other, ['status' => 'published']);
        $this->makeLog($other, ['channel' => 'sms', 'subject' => null, 'status' => 'failed', 'failure_reason' => 'x']);
        $this->makeNotification($other, 'user', $this->makeMember($other)->id);

        $summary = $this->withTenant($college, fn () => app(CommunicationReportService::class)->summary());

        $this->assertSame(2, $summary['notices']['total']);
        $this->assertSame(1, $summary['notices']['published']);
        $this->assertSame(1, $summary['circulars']['total']);
        $this->assertSame(2, $summary['notifications']['total']);
        $this->assertSame(1, $summary['notifications']['read']);
        $this->assertSame(1, $summary['notifications']['unread']);
        $this->assertSame(2, $summary['sms']['total']);
        $this->assertSame(1, $summary['sms']['delivered']);
        $this->assertSame(1, $summary['sms']['failed']);
        $this->assertSame(2, $summary['email']['total']);
        $this->assertSame(1, $summary['email']['sent']);
        $this->assertSame(1, $summary['email']['failed']);
        $this->assertSame(2, $summary['failed_communications']);
        $this->assertSame(4, $summary['total_communications']);

        $this->asCollege($college, $user)
            ->get(route('communication-reports.index'))
            ->assertOk()
            ->assertSee('Communication Reports')
            ->assertSee('Failed communications');
    }

    public function test_the_date_filter_narrows_the_figures(): void
    {
        $college = $this->makeCollege('CRP03');
        $user = $this->makeUserWithPermissions($college, ['communication_reports.view', 'communication_logs.view']);

        $this->makeLog($college, ['channel' => 'email', 'status' => 'sent', 'created_at' => now()->subDays(30), 'updated_at' => now()->subDays(30)]);
        $this->makeLog($college, ['channel' => 'email', 'status' => 'sent']);

        $recent = $this->withTenant($college, fn () => app(CommunicationReportService::class)->summary(now()->subDay()));
        $this->assertSame(1, $recent['email']['total']);

        $all = $this->withTenant($college, fn () => app(CommunicationReportService::class)->summary());
        $this->assertSame(2, $all['email']['total']);

        $this->asCollege($college, $user)
            ->get(route('communication-reports.index', ['date_from' => now()->subDay()->toDateString(), 'date_to' => now()->toDateString()]))
            ->assertOk();

        // Malformed dates are ignored rather than failing the screen.
        $this->asCollege($college, $user)
            ->get(route('communication-reports.index', ['date_from' => 'last-week', 'date_to' => ['array']]))
            ->assertOk();
    }

    public function test_recent_activity_panels_respect_the_module_permissions(): void
    {
        $college = $this->makeCollege('CRP04');
        $user = $this->makeUserWithPermissions($college, ['communication_reports.view']);

        $this->makeLog($college, ['recipient' => 'hidden-log@example.test']);
        $this->makeNotification($college, 'user', $user->id, ['title' => 'Hidden Notification']);

        $this->asCollege($college, $user)
            ->get(route('communication-reports.index'))
            ->assertOk()
            ->assertDontSee('hidden-log@example.test')
            ->assertDontSee('Hidden Notification')
            ->assertSee('You do not have permission to view communication logs.');

        $full = $this->makeUserWithPermissions($college, ['communication_reports.view', 'communication_logs.view', 'notifications.view']);
        $this->asCollege($college, $full)
            ->get(route('communication-reports.index'))
            ->assertOk()
            ->assertSee('hidden-log@example.test')
            ->assertSee('Hidden Notification');
    }
}
