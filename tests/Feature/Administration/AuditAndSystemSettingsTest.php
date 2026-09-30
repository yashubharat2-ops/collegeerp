<?php

namespace Tests\Feature\Administration;

use App\Models\AuditLog;
use App\Models\College;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Tests\TestCase;

class AuditAndSystemSettingsTest extends TestCase
{
    use AdministrationTestHelpers;

    public function test_audit_read_only_view_is_scoped_and_filters_by_actor_action_module_and_inclusive_dates(): void
    {
        $college = $this->college();
        $other = $this->college('AUDIT-OTHER');
        $actor = $this->actor($college, ['audit_logs.view']);
        $differentActor = $this->actor($college);
        $foreignActor = $this->actor($other, [], ['name' => 'Foreign Audit Actor']);
        $matching = $this->event($college, $actor, 'users.updated', '2026-09-30 23:59:59');
        $this->event($college, $differentActor, 'users.updated', '2026-09-30 12:00:00');
        $this->event($college, $actor, 'roles.created', '2026-09-30 12:00:00');
        $this->event($college, $actor, 'users.updated', '2026-10-01 00:00:00');
        $this->event($other, $foreignActor, 'foreign.secret_action', '2026-09-30 12:00:00');
        $this->asCollege($college, $actor)->get(route('admin.audit-logs.index', ['user_id' => $actor->id, 'action' => 'users.updated', 'module' => 'users', 'date_from' => '2026-09-30', 'date_to' => '2026-09-30']))
            ->assertOk()->assertDontSee('foreign.secret_action')->assertDontSee('Foreign Audit Actor')
            ->assertViewHas('logs', fn ($logs) => $logs->total() === 1 && $logs->first()->id === $matching->id);
    }

    public function test_tenant_filters_cannot_bypass_isolation_and_only_super_admin_can_explicitly_read_platform_scope(): void
    {
        $college = $this->college();
        $other = $this->college('PLATFORM-AUDIT');
        $actor = $this->actor($college, ['audit_logs.view']);
        $foreign = $this->event($other, $actor, 'foreign.action', '2026-09-30 12:00:00');
        $this->event(null, null, 'platform.event', '2026-09-30 12:00:00');
        $this->asCollege($college, $actor)->getJson(route('admin.audit-logs.index', ['college_id' => $other->id]))->assertStatus(422);
        $this->asCollege($college, $actor)->get(route('admin.audit-logs.index', ['scope' => 'platform']))->assertForbidden();
        $super = $this->super($college);
        $this->asCollege($college, $super)->get(route('admin.audit-logs.index'))->assertOk()->assertViewHas('logs', fn ($logs) => $logs->total() === 0);
        $this->asCollege($college, $super)->get(route('admin.audit-logs.index', ['scope' => 'platform', 'college_id' => $other->id]))->assertOk()->assertViewHas('logs', fn ($logs) => $logs->total() === 1 && $logs->first()->id === $foreign->id);
        $this->asCollege($college, $super)->get(route('admin.audit-logs.index', ['scope' => 'platform']))->assertOk()->assertSee('platform.event');
    }

    public function test_historical_payloads_credentials_headers_and_ip_addresses_are_not_selected_or_rendered(): void
    {
        $college = $this->college();
        $actor = $this->actor($college, ['audit_logs.view']);
        AuditLog::create([
            'college_id' => $college->id, 'user_id' => $actor->id, 'action' => 'security.legacy',
            'old_values' => ['password' => 'RawHistoricalPassword'],
            'new_values' => ['remember_token' => 'RawRememberCredential', 'setting' => ['key' => 'smtp.password', 'value' => 'NestedSecret']],
            'user_agent' => 'HeaderCredential', 'ip_address' => '203.0.113.78',
        ]);
        $this->asCollege($college, $actor)->get(route('admin.audit-logs.index'))->assertOk()
            ->assertDontSee('RawHistoricalPassword')->assertDontSee('RawRememberCredential')->assertDontSee('NestedSecret')->assertDontSee('HeaderCredential')->assertDontSee('203.0.113.78')
            ->assertViewHas('logs', fn ($logs) => ! array_key_exists('new_values', $logs->first()->getAttributes()) && ! array_key_exists('old_values', $logs->first()->getAttributes()));
    }

    public function test_malformed_audit_filters_are_validation_errors_and_there_are_no_mutation_endpoints(): void
    {
        $college = $this->college();
        $actor = $this->actor($college, ['audit_logs.view']);
        $log = $this->event($college, $actor, 'users.updated', '2026-09-30 12:00:00');
        $this->asCollege($college, $actor)->getJson(route('admin.audit-logs.index', ['date_from' => 'bad', 'module' => ['array']]))->assertStatus(422);
        $this->asCollege($college, $actor)->getJson(route('admin.audit-logs.index', ['date_from' => '2026-10-01', 'date_to' => '2026-09-01']))->assertStatus(422);
        $this->asCollege($college, $actor)->post(route('admin.audit-logs.index'), ['action' => 'changed'])->assertStatus(405);
        $this->asCollege($college, $actor)->delete('/admin/audit-logs/'.$log->id)->assertNotFound();
        $this->assertFalse($log->update(['action' => 'changed']));
        $this->assertFalse($log->delete());
        $this->assertSame('users.updated', $log->fresh()->action);
    }

    public function test_system_settings_separate_tenant_and_platform_values_and_never_expose_sensitive_configuration(): void
    {
        $college = $this->college();
        $actor = $this->actor($college, ['settings.view']);
        config([
            'mail.mailers.smtp.password' => 'MailConfigurationSecret',
            'database.connections.mysql.password' => 'DatabaseConfigurationSecret',
            'services.example.api_key' => 'ProviderConfigurationSecret',
        ]);
        $this->asCollege($college, $actor)->get(route('admin.system-settings.index'))->assertOk()->assertSee($college->name)->assertDontSee('data-settings-scope="platform"', false);
        $super = $this->super($college);
        $this->asCollege($college, $super)->get(route('admin.system-settings.index'))->assertOk()->assertSee('data-settings-scope="platform"', false)->assertSee('Application timezone')
            ->assertDontSee('MailConfigurationSecret')->assertDontSee('DatabaseConfigurationSecret')->assertDontSee('ProviderConfigurationSecret');
        $this->asCollege($college, $super)->put(route('admin.system-settings.index'), ['app_debug' => true])->assertStatus(405);
        $this->asCollege($college, $this->actor($college))->get(route('admin.system-settings.index'))->assertForbidden();
    }

    public function test_existing_explicit_college_switch_grant_remains_required_for_unassigned_super_admin_context(): void
    {
        $college = $this->college('SWITCH-SETTINGS');
        $user = User::factory()->create(['is_active' => true]);
        $super = Role::query()->whereNull('college_id')->where('slug', Role::SUPER_ADMIN_SLUG)->firstOrFail();
        $user->roles()->attach($super->id, ['college_id' => null]);
        app(TenantContext::class)->clear();
        $this->actingAs($user)->withSession(['active_college_id' => $college->id])->get(route('admin.institution-settings.index'))->assertForbidden();
        $this->actingAs($user)->post(route('college-context.switch'), ['college_id' => $college->id])->assertRedirect();
        $this->actingAs($user)->get(route('admin.institution-settings.index'))->assertOk()->assertSee($college->name);
        $this->assertFalse($user->colleges()->whereKey($college->id)->exists());
    }

    private function event(?College $college, ?User $user, string $action, string $date): AuditLog
    {
        return AuditLog::create(['college_id' => $college?->id, 'user_id' => $user?->id, 'action' => $action, 'created_at' => $date, 'route_name' => 'fixture.event', 'method' => 'PUT']);
    }
}
