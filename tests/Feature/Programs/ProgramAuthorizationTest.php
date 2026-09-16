<?php

namespace Tests\Feature\Programs;

use App\Models\{Department, Permission, Program, Role, User};
use Illuminate\Support\Str;
use Tests\Feature\Departments\DepartmentTestHelpers;
use Tests\TestCase;

class ProgramAuthorizationTest extends TestCase
{
    use DepartmentTestHelpers;

    public function test_authenticated_users_without_permission_forbidden(): void
    {
        $college = $this->makeCollege('FORB');
        $outsider = $this->makeUserWithPermissions($college, []); // no permissions at all
        $program = Program::create(['college_id' => $college->id, 'name' => 'History', 'code' => 'HIS', 'status' => 'active']);

        $this->asCollege($college, $outsider)->get(route('programs.index'))->assertForbidden();
        $this->asCollege($college, $outsider)->get(route('programs.create'))->assertForbidden();
        $this->asCollege($college, $outsider)->post(route('programs.store'), ['name' => 'Geography', 'code' => 'GEO', 'status' => 'active'])->assertForbidden();
        $this->asCollege($college, $outsider)->get(route('programs.edit', $program))->assertForbidden();
        $this->asCollege($college, $outsider)->put(route('programs.update', $program), ['name' => 'H', 'code' => 'HIS', 'status' => 'active'])->assertForbidden();
        $this->asCollege($college, $outsider)->delete(route('programs.destroy', $program))->assertForbidden();
        $this->assertDatabaseHas('programs', ['id' => $program->id, 'name' => 'History', 'deleted_at' => null]);
    }

    public function test_each_action_requires_its_own_permission(): void
    {
        $college = $this->makeCollege('GRAN');
        $program = Program::create(['college_id' => $college->id, 'name' => 'Arts', 'code' => 'ART', 'status' => 'active']);

        // Without programs.view: the index is forbidden even though create works.
        $creator = $this->makeUserWithPermissions($college, ['programs.create']);
        $this->asCollege($college, $creator)->get(route('programs.index'))->assertForbidden();
        $this->asCollege($college, $creator)->post(route('programs.store'), ['name' => 'Fine Arts', 'code' => 'FIN', 'status' => 'active'], ['Referer' => route('programs.index')])->assertSessionHas('success');

        // Without programs.create: both the form and the write are forbidden.
        $viewer = $this->makeUserWithPermissions($college, ['programs.view']);
        $this->asCollege($college, $viewer)->get(route('programs.index'))->assertOk()->assertSee('Arts');
        $this->asCollege($college, $viewer)->get(route('programs.create'))->assertForbidden();
        $this->asCollege($college, $viewer)->post(route('programs.store'), ['name' => 'N', 'code' => 'NN', 'status' => 'active'])->assertForbidden();

        // Without programs.update: the edit form and the write are forbidden; without delete too.
        $this->asCollege($college, $viewer)->get(route('programs.edit', $program))->assertForbidden();
        $this->asCollege($college, $viewer)->put(route('programs.update', $program), ['name' => 'Arts', 'code' => 'ART', 'status' => 'inactive'])->assertForbidden();
        $this->asCollege($college, $viewer)->delete(route('programs.destroy', $program))->assertForbidden();

        // Update permission alone suffices for the edit form and the write.
        $editor = $this->makeUserWithPermissions($college, ['programs.update']);
        $this->asCollege($college, $editor)->get(route('programs.edit', $program))->assertOk();
        $this->asCollege($college, $editor)->put(route('programs.update', $program), ['name' => 'Performing Arts', 'code' => 'ART', 'status' => 'active'], ['Referer' => route('programs.edit', $program)])->assertSessionHas('success');
        $this->assertSame('Performing Arts', $program->fresh()->name);

        // Delete permission alone suffices for the soft delete.
        $deleter = $this->makeUserWithPermissions($college, ['programs.delete']);
        $this->asCollege($college, $deleter)->delete(route('programs.destroy', $program), [], ['Referer' => route('programs.index')])->assertSessionHas('success');
        $this->assertSoftDeleted('programs', ['id' => $program->id]);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('programs.index'))->assertRedirect(route('login'));
    }

    public function test_college_admin_authorized_for_full_program_lifecycle(): void
    {
        $college = $this->makeCollege('CADM');
        // Mirrors the seeder: the College Admin role holds every permission, including programs.*.
        $adminRole = Role::create(['college_id' => $college->id, 'name' => 'College Admin', 'slug' => 'college-admin-'.strtolower($college->code), 'is_system' => true, 'is_active' => true]);
        $adminRole->permissions()->sync(Permission::query()->pluck('id')->all());
        $admin = User::create(['name' => 'CA', 'email' => 'ca-'.Str::random(6).'@example.test', 'password' => 'password', 'is_active' => true]);
        $admin->colleges()->attach($college->id, ['is_default' => true]);
        $admin->roles()->attach($adminRole->id, ['college_id' => $college->id]);
        $department = Department::create(['college_id' => $college->id, 'name' => 'Humanities', 'code' => 'HUM', 'status' => 'active']);

        // UI smoke: every Program page renders for an authorized user.
        $this->asCollege($college, $admin)->get(route('programs.index'))->assertOk()->assertSee('Programs');
        $this->asCollege($college, $admin)->get(route('programs.create'))->assertOk()->assertSee('New program')->assertSee('College level (no department)');
        $this->asCollege($college, $admin)->post(route('programs.store'), ['name' => 'Economics', 'code' => 'ECO', 'department_id' => $department->id, 'status' => 'active'], ['Referer' => route('programs.index')])->assertSessionHas('success');
        $program = Program::withoutGlobalScopes()->firstWhere('code', 'ECO');
        $this->assertSame($college->id, $program->college_id);
        $this->assertSame($department->id, $program->department_id);
        $this->asCollege($college, $admin)->get(route('programs.edit', $program))->assertOk()->assertSee('Economics');
        $this->asCollege($college, $admin)->put(route('programs.update', $program), ['name' => 'Economics', 'code' => 'ECO', 'short_name' => 'ECON', 'status' => 'inactive'], ['Referer' => route('programs.edit', $program)])->assertSessionHas('success');
        $this->asCollege($college, $admin)->delete(route('programs.destroy', $program), [], ['Referer' => route('programs.index')])->assertSessionHas('success');
        $this->assertSoftDeleted('programs', ['id' => $program->id]);
    }

    public function test_super_admin_can_create_with_explicit_college_context(): void
    {
        $college = $this->makeCollege('SUPA');
        $super = $this->superAdminUser();
        $superRole = Role::firstOrCreate(['college_id' => null, 'slug' => Role::SUPER_ADMIN_SLUG], ['name' => 'Super Admin', 'is_system' => true]);
        $super->roles()->attach($superRole->id, ['college_id' => null]);
        $super->colleges()->attach($college->id, ['is_default' => true]);

        // The super admin's permissions are global (permission exists + is_active).
        $this->asCollege($college, $super)->get(route('programs.index'))->assertOk();
        $this->asCollege($college, $super)->post(route('programs.store'), ['name' => 'Data Science', 'code' => 'DS', 'status' => 'active'], ['Referer' => route('programs.index')])->assertSessionHas('success');
        $this->assertDatabaseHas('programs', ['college_id' => $college->id, 'name' => 'Data Science', 'code' => 'DS']);

        // Even for a super admin, tenant scoping is honoured: a super admin with no
        // usable college context is rejected at the tenant boundary (Phase 0 behaviour).
        $homeless = $this->superAdminUser();
        $homeless->roles()->attach($superRole->id, ['college_id' => null]);
        $this->actingAs($homeless)->get(route('programs.index'))->assertForbidden();
    }
}
