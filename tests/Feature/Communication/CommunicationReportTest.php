<?php

namespace Tests\Feature\Communication;

use App\Domain\Communication\Services\CommunicationReportService;
use App\Domain\Communication\Support\CommunicationChannels;
use App\Domain\Communication\Support\CommunicationLogStatus;
use App\Domain\Communication\Support\CommunicationPriority;
use App\Domain\Communication\Support\CommunicationTargets;
use App\Domain\Communication\Support\CommunicationTypes;
use App\Domain\Communication\Support\DeliveryStates;
use App\Domain\Communication\Support\NotificationRecipients;
use App\Domain\Communication\Support\PublicationWorkflow;
use App\Http\Controllers\Communication\CommunicationReportController;
use App\Models\College;
use App\Models\CommunicationLog;
use App\Models\CommunicationNotification;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CommunicationReportTest extends TestCase
{
    use CommunicationTestHelpers;

    public function test_reports_constant_and_tabs_follow_exact_eight_report_order(): void
    {
        $expected = [
            'notices' => 'Notice Report',
            'circulars' => 'Circular Report',
            'notifications' => 'Notification Report',
            'templates' => 'Communication Template Report',
            'sms_logs' => 'SMS Log Report',
            'email_logs' => 'Email Log Report',
            'tracking' => 'Delivery / Read Tracking Report',
            'summary' => 'Communication Summary',
        ];

        $this->assertSame($expected, CommunicationReportController::REPORTS);
        $this->assertSame(array_keys($expected), array_keys(CommunicationReportController::REPORTS));

        $college = $this->makeCollege('CREP-ORD');
        $viewer = $this->makeUserWithPermissions($college, ['communication_reports.view']);

        $html = $this->asCollege($college, $viewer)
            ->get(route('communication-reports.index'))
            ->assertOk()
            ->getContent();

        $cursor = -1;
        foreach ($expected as $key => $label) {
            $href = route('communication-reports.index', ['report' => $key]);
            $pos = strpos($html, $href);
            $this->assertNotFalse($pos, "Missing tab link for {$key} ({$label})");
            $this->assertStringContainsString($label, $html);
            $this->assertGreaterThan($cursor, $pos, "Tab {$label} is out of order.");
            $cursor = $pos;
        }
    }

    public function test_rbac_gates_communication_reports_on_communication_reports_view_permission(): void
    {
        $college = $this->makeCollege('CREP-RBAC');

        // Unauthenticated -> login redirect.
        $this->get(route('communication-reports.index'))->assertRedirect(route('login'));

        // Authenticated user without communication_reports.view -> 403 on every report tab.
        $operationalOnly = $this->makeUserWithPermissions($college, [
            'communication_dashboard.view',
            'notices.view',
            'circulars.view',
            'notifications.view',
            'communication_templates.view',
            'communication_logs.view',
            'communication_tracking.view',
        ]);

        foreach (array_keys(CommunicationReportController::REPORTS) as $reportKey) {
            $this->asCollege($college, $operationalOnly)
                ->get(route('communication-reports.index', ['report' => $reportKey]))
                ->assertForbidden();
        }

        // User with communication_reports.view -> 200 on every report tab.
        $viewer = $this->makeUserWithPermissions($college, ['communication_reports.view']);
        foreach (CommunicationReportController::REPORTS as $reportKey => $title) {
            $this->asCollege($college, $viewer)
                ->get(route('communication-reports.index', ['report' => $reportKey]))
                ->assertOk()
                ->assertSee($title);
        }

        // Super Admin -> 200 on every report tab.
        $super = $this->makeSuperAdmin($college);
        foreach (array_keys(CommunicationReportController::REPORTS) as $reportKey) {
            $this->asCollege($college, $super)
                ->get(route('communication-reports.index', ['report' => $reportKey]))
                ->assertOk();
        }

        // Seeded College Admin role holds communication_reports.view.
        $demoCollege = College::query()->where('code', 'DEMO')->firstOrFail();
        $adminRole = Role::query()->where('college_id', $demoCollege->id)->where('slug', 'college-admin')->firstOrFail();
        $collegeAdmin = User::create([
            'name' => 'College Admin Reporter',
            'email' => 'college-admin-reporter@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $collegeAdmin->colleges()->attach($demoCollege->id, ['is_default' => true]);
        $collegeAdmin->roles()->attach($adminRole->id, ['college_id' => $demoCollege->id]);

        $this->assertTrue($collegeAdmin->hasPermission('communication_reports.view'));
        $this->asCollege($demoCollege, $collegeAdmin)
            ->get(route('communication-reports.index'))
            ->assertOk();

        // Only a single communication_reports.view permission exists.
        $this->assertSame(1, Permission::query()->where('slug', 'communication_reports.view')->count());
    }

    public function test_route_is_strictly_get_only_and_renders_no_mutating_actions(): void
    {
        $route = Route::getRoutes()->getByName('communication-reports.index');
        $this->assertNotNull($route);
        $this->assertSame(['GET', 'HEAD'], $route->methods());

        $college = $this->makeCollege('CREP-GET');
        $viewer = $this->makeUserWithPermissions($college, ['communication_reports.view']);

        foreach (['post', 'put', 'patch', 'delete'] as $method) {
            $this->asCollege($college, $viewer)
                ->{$method}('/communication-reports')
                ->assertStatus(405);
        }

        $html = $this->asCollege($college, $viewer)
            ->get(route('communication-reports.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('method="POST"', $html);
        $this->assertStringNotContainsString('method="post"', $html);
        $this->assertStringNotContainsString('_method', $html);
    }

    public function test_empty_states_render_cleanly_across_all_eight_reports(): void
    {
        $college = $this->makeCollege('CREP-EMPTY');
        $viewer = $this->makeUserWithPermissions($college, ['communication_reports.view']);

        $expectedEmptyMessages = [
            'notices' => 'No notices match the selected filters.',
            'circulars' => 'No circulars match the selected filters.',
            'notifications' => 'No notifications match the selected filters.',
            'templates' => 'No communication templates match the selected filters.',
            'sms_logs' => 'No SMS logs match the selected filters.',
            'email_logs' => 'No email logs match the selected filters.',
            'tracking' => 'No delivery or read tracking records match the selected filters.',
            'summary' => 'Communication Module Breakdown',
        ];

        foreach ($expectedEmptyMessages as $reportKey => $expectedText) {
            $this->asCollege($college, $viewer)
                ->get(route('communication-reports.index', ['report' => $reportKey]))
                ->assertOk()
                ->assertSee(CommunicationReportController::REPORTS[$reportKey])
                ->assertSee($expectedText);
        }

        // Unknown or array ?report= falls back safely to Notice Report.
        $this->asCollege($college, $viewer)
            ->get(route('communication-reports.index', ['report' => 'unknown_tab']))
            ->assertOk()
            ->assertViewHas('report', 'notices')
            ->assertSee('Notice Report');

        $this->asCollege($college, $viewer)
            ->get(route('communication-reports.index', ['report' => ['bad']]))
            ->assertOk()
            ->assertViewHas('report', 'notices');
    }

    public function test_notice_report_filters_and_tenant_isolation(): void
    {
        $collegeA = $this->makeCollege('CREP-NA');
        $collegeB = $this->makeCollege('CREP-NB');
        $viewerA = $this->makeUserWithPermissions($collegeA, ['communication_reports.view']);
        $FixturesA = $this->makeAcademicFixtures($collegeA, 'NA');
        $FixturesB = $this->makeAcademicFixtures($collegeB, 'NB');

        $pubExamNotice = $this->makeNotice($collegeA, [
            'title' => 'Alpha Semester Exam Schedule',
            'notice_type' => CommunicationTypes::EXAMINATION,
            'priority' => CommunicationPriority::URGENT,
            'target_type' => CommunicationTargets::DEPARTMENT,
            'target_id' => $FixturesA['department']->id,
            'status' => PublicationWorkflow::PUBLISHED,
            'publish_at' => Carbon::parse('2026-09-10 09:00:00'),
        ]);

        $draftGeneralNotice = $this->makeNotice($collegeA, [
            'title' => 'Alpha Holiday Draft',
            'notice_type' => CommunicationTypes::HOLIDAY,
            'priority' => CommunicationPriority::NORMAL,
            'target_type' => CommunicationTargets::ALL,
            'status' => PublicationWorkflow::DRAFT,
            'publish_at' => Carbon::parse('2026-09-20 09:00:00'),
        ]);

        $foreignNotice = $this->makeNotice($collegeB, [
            'title' => 'Bravo Foreign Secret Notice',
            'notice_type' => CommunicationTypes::EXAMINATION,
            'priority' => CommunicationPriority::URGENT,
            'target_type' => CommunicationTargets::DEPARTMENT,
            'target_id' => $FixturesB['department']->id,
            'status' => PublicationWorkflow::PUBLISHED,
            'publish_at' => Carbon::parse('2026-09-10 09:00:00'),
        ]);

        // Unfiltered Notice Report for College A shows both College A notices and never College B's.
        $this->asCollege($collegeA, $viewerA)
            ->get(route('communication-reports.index', ['report' => 'notices']))
            ->assertOk()
            ->assertSee($pubExamNotice->title)
            ->assertSee($draftGeneralNotice->title)
            ->assertSee($FixturesA['department']->name)
            ->assertDontSee($foreignNotice->title)
            ->assertDontSee($FixturesB['department']->name)
            ->assertViewHas('totals', fn (array $t) => $t['total'] === 2 && $t['published'] === 1 && $t['draft'] === 1);

        // Filter by notice_type, priority, status, department_id, and date range.
        $this->asCollege($collegeA, $viewerA)
            ->get(route('communication-reports.index', [
                'report' => 'notices',
                'notice_type' => CommunicationTypes::EXAMINATION,
                'priority' => CommunicationPriority::URGENT,
                'status' => PublicationWorkflow::PUBLISHED,
                'department_id' => $FixturesA['department']->id,
                'from' => '2026-09-01',
                'to' => '2026-09-15',
            ]))
            ->assertOk()
            ->assertSee($pubExamNotice->title)
            ->assertDontSee($draftGeneralNotice->title)
            ->assertViewHas('totals', fn (array $t) => $t['total'] === 1);

        // Passing foreign department_id returns zero rows.
        $this->asCollege($collegeA, $viewerA)
            ->get(route('communication-reports.index', [
                'report' => 'notices',
                'department_id' => $FixturesB['department']->id,
            ]))
            ->assertOk()
            ->assertDontSee($pubExamNotice->title)
            ->assertDontSee($foreignNotice->title)
            ->assertViewHas('totals', fn (array $t) => $t['total'] === 0);
    }

    public function test_circular_report_filters_and_tenant_isolation(): void
    {
        $collegeA = $this->makeCollege('CREP-CA');
        $collegeB = $this->makeCollege('CREP-CB');
        $viewerA = $this->makeUserWithPermissions($collegeA, ['communication_reports.view']);

        $publishedCircular = $this->makeCircular($collegeA, [
            'circular_number' => 'CIR-A-101',
            'title' => 'Alpha Dress Code Circular',
            'subject' => 'Updated campus dress code',
            'target_type' => CommunicationTargets::STUDENTS,
            'issue_date' => '2026-09-05',
            'status' => PublicationWorkflow::PUBLISHED,
        ]);

        $archivedCircular = $this->makeCircular($collegeA, [
            'circular_number' => 'CIR-A-102',
            'title' => 'Alpha Old Fee Circular',
            'target_type' => CommunicationTargets::PARENTS,
            'issue_date' => '2026-08-01',
            'status' => PublicationWorkflow::ARCHIVED,
        ]);

        $foreignCircular = $this->makeCircular($collegeB, [
            'circular_number' => 'CIR-B-999',
            'title' => 'Bravo Confidential Circular',
            'target_type' => CommunicationTargets::STUDENTS,
            'issue_date' => '2026-09-05',
            'status' => PublicationWorkflow::PUBLISHED,
        ]);

        $this->asCollege($collegeA, $viewerA)
            ->get(route('communication-reports.index', [
                'report' => 'circulars',
                'target_type' => CommunicationTargets::STUDENTS,
                'status' => PublicationWorkflow::PUBLISHED,
                'from' => '2026-09-01',
                'to' => '2026-09-30',
            ]))
            ->assertOk()
            ->assertSee($publishedCircular->circular_number)
            ->assertSee($publishedCircular->title)
            ->assertDontSee($archivedCircular->title)
            ->assertDontSee($foreignCircular->title)
            ->assertViewHas('totals', fn (array $t) => $t['total'] === 1 && $t['published'] === 1);
    }

    public function test_notification_report_filters_and_tenant_isolation(): void
    {
        $collegeA = $this->makeCollege('CREP-NTF-A');
        $collegeB = $this->makeCollege('CREP-NTF-B');
        $viewerA = $this->makeUserWithPermissions($collegeA, ['communication_reports.view']);
        $fxA = $this->makeAcademicFixtures($collegeA, 'NTFA');
        $fxB = $this->makeAcademicFixtures($collegeB, 'NTFB');

        $unreadStudentNotif = $this->makeNotification($collegeA, [
            'title' => 'Alpha Fee Due Alert',
            'notification_type' => CommunicationTypes::FEE,
            'priority' => CommunicationPriority::HIGH,
            'recipient_type' => NotificationRecipients::STUDENT,
            'recipient_id' => $fxA['student']->id,
            'read_at' => null,
        ]);

        $readStaffNotif = $this->makeNotification($collegeA, [
            'title' => 'Alpha Faculty Meeting Note',
            'notification_type' => CommunicationTypes::GENERAL,
            'priority' => CommunicationPriority::NORMAL,
            'recipient_type' => NotificationRecipients::STAFF,
            'recipient_id' => $fxA['faculty']->id,
            'sent_at' => now()->subHour(),
            'delivered_at' => now()->subMinutes(30),
            'read_at' => now()->subMinutes(10),
        ]);

        $foreignNotif = $this->makeNotification($collegeB, [
            'title' => 'Bravo Foreign Notification',
            'notification_type' => CommunicationTypes::FEE,
            'priority' => CommunicationPriority::HIGH,
            'recipient_type' => NotificationRecipients::STUDENT,
            'recipient_id' => $fxB['student']->id,
        ]);

        $this->asCollege($collegeA, $viewerA)
            ->get(route('communication-reports.index', [
                'report' => 'notifications',
                'notification_type' => CommunicationTypes::FEE,
                'recipient_type' => NotificationRecipients::STUDENT,
                'student_id' => $fxA['student']->id,
                'status' => 'unread',
            ]))
            ->assertOk()
            ->assertSee($unreadStudentNotif->title)
            ->assertSee($fxA['student']->admission_no)
            ->assertDontSee($readStaffNotif->title)
            ->assertDontSee($foreignNotif->title)
            ->assertViewHas('totals', fn (array $t) => $t['total'] === 1 && $t['unread'] === 1);

        // Filtering by status=read returns only the read notification.
        $this->asCollege($collegeA, $viewerA)
            ->get(route('communication-reports.index', [
                'report' => 'notifications',
                'status' => 'read',
            ]))
            ->assertOk()
            ->assertSee($readStaffNotif->title)
            ->assertDontSee($unreadStudentNotif->title)
            ->assertViewHas('totals', fn (array $t) => $t['total'] === 1 && $t['read'] === 1);

        // Foreign student_id filter returns zero rows.
        $this->asCollege($collegeA, $viewerA)
            ->get(route('communication-reports.index', [
                'report' => 'notifications',
                'student_id' => $fxB['student']->id,
            ]))
            ->assertOk()
            ->assertDontSee($unreadStudentNotif->title)
            ->assertDontSee($foreignNotif->title)
            ->assertViewHas('totals', fn (array $t) => $t['total'] === 0);
    }

    public function test_template_report_filters_usage_counts_and_tenant_isolation(): void
    {
        $collegeA = $this->makeCollege('CREP-TPL-A');
        $collegeB = $this->makeCollege('CREP-TPL-B');
        $viewerA = $this->makeUserWithPermissions($collegeA, ['communication_reports.view']);

        $smsTpl = $this->makeTemplate($collegeA, [
            'name' => 'Alpha Attendance SMS',
            'code' => 'ALPHA_ATT_SMS',
            'channel' => CommunicationChannels::SMS,
            'body' => 'Dear {{student_name}}, attendance is {{percentage}}%.',
            'status' => 'active',
        ]);
        $this->makeLog($collegeA, [
            'channel' => CommunicationChannels::SMS,
            'communication_template_id' => $smsTpl->id,
            'status' => CommunicationLogStatus::DELIVERED,
        ]);
        $this->makeLog($collegeA, [
            'channel' => CommunicationChannels::SMS,
            'communication_template_id' => $smsTpl->id,
            'status' => CommunicationLogStatus::FAILED,
        ]);

        $emailTpl = $this->makeTemplate($collegeA, [
            'name' => 'Alpha Welcome Email',
            'code' => 'ALPHA_WELCOME_EMAIL',
            'channel' => CommunicationChannels::EMAIL,
            'subject' => 'Welcome {{student_name}}',
            'body' => 'Welcome to {{college_name}}.',
            'status' => 'inactive',
        ]);

        $foreignTpl = $this->makeTemplate($collegeB, [
            'name' => 'Bravo Secret Template',
            'code' => 'BRAVO_SECRET',
            'channel' => CommunicationChannels::SMS,
            'status' => 'active',
        ]);

        $this->asCollege($collegeA, $viewerA)
            ->get(route('communication-reports.index', [
                'report' => 'templates',
                'channel' => CommunicationChannels::SMS,
                'status' => 'active',
            ]))
            ->assertOk()
            ->assertSee($smsTpl->name)
            ->assertSee($smsTpl->code)
            ->assertSee('student_name, percentage')
            ->assertDontSee($emailTpl->name)
            ->assertDontSee($foreignTpl->name)
            ->assertViewHas('totals', fn (array $t) => $t['total'] === 1 && $t['active'] === 1 && $t['sms'] === 1)
            ->assertViewHas('rows', function ($rows) use ($smsTpl) {
                $first = $rows->first();

                return $first
                    && $first->id === $smsTpl->id
                    && (int) $first->logs_count === 2
                    && (int) $first->delivered_logs_count === 1
                    && (int) $first->failed_logs_count === 1;
            });
    }

    public function test_sms_and_email_log_reports_separate_channels_filter_and_isolate_tenants(): void
    {
        $collegeA = $this->makeCollege('CREP-LOG-A');
        $collegeB = $this->makeCollege('CREP-LOG-B');
        $viewerA = $this->makeUserWithPermissions($collegeA, ['communication_reports.view']);

        $smsTemplate = $this->makeTemplate($collegeA, [
            'name' => 'Alpha SMS Template',
            'code' => 'ALPHA_SMS_TPL',
            'channel' => CommunicationChannels::SMS,
        ]);
        $emailTemplate = $this->makeTemplate($collegeA, [
            'name' => 'Alpha Email Template',
            'code' => 'ALPHA_EMAIL_TPL',
            'channel' => CommunicationChannels::EMAIL,
            'subject' => 'Receipt',
        ]);

        $smsDelivered = $this->makeLog($collegeA, [
            'channel' => CommunicationChannels::SMS,
            'communication_template_id' => $smsTemplate->id,
            'recipient' => '+919811112222',
            'content' => 'Alpha SMS Delivered Payload',
            'status' => CommunicationLogStatus::DELIVERED,
            'provider_reference' => 'SMS-REF-001',
        ]);

        $smsFailed = $this->makeLog($collegeA, [
            'channel' => CommunicationChannels::SMS,
            'recipient' => '+919833334444',
            'content' => 'Alpha SMS Failed Payload',
            'status' => CommunicationLogStatus::FAILED,
            'failure_reason' => 'DND active',
        ]);

        $emailSent = $this->makeLog($collegeA, [
            'channel' => CommunicationChannels::EMAIL,
            'communication_template_id' => $emailTemplate->id,
            'recipient' => 'student.alpha@example.test',
            'subject' => 'Alpha Semester Fee Receipt',
            'content' => 'Alpha Email Body Content',
            'status' => CommunicationLogStatus::SENT,
            'provider_reference' => 'EML-REF-001',
        ]);

        $foreignSms = $this->makeLog($collegeB, [
            'channel' => CommunicationChannels::SMS,
            'recipient' => '+919999999999',
            'content' => 'Bravo Foreign SMS Payload',
            'status' => CommunicationLogStatus::DELIVERED,
        ]);

        $foreignEmail = $this->makeLog($collegeB, [
            'channel' => CommunicationChannels::EMAIL,
            'recipient' => 'bravo@example.test',
            'subject' => 'Bravo Foreign Email Subject',
            'content' => 'Bravo Foreign Email Body',
            'status' => CommunicationLogStatus::SENT,
        ]);

        // SMS Log Report shows only College A SMS logs, never Email logs or College B logs.
        $this->asCollege($collegeA, $viewerA)
            ->get(route('communication-reports.index', [
                'report' => 'sms_logs',
                'status' => CommunicationLogStatus::DELIVERED,
                'template_id' => $smsTemplate->id,
            ]))
            ->assertOk()
            ->assertSee($smsDelivered->recipient)
            ->assertSee('Alpha SMS Delivered Payload')
            ->assertDontSee($smsFailed->recipient)
            ->assertDontSee($emailSent->recipient)
            ->assertDontSee($foreignSms->recipient)
            ->assertViewHas('totals', fn (array $t) => $t['total'] === 1 && $t['delivered'] === 1);

        // Email Log Report shows only College A Email logs, never SMS logs or College B logs.
        $this->asCollege($collegeA, $viewerA)
            ->get(route('communication-reports.index', [
                'report' => 'email_logs',
                'status' => CommunicationLogStatus::SENT,
                'search' => 'Semester Fee Receipt',
            ]))
            ->assertOk()
            ->assertSee($emailSent->recipient)
            ->assertSee('Alpha Semester Fee Receipt')
            ->assertDontSee($smsDelivered->recipient)
            ->assertDontSee($foreignEmail->recipient)
            ->assertViewHas('totals', fn (array $t) => $t['total'] === 1 && $t['sent'] === 1);
    }

    public function test_delivery_and_read_tracking_report_filters_states_and_isolates_tenants(): void
    {
        $collegeA = $this->makeCollege('CREP-TRK-A');
        $collegeB = $this->makeCollege('CREP-TRK-B');
        $viewerA = $this->makeUserWithPermissions($collegeA, ['communication_reports.view']);

        $pending = $this->makeNotification($collegeA, [
            'title' => 'Alpha Pending Dispatch',
            'sent_at' => null,
            'delivered_at' => null,
            'read_at' => null,
        ]);

        $delivered = $this->makeNotification($collegeA, [
            'title' => 'Alpha Delivered Dispatch',
            'sent_at' => now()->subHour(),
            'delivered_at' => now()->subMinutes(20),
            'read_at' => null,
        ]);

        $read = $this->makeNotification($collegeA, [
            'title' => 'Alpha Read Dispatch',
            'sent_at' => now()->subHours(2),
            'delivered_at' => now()->subHour(),
            'read_at' => now()->subMinutes(5),
        ]);

        $foreignDelivered = $this->makeNotification($collegeB, [
            'title' => 'Bravo Foreign Delivered Dispatch',
            'sent_at' => now()->subHour(),
            'delivered_at' => now()->subMinutes(20),
            'read_at' => null,
        ]);

        $this->asCollege($collegeA, $viewerA)
            ->get(route('communication-reports.index', [
                'report' => 'tracking',
                'status' => DeliveryStates::DELIVERED,
            ]))
            ->assertOk()
            ->assertSee($delivered->title)
            ->assertDontSee($pending->title)
            ->assertDontSee($read->title)
            ->assertDontSee($foreignDelivered->title)
            ->assertViewHas('totals', fn (array $t) => $t['total'] === 1 && $t['delivered_only'] === 1);
    }

    public function test_summary_aggregates_live_for_the_active_college_only_and_honours_date_windows(): void
    {
        $collegeA = $this->makeCollege('CREP-SUMA');
        $collegeB = $this->makeCollege('CREP-SUMB');
        $viewerA = $this->makeUserWithPermissions($collegeA, ['communication_reports.view']);

        $this->makeNotice($collegeA, ['status' => PublicationWorkflow::PUBLISHED, 'publish_at' => now()->subHour()]);
        $this->makeNotice($collegeA, ['status' => PublicationWorkflow::DRAFT]);
        $this->makeCircular($collegeA, ['status' => PublicationWorkflow::ARCHIVED]);
        $this->makeNotification($collegeA, ['read_at' => null]);
        $this->makeNotification($collegeA, [
            'sent_at' => now()->subMinute(),
            'delivered_at' => now()->subMinute(),
            'read_at' => now(),
        ]);
        $this->makeTemplate($collegeA, ['channel' => CommunicationChannels::SMS, 'status' => 'active']);
        $this->makeTemplate($collegeA, ['channel' => CommunicationChannels::EMAIL, 'status' => 'inactive', 'code' => 'TPL_INACTIVE']);
        $this->makeLog($collegeA, ['channel' => CommunicationChannels::SMS, 'status' => CommunicationLogStatus::DELIVERED]);
        $this->makeLog($collegeA, ['channel' => CommunicationChannels::SMS, 'status' => CommunicationLogStatus::FAILED]);
        $this->makeLog($collegeA, ['channel' => CommunicationChannels::EMAIL, 'status' => CommunicationLogStatus::SENT]);

        // Heavy activity in College B must not bleed into College A's summary.
        for ($i = 0; $i < 4; $i++) {
            $this->makeNotice($collegeB, ['status' => PublicationWorkflow::PUBLISHED]);
            $this->makeCircular($collegeB, ['status' => PublicationWorkflow::PUBLISHED]);
            $this->makeNotification($collegeB);
            $this->makeTemplate($collegeB, ['code' => "B_TPL_{$i}"]);
            $this->makeLog($collegeB, ['channel' => CommunicationChannels::SMS, 'status' => CommunicationLogStatus::DELIVERED]);
            $this->makeLog($collegeB, ['channel' => CommunicationChannels::EMAIL, 'status' => CommunicationLogStatus::DELIVERED]);
        }

        $this->bindTenant($collegeA);
        $summary = app(CommunicationReportService::class)->summary();

        $this->assertSame(['total' => 2, 'draft' => 1, 'published' => 1, 'archived' => 0, 'live' => 1], $summary['notices']);
        $this->assertSame(['total' => 1, 'draft' => 0, 'published' => 0, 'archived' => 1, 'live' => 0], $summary['circulars']);
        $this->assertSame(2, $summary['notifications']['total']);
        $this->assertSame(1, $summary['notifications']['read']);
        $this->assertSame(1, $summary['notifications']['unread']);
        $this->assertSame(['total' => 2, 'active' => 1, 'inactive' => 1, 'sms' => 1, 'email' => 1], $summary['templates']);
        $this->assertSame(2, $summary['sms']['total']);
        $this->assertSame(1, $summary['sms'][CommunicationLogStatus::DELIVERED]);
        $this->assertSame(1, $summary['sms'][CommunicationLogStatus::FAILED]);
        $this->assertSame(1, $summary['email']['total']);
        $this->assertSame(1, $summary['email'][CommunicationLogStatus::SENT]);
        $this->assertSame(1, $summary['delivered_communications']);
        $this->assertSame(1, $summary['failed_communications']);
        $this->assertSame(3, $summary['total_communications']);

        $this->asCollege($collegeA, $viewerA)
            ->get(route('communication-reports.index', ['report' => 'summary']))
            ->assertOk()
            ->assertViewHas('summary', fn (array $s) => $s['total_communications'] === 3 && $s['templates']['total'] === 2);
    }

    public function test_date_range_filter_and_swapped_dates_work_on_summary(): void
    {
        $college = $this->makeCollege('CREP-DATE');
        $viewer = $this->makeUserWithPermissions($college, ['communication_reports.view']);

        $old = $this->makeLog($college, ['channel' => CommunicationChannels::SMS, 'status' => CommunicationLogStatus::DELIVERED]);
        CommunicationLog::withoutGlobalScopes()->whereKey($old->id)->update(['created_at' => Carbon::parse('2026-01-10 12:00:00')]);

        $recent = $this->makeLog($college, ['channel' => CommunicationChannels::EMAIL, 'status' => CommunicationLogStatus::FAILED]);
        CommunicationLog::withoutGlobalScopes()->whereKey($recent->id)->update(['created_at' => Carbon::parse('2026-09-20 12:00:00')]);

        $oldNotification = $this->makeNotification($college);
        CommunicationNotification::withoutGlobalScopes()->whereKey($oldNotification->id)->update(['created_at' => Carbon::parse('2026-01-10 12:00:00')]);

        $this->asCollege($college, $viewer)
            ->get(route('communication-reports.index', [
                'report' => 'summary',
                'from' => '2026-09-01',
                'to' => '2026-09-30',
            ]))
            ->assertOk()
            ->assertViewHas('summary', fn (array $s) => $s['sms']['total'] === 0
                && $s['email']['total'] === 1
                && $s['notifications']['total'] === 0);

        // Swapped dates are normalised automatically; invalid dates are ignored.
        $this->asCollege($college, $viewer)
            ->get(route('communication-reports.index', [
                'report' => 'summary',
                'from' => '2026-09-30',
                'to' => '2026-09-01',
            ]))
            ->assertOk()
            ->assertViewHas('filters', fn (array $f) => $f['from'] === '2026-09-01' && $f['to'] === '2026-09-30');

        $this->asCollege($college, $viewer)
            ->get(route('communication-reports.index', [
                'report' => 'summary',
                'from' => 'not-a-date',
                'to' => ['bad'],
            ]))
            ->assertOk();
    }

    public function test_pagination_is_deterministic_and_preserves_query_string(): void
    {
        $college = $this->makeCollege('CREP-PAGE');
        $viewer = $this->makeUserWithPermissions($college, ['communication_reports.view']);

        for ($i = 1; $i <= 25; $i++) {
            $this->makeNotice($college, [
                'title' => sprintf('Paginated Notice #%02d', $i),
                'status' => PublicationWorkflow::PUBLISHED,
                'publish_at' => Carbon::parse('2026-09-01 08:00:00')->addMinutes($i),
            ]);
        }

        $page1 = $this->asCollege($college, $viewer)
            ->get(route('communication-reports.index', [
                'report' => 'notices',
                'status' => PublicationWorkflow::PUBLISHED,
            ]))
            ->assertOk();

        $rowsPage1 = $page1->viewData('rows');
        $this->assertSame(CommunicationReportService::PER_PAGE, $rowsPage1->count());
        $this->assertSame(25, $rowsPage1->total());
        $this->assertStringContainsString('report=notices', $rowsPage1->url(2));
        $this->assertStringContainsString('status=published', $rowsPage1->url(2));

        $page2 = $this->asCollege($college, $viewer)
            ->get(route('communication-reports.index', [
                'report' => 'notices',
                'status' => PublicationWorkflow::PUBLISHED,
                'page' => 2,
            ]))
            ->assertOk();

        $rowsPage2 = $page2->viewData('rows');
        $this->assertSame(5, $rowsPage2->count());
    }

    public function test_reports_avoid_n_plus_one_queries(): void
    {
        $college = $this->makeCollege('CREP-PERF');
        $viewer = $this->makeUserWithPermissions($college, ['communication_reports.view']);
        $fx = $this->makeAcademicFixtures($college, 'PRF');

        $tpl = $this->makeTemplate($college, ['code' => 'PERF_TPL', 'channel' => CommunicationChannels::SMS]);

        for ($i = 1; $i <= 8; $i++) {
            $this->makeNotice($college, [
                'title' => "Perf Notice {$i}",
                'target_type' => CommunicationTargets::DEPARTMENT,
                'target_id' => $fx['department']->id,
            ]);
            $this->makeCircular($college, ['circular_number' => "PERF-CIR-{$i}"]);
            $this->makeNotification($college, [
                'recipient_type' => NotificationRecipients::STUDENT,
                'recipient_id' => $fx['student']->id,
            ]);
            $this->makeTemplate($college, ['code' => "PERF_TPL_{$i}"]);
            $this->makeLog($college, [
                'channel' => CommunicationChannels::SMS,
                'communication_template_id' => $tpl->id,
                'recipient_type' => NotificationRecipients::STUDENT,
                'recipient_id' => $fx['student']->id,
            ]);
            $this->makeLog($college, [
                'channel' => CommunicationChannels::EMAIL,
                'recipient_type' => NotificationRecipients::USER,
                'recipient_id' => $fx['recipientUser']->id,
            ]);
        }

        foreach (array_keys(CommunicationReportController::REPORTS) as $reportKey) {
            DB::flushQueryLog();
            DB::enableQueryLog();

            $this->asCollege($college, $viewer)
                ->get(route('communication-reports.index', ['report' => $reportKey]))
                ->assertOk();

            $queryCount = count(DB::getQueryLog());
            DB::disableQueryLog();

            $this->assertLessThanOrEqual(
                12,
                $queryCount,
                "Report [{$reportKey}] executed {$queryCount} queries, indicating a potential N+1 regression."
            );
        }
    }
}
