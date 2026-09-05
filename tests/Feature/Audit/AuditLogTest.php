<?php
namespace Tests\Feature\Audit;
use App\Models\{AuditLog, User};
use App\Services\Audit\AuditLogService;
use Tests\TestCase;
class AuditLogTest extends TestCase
{
    public function test_audit_records_are_created_and_not_editable(): void
    {
        $user = User::first();
        $this->actingAs($user);
        $college = $user->colleges()->first();
        app(\App\Support\Tenancy\TenantContext::class)->set($college);
        $log = app(AuditLogService::class)->record('security.test');
        $this->assertDatabaseHas('audit_logs', ['id' => $log->id, 'action' => 'security.test']);
        $this->assertFalse($log->delete());
    }
}
