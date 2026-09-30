<?php

namespace Tests\Feature\Administration;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\AdministrationPermissionSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RolesAndPermissionsTest extends TestCase
{
    use AdministrationTestHelpers;

    private function payload(array $overrides = []): array
    {
        return ['name' => 'Academic Office', 'slug' => 'academic-office', 'description' => 'Scoped administrative role', 'is_active' => true, 'permissions' => [], ...$overrides];
    }

    public function test_role_crud_reuses_registry_and_pivots_and_explicit_empty_permissions_revokes_grants(): void
    {
        $college = $this->college();
        $actor = $this->actor($college, ['roles.view', 'roles.create', 'roles.update', 'permissions.view', 'campuses.view']);
        $permission = Permission::query()->where('slug', 'campuses.view')->firstOrFail();
        $count = Permission::count();
        $this->asCollege($college, $actor)->get(route('admin.roles.create'))->assertOk();
        $this->asCollege($college, $actor)->post(route('admin.roles.store'), $this->payload(['permissions' => [$permission->id]]))->assertRedirect(route('admin.roles.index'))->assertSessionHasNoErrors();
        $role = Role::query()->where('college_id', $college->id)->where('slug', 'academic-office')->firstOrFail();
        $this->assertFalse($role->is_system);
        $this->assertDatabaseHas('permission_role', ['role_id' => $role->id, 'permission_id' => $permission->id]);
        $this->asCollege($college, $actor)->get(route('admin.roles.edit', $role))->assertOk();
        $this->asCollege($college, $actor)->put(route('admin.roles.update', $role), ['name' => 'Renamed Office', 'description' => null, 'is_active' => true])->assertSessionHasNoErrors();
        $this->assertSame(1, $role->permissions()->count()); // omitted is not revoked
        $this->asCollege($college, $actor)->put(route('admin.roles.update', $role), ['name' => 'Renamed Office', 'is_active' => true, 'permissions' => []])->assertSessionHasNoErrors();
        $this->assertSame(0, $role->permissions()->count());
        $this->assertSame($count, Permission::count());
        $this->assertDatabaseHas('audit_logs', ['college_id' => $college->id, 'action' => 'roles.created', 'subject_id' => $role->id]);
        $last = AuditLog::query()->where('action', 'roles.updated')->orderByDesc('id')->firstOrFail();
        $this->assertSame([], $last->new_values['permission_ids']);
    }

    public function test_a_viewer_has_no_create_update_or_permission_assignment_authority(): void
    {
        $college = $this->college();
        $actor = $this->actor($college, ['roles.view', 'permissions.view']);
        $role = $this->role($college);
        $this->asCollege($college, $actor)->get(route('admin.roles.index'))->assertOk();
        $this->asCollege($college, $actor)->get(route('admin.permissions.index'))->assertOk()->assertDontSee('Assign permissions');
        $this->asCollege($college, $actor)->get(route('admin.roles.create'))->assertForbidden();
        $this->asCollege($college, $actor)->post(route('admin.roles.store'), $this->payload())->assertForbidden();
        $this->asCollege($college, $actor)->get(route('admin.roles.edit', $role))->assertForbidden();
        $this->asCollege($college, $actor)->put(route('admin.roles.update', $role), ['name' => 'Denied', 'is_active' => true, 'permissions' => []])->assertForbidden();
    }

    public function test_other_colleges_and_global_role_ids_are_not_resolved_even_for_super_admin(): void
    {
        $college = $this->college();
        $other = $this->college('ROLE-FOREIGN');
        $super = $this->super($college);
        $own = $this->role($college, [], ['name' => 'Local Role Definition']);
        $foreign = $this->role($other, [], ['name' => 'Hidden Foreign Definition']);
        $global = Role::query()->whereNull('college_id')->where('slug', Role::SUPER_ADMIN_SLUG)->firstOrFail();
        $this->asCollege($college, $super)->get(route('admin.roles.index'))->assertOk()->assertSee($own->name)->assertDontSee($foreign->name);
        foreach ([$foreign, $global] as $role) {
            $this->asCollege($college, $super)->get(route('admin.roles.edit', $role))->assertNotFound();
            $this->asCollege($college, $super)->put(route('admin.roles.update', $role), ['name' => 'Hijacked', 'is_active' => true])->assertNotFound();
        }
        $this->assertSame('Hidden Foreign Definition', $foreign->refresh()->name);
    }

    public function test_grant_ceiling_inactive_permissions_and_reserved_identifiers_cannot_escalate_authority(): void
    {
        $college = $this->college();
        $actor = $this->actor($college, ['roles.create', 'roles.update', 'roles.view']);
        $permission = Permission::query()->where('slug', 'students.view')->firstOrFail();
        $this->asCollege($college, $actor)->post(route('admin.roles.store'), $this->payload(['permissions' => [$permission->id]]))->assertSessionHasErrors('permissions');
        $this->assertDatabaseMissing('roles', ['college_id' => $college->id, 'slug' => 'academic-office']);
        $this->assertDatabaseMissing('audit_logs', ['college_id' => $college->id, 'action' => 'roles.created']);
        $elevated = $this->role($college, ['students.view']);
        $this->asCollege($college, $actor)->put(route('admin.roles.update', $elevated), ['name' => 'Taken Over', 'is_active' => true, 'permissions' => []])->assertForbidden();
        $permission->update(['is_active' => false]);
        $this->asCollege($college, $actor)->post(route('admin.roles.store'), $this->payload(['permissions' => [$permission->id]]))->assertSessionHasErrors('permissions.0');
        foreach (['super-admin', 'college-admin'] as $slug) {
            $this->asCollege($college, $this->super($college))->post(route('admin.roles.store'), $this->payload(['slug' => $slug]))->assertSessionHasErrors('slug');
        }
    }

    public function test_system_flags_ownership_stable_identifiers_and_own_authority_are_protected(): void
    {
        $college = $this->college();
        $actor = $this->actor($college, ['roles.view', 'roles.create', 'roles.update']);
        $system = $this->role($college, [], ['is_system' => true]);
        $own = $actor->roles()->firstOrFail();
        foreach ([$system, $own] as $role) {
            $this->asCollege($college, $actor)->get(route('admin.roles.edit', $role))->assertForbidden();
            $this->asCollege($college, $actor)->put(route('admin.roles.update', $role), ['name' => 'Denied', 'is_active' => true])->assertForbidden();
        }
        $this->asCollege($college, $actor)->post(route('admin.roles.store'), $this->payload(['college_id' => 999, 'is_system' => true]))->assertSessionHasErrors(['college_id', 'is_system']);
        $editable = $this->role($college);
        $this->asCollege($college, $actor)->put(route('admin.roles.update', $editable), ['name' => 'Unmoved', 'is_active' => true, 'slug' => 'super-admin'])->assertSessionHasErrors('slug');
        $this->assertNotSame('super-admin', $editable->refresh()->slug);
    }

    public function test_role_deactivation_revokes_permissions_without_deleting_assignments(): void
    {
        $college = $this->college();
        $manager = $this->super($college);
        $member = $this->actor($college, ['campuses.view']);
        $role = $member->roles()->firstOrFail();
        $this->assertTrue($member->hasPermission('campuses.view', $college->id));
        $this->asCollege($college, $manager)->put(route('admin.roles.update', $role), ['name' => $role->name, 'is_active' => false])->assertSessionHasNoErrors();
        $this->assertFalse($member->hasPermission('campuses.view', $college->id));
        $this->assertDatabaseHas('role_user', ['role_id' => $role->id, 'user_id' => $member->id, 'college_id' => $college->id]);
    }

    public function test_registry_is_grouped_includes_inactive_definitions_and_links_to_the_existing_role_editor(): void
    {
        $college = $this->college();
        $actor = $this->actor($college, ['permissions.view', 'roles.update', 'campuses.view']);
        $role = $this->role($college, ['campuses.view'], ['name' => 'Editable Registry Role']);
        $other = $this->college('REGISTRY-OTHER');
        $foreign = $this->role($other, [], ['name' => 'Do Not Disclose Foreign Role']);
        $permission = Permission::query()->where('slug', 'campuses.create')->firstOrFail();
        $permission->update(['is_active' => false]);
        $response = $this->asCollege($college, $actor)->get(route('admin.permissions.index', ['module' => 'campuses']))->assertOk()->assertSee('campuses.create')->assertSee('Inactive')->assertDontSee($foreign->name);
        $response->assertSee(route('admin.roles.edit', $role).'#permissions', false);
        $response->assertViewHas('permissions', fn ($groups) => $groups->keys()->all() === ['campuses']);
        $this->assertSame(0, Permission::query()->where('slug', 'like', 'administration.%')->count());
    }

    public function test_standalone_seeder_is_idempotent_and_does_not_overwrite_existing_roles_grants_or_activation(): void
    {
        $permission = Permission::query()->where('slug', 'users.view')->firstOrFail();
        $permission->update(['is_active' => false, 'description' => 'Retain operator configuration']);
        $permissions = Permission::count();
        $roles = Role::count();
        $grants = DB::table('permission_role')->count();
        $this->seed(AdministrationPermissionSeeder::class);
        $this->seed(AdministrationPermissionSeeder::class);
        $this->assertSame($permissions, Permission::count());
        $this->assertSame($roles, Role::count());
        $this->assertSame($grants, DB::table('permission_role')->count());
        $this->assertFalse($permission->refresh()->is_active);
        $this->assertSame('Retain operator configuration', $permission->description);
        $this->assertCount(count(AdministrationPermissionSeeder::PERMISSIONS), array_unique(AdministrationPermissionSeeder::PERMISSIONS));
    }
}
