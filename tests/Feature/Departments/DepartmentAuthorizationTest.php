<?php

namespace Tests\Feature\Departments;

use App\Models\{Department, Role, User};
use Illuminate\Support\Str;
use Tests\TestCase;

class DepartmentAuthorizationTest extends TestCase
{
    use DepartmentTestHelpers;

    public function test_authenticated_users_without_permission_forbidden(): void
    {
        $college = $this->makeCollege('FORB');
        $outsider = $this->makeUserWithPermissions($college, []); // no permissions at all
        $department = Department::create(['college_id' => $college->id, 'name' => 'History', 'code' => 'HIS', 'status' => 'active']);

        $this->asCollege($college, $outsider)->get(route('departments.index'))->assertForbidden();
        $this->asCollege($college, $outsider)->get(route('departments.create'))->assertForbidden();
        $this->asCollege($college, $outsider)->post(route('departments.store'), ['name' => 'Geography', 'code' => 'GEO', 'status' => 'active'])->assertForbidden();
        $this->asCollege($college, $outsider)->get(route('departments.edit', $department))->assertForbidden();
        $this->asCollege($college, $outsider)->put(route('departments.update', $department), ['name' => 'H', 'code' => 'HIS', 'status' => 'active'])->assertForbidden();
        $this->asCollege($college, $outsider)->patch(route('departments.status', $department), ['status' => 'inactive'])->assertForbidden();
        $this->asCollege($college, $outsider)->delete(route('departments.destroy', $department))->assertForbidden();
        $this->assertDatabaseHas('departments', ['id' => $department->id, 'status' => 'active']);
    }

    public function test_view_only_user_may_not_write(): void
    {
        $college = $this->makeCollege('VIEW');
        $viewer = $this->makeUserWithPermissions($college, ['departments.view']);
        $department = Department::create(['college_id' => $college->id, 'name' => 'English', 'code' => 'ENG', 'status' => 'active']);

        $this->asCollege($college, $viewer)->get(route('departments.index'))->assertOk()->assertSee('English');
        $this->asCollege($college, $viewer)->get(route('departments.create'))->assertForbidden();
        $this->asCollege($college, $viewer)->delete(route('departments.destroy', $department))->assertForbidden();
        $this->asCollege($college, $viewer)->patch(route('departments.status', $department), ['status' => 'inactive'])->assertForbidden();
    }

    public function test_permission_without_view_still_forbids_index(): void
    {
        $college = $this->makeCollege('NOVI');
        $creatorOnly = $this->makeUserWithPermissions($college, ['departments.create']);

        $this->asCollege($college, $creatorOnly)->get(route('departments.index'))->assertForbidden();
        // create permission alone is enough for store (spec: departments.create guards it)
        $this->asCollege($college, $creatorOnly)->post(route('departments.store'), ['name' => 'Fine Arts', 'code' => 'ART', 'status' => 'active'], ['Referer' => route('departments.index')])->assertSessionHas('success');
        $this->assertDatabaseHas('departments', ['college_id' => $college->id, 'code' => 'ART']);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('departments.index'))->assertRedirect(route('login'));
    }

    public function test_college_admin_authorized_for_full_department_lifecycle(): void
    {
        $college = $this->makeCollege('CADM');
        // Mirrors the seeder: the College Admin role holds every permission, including departments.*.
        $adminRole = Role::create(['college_id' => $college->id, 'name' => 'College Admin', 'slug' => 'college-admin-'.strtolower($college->code), 'is_system' => true, 'is_active' => true]);
        $adminRole->permissions()->sync(\App\Models\Permission::query()->pluck('id')->all());
        $admin = User::create(['name' => 'CA', 'email' => 'ca-'.Str::random(6).'@example.test', 'password' => 'password', 'is_active' => true]);
        $admin->colleges()->attach($college->id, ['is_default' => true]);
        $admin->roles()->attach($adminRole->id, ['college_id' => $college->id]);

        $this->asCollege($college, $admin)->get(route('departments.index'))->assertOk();
        $this->asCollege($college, $admin)->get(route('departments.create'))->assertOk()->assertSee('New department');
        $this->asCollege($college, $admin)->post(route('departments.store'), ['name' => 'Economics', 'code' => 'ECO', 'status' => 'active'], ['Referer' => route('departments.index')])->assertSessionHas('success');
        $department = Department::withoutGlobalScopes()->firstWhere('code', 'ECO');
        $this->asCollege($college, $admin)->get(route('departments.edit', $department))->assertOk()->assertSee('Economics');
        $this->asCollege($college, $admin)->patch(route('departments.status', $department), ['status' => 'inactive'], ['Referer' => route('departments.index')])->assertSessionHas('success');
        $this->asCollege($college, $admin)->delete(route('departments.destroy', $department), [], ['Referer' => route('departments.index')])->assertSessionHas('success');
        $this->assertSoftDeleted('departments', ['id' => $department->id]);
    }

    public function test_super_admin_can_create_with_explicit_college_context(): void
    {
        $college = $this->makeCollege('SUPA');
        $super = $this->superAdminUser();
        $superRole = Role::firstOrCreate(['college_id' => null, 'slug' => Role::SUPER_ADMIN_SLUG], ['name' => 'Super Admin', 'is_system' => true]);
        $super->roles()->attach($superRole->id, ['college_id' => null]);
        $super->colleges()->attach($college->id, ['is_default' => true]);

        // The super admin's permissions are global (permission exists + is_active).
        $this->asCollege($college, $super)->get(route('departments.index'))->assertOk();
        $this->asCollege($college, $super)->post(route('departments.store'), ['name' => 'Data Science', 'code' => 'DS', 'status' => 'active'], ['Referer' => route('departments.index')])->assertSessionHas('success');
        $this->assertDatabaseHas('departments', ['college_id' => $college->id, 'name' => 'Data Science', 'code' => 'DS']);

        // Even for a super admin, tenant scoping is honoured: rows belong to the
        // explicitly selected college, and a super admin with no college context
        // at all is rejected by the tenant boundary (Phase 0 behaviour).
        $homeless = $this->superAdminUser();
        $homeless->roles()->attach($superRole->id, ['college_id' => null]);
        $this->actingAs($homeless)->get(route('departments.index'))->assertForbidden();
    }
}
