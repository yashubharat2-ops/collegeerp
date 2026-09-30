<?php

namespace Tests\Feature\Administration;

use App\Models\Permission;
use App\Services\Authorization\RolePermissionService;
use App\Support\Tenancy\TenantContext;
use Tests\TestCase;

class PermissionGrantBoundaryTest extends TestCase
{
    use AdministrationTestHelpers;

    public function test_bulk_grant_options_match_the_existing_permission_checker_for_tenant_inactive_and_super_admin_cases(): void
    {
        $college = $this->college();
        $other = $this->college('GRANTS-OTHER');
        $actor = $this->actor($college, ['campuses.view', 'subjects.view']);
        $actor->colleges()->attach($other->id);
        $otherRole = $this->role($other, ['students.view']);
        $actor->roles()->attach($otherRole->id, ['college_id' => $other->id]);
        $inactive = $this->role($college, ['departments.view'], ['is_active' => false]);
        $actor->roles()->attach($inactive->id, ['college_id' => $college->id]);
        Permission::query()->where('slug', 'subjects.view')->update(['is_active' => false]);
        app(TenantContext::class)->set($college);
        $this->assertSame(['campuses.view'], app(RolePermissionService::class)->grantablePermissions($actor)->pluck('slug')->all());
        $grantable = app(RolePermissionService::class)->grantablePermissions($actor);
        foreach (Permission::query()->get() as $permission) {
            $this->assertSame($actor->hasPermission($permission->slug), $grantable->contains('id', $permission->id));
        }
        $actor->update(['is_active' => false]);
        $this->assertTrue(app(RolePermissionService::class)->grantablePermissions($actor)->isEmpty());
        $super = $this->super($college);
        $this->assertSame(Permission::query()->where('is_active', true)->count(), app(RolePermissionService::class)->grantablePermissions($super)->count());
        app(TenantContext::class)->clear();
        $this->assertTrue(app(RolePermissionService::class)->grantablePermissions($actor)->isEmpty());
    }

    public function test_explicit_null_is_not_an_empty_assignment_array_and_cannot_accidentally_revoke_grants(): void
    {
        $college = $this->college();
        $actor = $this->super($college);
        $user = $this->actor($college, ['campuses.view']);
        $role = $user->roles()->firstOrFail();
        $this->asCollege($college, $actor)->patchJson(route('admin.users.roles.update', $user), ['roles' => null])->assertStatus(422);
        $this->assertSame(1, $user->roles()->count());
        $this->asCollege($college, $actor)->putJson(route('admin.roles.update', $role), ['name' => $role->name, 'is_active' => true, 'permissions' => null])->assertStatus(422);
        $this->assertSame(1, $role->permissions()->count());
    }

    public function test_inactive_or_deleted_other_college_memberships_still_protect_a_shared_global_profile(): void
    {
        $college = $this->college();
        $other = $this->college('RETIRED-COLLEGE');
        $manager = $this->actor($college, ['users.update']);
        $target = $this->actor($college);
        $target->colleges()->attach($other->id);
        $other->delete();
        $this->asCollege($college, $manager)->put(route('admin.users.update', $target), ['name' => 'Cannot change shared identity', 'email' => 'blocked-shared@example.org'])->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'blocked-shared@example.org']);
    }
}
