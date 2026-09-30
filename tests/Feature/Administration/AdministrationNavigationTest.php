<?php

namespace Tests\Feature\Administration;

use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AdministrationNavigationTest extends TestCase
{
    use AdministrationTestHelpers;

    private const PAGES = [
        'Users' => 'admin.users.index', 'Roles' => 'admin.roles.index', 'Permissions' => 'admin.permissions.index',
        'Academic Configuration' => 'admin.academic-config.index', 'Institution Settings' => 'admin.institution-settings.index',
        'Notification Settings' => 'admin.notification-settings.index', 'Audit Logs' => 'admin.audit-logs.index', 'System Settings' => 'admin.system-settings.index',
    ];

    public function test_all_eight_pages_render_and_the_sidebar_contains_exactly_the_requested_entries(): void
    {
        $college = $this->college();
        $user = $this->super($college);
        foreach (self::PAGES as $title => $route) {
            $response = $this->asCollege($college, $user)->get(route($route))->assertOk()->assertSee($title);
            $this->assertSame(array_keys(self::PAGES), $this->administrationLabels($response->getContent()));
        }
        $this->asCollege($college, $user)->get('/settings')->assertOk()->assertSee('Institution Settings');
    }

    public function test_sidebar_and_endpoints_are_policy_filtered_without_a_broad_administration_gate(): void
    {
        $college = $this->college();
        $viewer = $this->actor($college, ['users.view']);
        $response = $this->asCollege($college, $viewer)->get(route('admin.users.index'))->assertOk();
        $this->assertSame(['Users'], $this->administrationLabels($response->getContent()));
        foreach (array_diff(self::PAGES, ['admin.users.index']) as $route) {
            $this->asCollege($college, $viewer)->get(route($route))->assertForbidden();
        }
        $this->asCollege($college, $viewer)->get(route('admin.users.create'))->assertForbidden();
        $this->asCollege($college, $viewer)->post(route('admin.users.store'), ['name' => 'Blocked', 'email' => 'blocked@example.org'])->assertForbidden();
    }

    public function test_an_inactive_actor_and_a_user_without_the_active_college_grants_are_denied(): void
    {
        $college = $this->college();
        $other = $this->college('OTHER');
        $actor = $this->actor($college, ['users.view']);
        $actor->colleges()->attach($other->id);
        $this->asCollege($other, $actor)->get(route('admin.users.index'))->assertForbidden();
        $actor->update(['is_active' => false]);
        $this->asCollege($college, $actor)->get(route('admin.users.index'))->assertForbidden();
    }

    public function test_guests_are_redirected_and_read_only_interfaces_have_no_write_routes(): void
    {
        foreach (self::PAGES as $route) {
            $this->get(route($route))->assertRedirect(route('login'));
        }
        foreach (['admin.audit-logs.', 'admin.system-settings.', 'admin.permissions.', 'admin.academic-config.'] as $prefix) {
            foreach (Route::getRoutes() as $route) {
                if (str_starts_with($route->getName() ?? '', $prefix)) {
                    $this->assertSame(['GET', 'HEAD'], $route->methods());
                }
            }
        }
    }

    public function test_super_admin_without_a_tenant_can_read_explicit_platform_screens_only(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $super = Role::query()->whereNull('college_id')->where('slug', Role::SUPER_ADMIN_SLUG)->firstOrFail();
        $user->roles()->attach($super->id, ['college_id' => null]);
        app(TenantContext::class)->clear();
        $response = $this->actingAs($user)->get(route('admin.system-settings.index'))->assertOk();
        $this->assertSame(['Permissions', 'Audit Logs', 'System Settings'], $this->administrationLabels($response->getContent()));
        $response->assertSee(route('admin.audit-logs.index', ['scope' => 'platform']), false);
        $this->actingAs($user)->get(route('admin.audit-logs.index', ['scope' => 'platform']))->assertOk();
        $this->actingAs($user)->get(route('admin.permissions.index'))->assertOk()->assertViewHas('editableRoles', fn ($roles) => $roles->isEmpty());
        $this->actingAs($user)->get(route('admin.users.index'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.roles.index'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.audit-logs.index'))->assertForbidden();
    }

    private function administrationLabels(string $html): array
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $nodes = (new DOMXPath($document))->query('//*[@data-navigation="administration-settings"]/a/span[last()]');
        $labels = [];
        foreach ($nodes as $node) {
            $labels[] = trim($node->textContent);
        }

        return $labels;
    }
}
