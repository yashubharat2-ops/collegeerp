<?php

namespace Tests\Feature\Campuses;

use App\Models\Campus;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

class CampusAuthorizationTest extends TestCase
{
    use CampusTestHelpers;

    public function test_authenticated_users_without_permission_forbidden(): void
    {
        $college = $this->makeCollege('CFORB');
        $outsider = $this->makeUserWithPermissions($college, []);
        $campus = Campus::withoutGlobalScopes()->create(['college_id' => $college->id, 'name' => 'Main', 'code' => 'MAIN', 'status' => 'active']);

        $this->asCollege($college, $outsider)->get(route('campuses.index'))->assertForbidden();
        $this->asCollege($college, $outsider)->get(route('campuses.create'))->assertForbidden();
        $this->asCollege($college, $outsider)->post(route('campuses.store'), ['name' => 'New', 'code' => 'NEW', 'status' => 'active'])->assertForbidden();
        $this->asCollege($college, $outsider)->get(route('campuses.edit', $campus))->assertForbidden();
        $this->asCollege($college, $outsider)->put(route('campuses.update', $campus), ['name' => 'Hacked', 'code' => 'MAIN', 'status' => 'active'])->assertForbidden();
        $this->asCollege($college, $outsider)->delete(route('campuses.destroy', $campus))->assertForbidden();

        $this->assertDatabaseHas('campuses', ['id' => $campus->id, 'name' => 'Main']);
    }

    public function test_view_only_user_may_not_write(): void
    {
        $college = $this->makeCollege('CVIEW');
        $viewer = $this->makeUserWithPermissions($college, ['campuses.view']);
        $campus = Campus::withoutGlobalScopes()->create(['college_id' => $college->id, 'name' => 'North', 'code' => 'NORTH', 'status' => 'active']);

        $this->asCollege($college, $viewer)->get(route('campuses.index'))->assertOk()->assertSee('North');
        $this->asCollege($college, $viewer)->get(route('campuses.create'))->assertForbidden();
        $this->asCollege($college, $viewer)->post(route('campuses.store'), ['name' => 'South', 'code' => 'SOUTH', 'status' => 'active'])->assertForbidden();
        $this->asCollege($college, $viewer)->delete(route('campuses.destroy', $campus))->assertForbidden();
    }

    public function test_permission_without_view_still_forbids_index(): void
    {
        $college = $this->makeCollege('CNOVI');
        $creatorOnly = $this->makeUserWithPermissions($college, ['campuses.create']);

        $this->asCollege($college, $creatorOnly)->get(route('campuses.index'))->assertForbidden();
        $this->asCollege($college, $creatorOnly)->post(route('campuses.store'), ['name' => 'East', 'code' => 'EAST', 'status' => 'active'], ['Referer' => route('campuses.index')])->assertSessionHas('success');
        $this->assertDatabaseHas('campuses', ['college_id' => $college->id, 'code' => 'EAST']);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('campuses.index'))->assertRedirect(route('login'));
        $this->get(route('campuses.create'))->assertRedirect(route('login'));
    }

    public function test_college_admin_authorized_for_full_campus_lifecycle(): void
    {
        $college = $this->makeCollege('CCADM');
        $adminRole = Role::create(['college_id' => $college->id, 'name' => 'College Admin', 'slug' => 'college-admin-'.strtolower($college->code), 'is_system' => true, 'is_active' => true]);
        $adminRole->permissions()->sync(\App\Models\Permission::query()->pluck('id')->all());
        $admin = User::create(['name' => 'CA', 'email' => 'ca-'.Str::random(6).'@example.test', 'password' => 'password', 'is_active' => true]);
        $admin->colleges()->attach($college->id, ['is_default' => true]);
        $admin->roles()->attach($adminRole->id, ['college_id' => $college->id]);

        $this->asCollege($college, $admin)->get(route('campuses.index'))->assertOk();
        $this->asCollege($college, $admin)->get(route('campuses.create'))->assertOk()->assertSee('New campus');
        $this->asCollege($college, $admin)->post(route('campuses.store'), ['name' => 'Central', 'code' => 'CENT', 'status' => 'active'], ['Referer' => route('campuses.index')])->assertSessionHas('success');
        $campus = Campus::withoutGlobalScopes()->firstWhere('code', 'CENT');
        $this->asCollege($college, $admin)->get(route('campuses.edit', $campus))->assertOk()->assertSee('Central');
        $this->asCollege($college, $admin)->put(route('campuses.update', $campus), ['name' => 'Central Updated', 'code' => 'CENT', 'status' => 'active'], ['Referer' => route('campuses.edit', $campus)])->assertSessionHas('success');
        $this->asCollege($college, $admin)->delete(route('campuses.destroy', $campus), [], ['Referer' => route('campuses.index')])->assertSessionHas('success');
        $this->assertSoftDeleted('campuses', ['id' => $campus->id]);
    }

    public function test_super_admin_can_create_with_explicit_college_context(): void
    {
        $college = $this->makeCollege('CSUPA');
        $super = $this->superAdminUser();
        $superRole = Role::firstOrCreate(['college_id' => null, 'slug' => Role::SUPER_ADMIN_SLUG], ['name' => 'Super Admin', 'is_system' => true]);
        $super->roles()->attach($superRole->id, ['college_id' => null]);
        $super->colleges()->attach($college->id, ['is_default' => true]);

        $this->asCollege($college, $super)->get(route('campuses.index'))->assertOk();
        $this->asCollege($college, $super)->post(route('campuses.store'), ['name' => 'Super Campus', 'code' => 'SUP', 'status' => 'active'], ['Referer' => route('campuses.index')])->assertSessionHas('success');
        $this->assertDatabaseHas('campuses', ['college_id' => $college->id, 'name' => 'Super Campus', 'code' => 'SUP']);

        $homeless = $this->superAdminUser();
        $homeless->roles()->attach($superRole->id, ['college_id' => null]);
        $this->actingAs($homeless)->get(route('campuses.index'))->assertForbidden();
    }

    public function test_unauthorized_user_receives_correct_response(): void
    {
        $college = $this->makeCollege('CUNAUTH');
        $user = $this->makeUserWithPermissions($college, []);
        $campus = Campus::withoutGlobalScopes()->create(['college_id' => $college->id, 'name' => 'Main', 'code' => 'MAIN', 'status' => 'active']);

        $this->asCollege($college, $user)->get(route('campuses.index'))->assertForbidden();
        $this->asCollege($college, $user)->post(route('campuses.store'), ['name' => 'Hack', 'code' => 'HACK', 'status' => 'active'])->assertForbidden();
        $this->asCollege($college, $user)->get(route('campuses.edit', $campus))->assertForbidden();
        $this->asCollege($college, $user)->put(route('campuses.update', $campus), ['name' => 'Hack', 'code' => 'MAIN', 'status' => 'active'])->assertForbidden();
        $this->asCollege($college, $user)->delete(route('campuses.destroy', $campus))->assertForbidden();

        // Ensure no mutation
        $this->assertDatabaseHas('campuses', ['id' => $campus->id, 'name' => 'Main']);
        $this->assertSame(1, Campus::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }
}
