<?php
namespace Tests\Feature\Audit;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use Tests\TestCase;
class AuditRedactionTest extends TestCase
{
    public function test_sensitive_audit_values_are_redacted(): void
    {
        $user = User::first();
        $this->actingAs($user);
        app(\App\Support\Tenancy\TenantContext::class)->set($user->colleges()->first());
        $log = app(AuditLogService::class)->record('security.redaction', null, ['password' => 'secret'], ['token' => 'secret-token']);
        $this->assertSame('[REDACTED]', $log->old_values['password']);
        $this->assertSame('[REDACTED]', $log->new_values['token']);
    }
}
