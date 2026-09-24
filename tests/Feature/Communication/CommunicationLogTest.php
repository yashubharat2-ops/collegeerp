<?php

namespace Tests\Feature\Communication;

use App\Domain\Communication\Services\CommunicationLogService;
use App\Domain\Communication\Support\CommunicationLogStatus;
use App\Models\AuditLog;
use App\Models\CommunicationLog;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Communication Management Phase 2 — SMS / Email Logs.
 *
 * Tenant isolation, RBAC, immutability from the UI, log creation through the
 * service (including from a template), status handling / transitions and the
 * audit trail. No external gateway is involved anywhere.
 */
class CommunicationLogTest extends TestCase
{
    use CommunicationTestHelpers;

    private function service(): CommunicationLogService
    {
        return app(CommunicationLogService::class);
    }

    public function test_logs_are_isolated_per_college(): void
    {
        $college = $this->makeCollege('CLG01');
        $other = $this->makeCollege('CLG01X');
        $user = $this->makeUserWithPermissions($college, ['communication_logs.view']);

        $this->makeLog($college, ['recipient' => 'ours@example.test']);
        $foreign = $this->makeLog($other, ['recipient' => 'theirs@example.test']);

        $this->asCollege($college, $user)
            ->get(route('communication-logs.index'))
            ->assertOk()
            ->assertSee('ours@example.test')
            ->assertDontSee('theirs@example.test');

        $this->asCollege($college, $user)->get(route('communication-logs.show', $foreign))->assertNotFound();
    }

    public function test_viewing_logs_requires_the_log_permission(): void
    {
        $college = $this->makeCollege('CLG02');
        $log = $this->makeLog($college, ['recipient' => 'guarded@example.test']);

        $viewer = $this->makeUserWithPermissions($college, ['communication_logs.view']);
        $this->asCollege($college, $viewer)->get(route('communication-logs.index'))->assertOk()->assertSee('guarded@example.test');
        $this->asCollege($college, $viewer)->get(route('communication-logs.show', $log))->assertOk();

        $stranger = $this->makeUserWithPermissions($college, ['communication_templates.view', 'notifications.view']);
        $this->asCollege($college, $stranger)->get(route('communication-logs.index'))->assertForbidden();
        $this->asCollege($college, $stranger)->get(route('communication-logs.show', $log))->assertForbidden();
    }

    public function test_logs_are_immutable_from_the_ui(): void
    {
        $names = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route) => $route->getName())
            ->filter(fn (?string $name) => $name !== null && str_starts_with($name, 'communication-logs.'))
            ->values()
            ->all();

        sort($names);
        $this->assertSame(['communication-logs.index', 'communication-logs.show'], $names);

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (str_starts_with((string) $route->getName(), 'communication-logs.')) {
                $this->assertSame(['GET', 'HEAD'], $route->methods(), 'Communication logs expose read-only routes only.');
            }
        }
    }

    public function test_a_log_is_created_with_status_handling_and_an_audit_entry(): void
    {
        $college = $this->makeCollege('CLG03');
        $user = $this->makeUserWithPermissions($college, ['communication_logs.view']);
        $student = $this->makeStudent($college);

        $log = $this->withTenant($college, fn () => $this->service()->log($college, [
            'channel' => 'email',
            'recipient' => 'asha@example.test',
            'recipient_type' => 'student',
            'recipient_id' => $student->id,
            'subject' => 'Fee reminder',
            'content' => 'Your instalment is due.',
        ], $user));

        $this->assertSame($college->id, $log->college_id);
        $this->assertSame(CommunicationLogStatus::QUEUED, $log->status);
        $this->assertNull($log->sent_at);
        $this->assertNull($log->delivered_at);
        $this->assertSame('student', $log->recipient_type);
        $this->assertSame(1, AuditLog::where('action', 'communication_logs.created')->count());

        // An SMS log never keeps a subject line.
        $sms = $this->withTenant($college, fn () => $this->service()->log($college, [
            'channel' => 'sms',
            'recipient' => '+910000000000',
            'subject' => 'Ignored',
            'content' => 'Short text.',
            'status' => CommunicationLogStatus::SENT,
        ], $user));

        $this->assertNull($sms->subject);
        $this->assertNotNull($sms->sent_at);
    }

    public function test_status_transitions_are_validated_idempotent_and_terminal(): void
    {
        $college = $this->makeCollege('CLG04');
        $user = $this->makeUserWithPermissions($college, ['communication_logs.view']);

        $this->withTenant($college, function () use ($college, $user): void {
            $log = $this->service()->log($college, [
                'channel' => 'email',
                'recipient' => 'flow@example.test',
                'subject' => 'Flow',
                'content' => 'Body.',
            ], $user);

            $log = $this->service()->markSent($log, 'REF-123');
            $this->assertSame(CommunicationLogStatus::SENT, $log->status);
            $this->assertSame('REF-123', $log->provider_reference);
            $this->assertNotNull($log->sent_at);

            // Idempotent: repeating a transition changes nothing.
            $log = $this->service()->markSent($log);
            $this->assertSame(CommunicationLogStatus::SENT, $log->status);

            $log = $this->service()->markDelivered($log);
            $this->assertSame(CommunicationLogStatus::DELIVERED, $log->status);
            $this->assertNotNull($log->delivered_at);

            // Delivered is terminal.
            try {
                $this->service()->markFailed($log, 'too late');
                $this->fail('A delivered log must not become failed.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('status', $exception->errors());
            }

            $failed = $this->service()->log($college, [
                'channel' => 'sms',
                'recipient' => '+910000000001',
                'content' => 'Body.',
                'status' => CommunicationLogStatus::FAILED,
                'failure_reason' => 'Invalid number',
            ], $user);
            $this->assertSame('Invalid number', $failed->failure_reason);

            // A failure always needs a reason.
            try {
                $this->service()->log($college, [
                    'channel' => 'sms',
                    'recipient' => '+910000000002',
                    'content' => 'Body.',
                    'status' => CommunicationLogStatus::FAILED,
                ], $user);
                $this->fail('A failed log must carry a reason.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('failure_reason', $exception->errors());
            }
        });
    }

    public function test_a_log_may_reuse_a_template_of_the_same_college_and_channel_only(): void
    {
        $college = $this->makeCollege('CLG05');
        $other = $this->makeCollege('CLG05X');
        $user = $this->makeUserWithPermissions($college, ['communication_logs.view']);

        $template = $this->makeTemplate($college, [
            'code' => 'WELCOME',
            'subject' => 'Welcome {{ name }}',
            'body' => 'Hello {{ name }}, welcome aboard.',
        ]);
        $foreignTemplate = $this->makeTemplate($other, ['code' => 'FOREIGN']);
        $smsTemplate = $this->makeTemplate($college, ['code' => 'SMSONE', 'channel' => 'sms', 'subject' => null]);

        $log = $this->withTenant($college, fn () => $this->service()->logFromTemplate($college, $template, 'asha@example.test', ['name' => 'Asha'], [], $user));

        $this->assertSame($template->id, $log->communication_template_id);
        $this->assertSame('Welcome Asha', $log->subject);
        $this->assertSame('Hello Asha, welcome aboard.', $log->content);

        $this->withTenant($college, function () use ($college, $foreignTemplate, $smsTemplate, $user): void {
            try {
                $this->service()->log($college, [
                    'channel' => 'email',
                    'recipient' => 'x@example.test',
                    'content' => 'Body.',
                    'communication_template_id' => $foreignTemplate->id,
                ], $user);
                $this->fail('A template of another college must be rejected.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('communication_template_id', $exception->errors());
            }

            try {
                $this->service()->log($college, [
                    'channel' => 'email',
                    'recipient' => 'x@example.test',
                    'content' => 'Body.',
                    'communication_template_id' => $smsTemplate->id,
                ], $user);
                $this->fail('A template of another channel must be rejected.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('communication_template_id', $exception->errors());
            }
        });

        // Deleting the template keeps the log and its own message snapshot.
        $template->delete();
        $log->refresh();
        $this->assertNull($log->communication_template_id);
        $this->assertSame('Hello Asha, welcome aboard.', $log->content);
    }

    public function test_the_listing_filters_by_channel_status_and_search(): void
    {
        $college = $this->makeCollege('CLG06');
        $user = $this->makeUserWithPermissions($college, ['communication_logs.view']);

        $this->makeLog($college, ['channel' => 'sms', 'recipient' => '+911111111111', 'subject' => null, 'status' => 'failed', 'failure_reason' => 'Invalid number']);
        $this->makeLog($college, ['channel' => 'email', 'recipient' => 'delivered@example.test', 'status' => 'delivered']);

        $this->asCollege($college, $user)
            ->get(route('communication-logs.index', ['channel' => 'sms']))
            ->assertOk()
            ->assertSee('+911111111111')
            ->assertDontSee('delivered@example.test');

        $this->asCollege($college, $user)
            ->get(route('communication-logs.index', ['status' => 'delivered']))
            ->assertOk()
            ->assertSee('delivered@example.test')
            ->assertDontSee('+911111111111');

        $this->asCollege($college, $user)
            ->get(route('communication-logs.index', ['status' => 'not-a-status', 'template_id' => 'abc']))
            ->assertOk();

        $this->assertSame(2, CommunicationLog::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    public function test_a_log_never_lands_in_another_college(): void
    {
        $college = $this->makeCollege('CLG07');
        $other = $this->makeCollege('CLG07X');
        $user = $this->makeUserWithPermissions($college, ['communication_logs.view']);

        $this->withTenant($college, function () use ($other, $user): void {
            try {
                $this->service()->log($other, [
                    'channel' => 'email',
                    'recipient' => 'x@example.test',
                    'content' => 'Body.',
                ], $user);
                $this->fail('Writing into another college must be refused.');
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        });

        $this->assertSame(0, CommunicationLog::withoutGlobalScopes()->where('college_id', $other->id)->count());
    }
}
