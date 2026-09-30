<?php

namespace Tests\Feature\Administration;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Services\Authorization\RolePermissionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class UsersManagementTest extends TestCase
{
    use AdministrationTestHelpers;

    private const MANAGE = ['users.view', 'users.create', 'users.update', 'users.assign_roles', 'campuses.view'];

    public function test_create_reuses_the_login_identity_membership_and_role_pivots_without_exposing_a_password(): void
    {
        Mail::fake();
        Notification::fake();
        $college = $this->college();
        $actor = $this->actor($college, self::MANAGE);
        $role = $this->role($college, ['campuses.view']);
        $this->asCollege($college, $actor)->post(route('admin.users.store'), [
            'name' => 'Created Account', 'email' => 'created@example.org', 'is_active' => true, 'roles' => [$role->id],
        ])->assertRedirect(route('admin.users.index'))->assertSessionHasNoErrors();
        $user = User::query()->where('email', 'created@example.org')->firstOrFail();
        $this->assertDatabaseHas('user_college', ['user_id' => $user->id, 'college_id' => $college->id, 'is_default' => true]);
        $this->assertDatabaseHas('role_user', ['user_id' => $user->id, 'role_id' => $role->id, 'college_id' => $college->id]);
        $this->assertTrue($user->hasPermission('campuses.view', $college->id));
        $this->assertFalse(Hash::check('password', $user->password));
        $this->assertArrayNotHasKey('password', $user->toArray());
        $event = AuditLog::query()->where('action', 'users.created')->firstOrFail();
        $this->assertSame(['name', 'email', 'is_active'], array_keys($event->new_values));
        $this->asCollege($college, $actor)->get(route('admin.users.index'))->assertOk()->assertDontSee($user->password, false);
        $this->asCollege($college, $actor)->get(route('admin.users.edit', $user))->assertOk()->assertDontSee('name="password"', false);
        Mail::assertNothingSent();
        Notification::assertNothingSent();
    }

    public function test_list_filters_and_all_record_operations_are_tenant_scoped_including_for_super_admin(): void
    {
        $college = $this->college();
        $other = $this->college('FOREIGN');
        $actor = $this->actor($college, self::MANAGE);
        $own = $this->actor($college, [], ['name' => 'Our Visible Account']);
        $foreign = $this->actor($other, [], ['name' => 'Foreign Hidden Account']);
        $this->asCollege($college, $actor)->get(route('admin.users.index', ['search' => 'Visible']))->assertOk()->assertSee($own->email)->assertDontSee($foreign->email);
        $own->update(['is_active' => false]);
        $this->asCollege($college, $actor)->get(route('admin.users.index', ['status' => 'inactive']))->assertOk()->assertSee($own->email)
            ->assertViewHas('users', fn ($users) => $users->total() === 1 && $users->first()->id === $own->id);
        foreach ([$actor, $this->super($college)] as $manager) {
            $this->asCollege($college, $manager)->get(route('admin.users.edit', $foreign))->assertNotFound();
            $this->asCollege($college, $manager)->put(route('admin.users.update', $foreign), ['name' => 'Hijacked', 'email' => 'hijacked@example.org'])->assertNotFound();
            $this->asCollege($college, $manager)->patch(route('admin.users.status', $foreign), ['is_active' => false])->assertNotFound();
            $this->asCollege($college, $manager)->patch(route('admin.users.roles.update', $foreign), ['roles' => []])->assertNotFound();
        }
        $this->assertSame('Foreign Hidden Account', $foreign->refresh()->name);
        $this->assertTrue($foreign->is_active);
    }

    public function test_profile_status_and_role_operations_require_separate_authority(): void
    {
        $college = $this->college();
        $viewer = $this->actor($college, ['users.view']);
        $target = $this->actor($college);
        $this->asCollege($college, $viewer)->get(route('admin.users.index'))->assertOk();
        $this->asCollege($college, $viewer)->get(route('admin.users.create'))->assertForbidden();
        $this->asCollege($college, $viewer)->put(route('admin.users.update', $target), ['name' => 'Denied', 'email' => 'denied@example.org'])->assertForbidden();
        $this->asCollege($college, $viewer)->patch(route('admin.users.status', $target), ['is_active' => false])->assertForbidden();
        $this->asCollege($college, $viewer)->patch(route('admin.users.roles.update', $target), ['roles' => []])->assertForbidden();

        $editor = $this->actor($college, ['users.update']);
        $this->asCollege($college, $editor)->put(route('admin.users.update', $target), ['name' => 'Updated Name', 'email' => 'updated@example.org'])->assertSessionHasNoErrors();
        $this->assertSame('Updated Name', $target->refresh()->name);
        $this->assertNull($target->email_verified_at);
        $this->asCollege($college, $editor)->patch(route('admin.users.roles.update', $target), ['roles' => []])->assertForbidden();
    }

    public function test_deactivation_and_activation_use_the_existing_flag_and_authentication_behavior(): void
    {
        // Credential validation is unchanged; an authorization test need not
        // spend wall-clock time in the guard's configurable failure delay.
        config(['auth.timebox_duration' => 0]);
        $college = $this->college();
        $actor = $this->actor($college, self::MANAGE);
        $target = $this->actor($college, ['campuses.view']);
        $this->asCollege($college, $actor)->patch(route('admin.users.status', $target), ['is_active' => false])->assertSessionHasNoErrors();
        $this->assertFalse($target->refresh()->is_active);
        $this->assertNull($target->remember_token);
        $this->assertFalse($target->hasPermission('campuses.view', $college->id));
        $this->post(route('logout'));
        $this->post(route('login.store'), ['email' => $target->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->asCollege($college, $actor)->patch(route('admin.users.status', $target), ['is_active' => true])->assertSessionHasNoErrors();
        $this->assertTrue($target->refresh()->is_active);
        $this->assertTrue($target->hasPermission('campuses.view', $college->id));
        $this->assertDatabaseHas('audit_logs', ['action' => 'users.status_changed', 'subject_id' => $target->id, 'college_id' => $college->id]);
    }

    public function test_database_backed_sessions_are_revoked_when_supported(): void
    {
        $college = $this->college();
        $actor = $this->actor($college, ['users.update']);
        $target = $this->actor($college);
        DB::table('sessions')->insert(['id' => 'target-session', 'user_id' => $target->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'Test', 'payload' => '', 'last_activity' => time()]);
        config(['session.driver' => 'database']);
        $this->asCollege($college, $actor)->patch(route('admin.users.status', $target), ['is_active' => false])->assertRedirect();
        $this->assertDatabaseMissing('sessions', ['id' => 'target-session']);
    }

    public function test_shared_accounts_and_platform_identities_are_protected_but_scoped_assignments_remain_supported(): void
    {
        $college = $this->college();
        $other = $this->college('SHARED');
        $actor = $this->actor($college, self::MANAGE);
        $target = $this->actor($college);
        $target->colleges()->attach($other->id);
        $siblingRole = $this->role($other, ['students.view']);
        $target->roles()->attach($siblingRole->id, ['college_id' => $other->id]);
        $ownRole = $this->role($college, ['campuses.view']);
        $this->asCollege($college, $actor)->put(route('admin.users.update', $target), ['name' => 'Not Allowed', 'email' => 'shared-change@example.org'])->assertForbidden();
        $this->asCollege($college, $actor)->patch(route('admin.users.status', $target), ['is_active' => false])->assertForbidden();
        $this->asCollege($college, $actor)->patch(route('admin.users.roles.update', $target), ['roles' => [$ownRole->id]])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('role_user', ['user_id' => $target->id, 'role_id' => $siblingRole->id, 'college_id' => $other->id]);

        $super = $this->super($college);
        $this->asCollege($college, $actor)->put(route('admin.users.update', $super), ['name' => 'Hijacked Super', 'email' => 'hijacked-super@example.org'])->assertForbidden();
        $this->asCollege($college, $actor)->patch(route('admin.users.roles.update', $super), ['roles' => []])->assertForbidden();
        $this->asCollege($college, $super)->put(route('admin.users.update', $target), ['name' => 'Authorized Shared Name', 'email' => 'shared@example.org'])->assertSessionHasNoErrors();
        $this->assertSame('Authorized Shared Name', $target->refresh()->name);
    }

    public function test_self_status_self_roles_and_cross_college_or_platform_role_ids_cannot_be_used(): void
    {
        $college = $this->college();
        $other = $this->college('ROLES-OTHER');
        $actor = $this->actor($college, self::MANAGE);
        $target = $this->actor($college);
        $foreign = $this->role($other, ['campuses.view']);
        $global = Role::query()->whereNull('college_id')->where('slug', Role::SUPER_ADMIN_SLUG)->firstOrFail();
        $inactive = $this->role($college, [], ['is_active' => false]);
        $this->asCollege($college, $actor)->patch(route('admin.users.status', $actor), ['is_active' => false])->assertForbidden();
        $this->asCollege($college, $actor)->patch(route('admin.users.roles.update', $actor), ['roles' => []])->assertForbidden();
        foreach ([$foreign, $global, $inactive] as $role) {
            $this->asCollege($college, $actor)->patch(route('admin.users.roles.update', $target), ['roles' => [$role->id]])->assertSessionHasErrors('roles.0');
        }
        $this->assertSame(0, $target->roles()->count());
        $this->assertFalse($target->isSuperAdmin());
    }

    public function test_role_grant_ceiling_rejects_escalation_and_takeover_of_a_more_privileged_target(): void
    {
        $college = $this->college();
        $actor = $this->actor($college, ['users.assign_roles', 'users.create']);
        $target = $this->actor($college);
        $elevated = $this->role($college, ['students.view']);
        $this->asCollege($college, $actor)->patch(route('admin.users.roles.update', $target), ['roles' => [$elevated->id]])->assertSessionHasErrors('roles');
        $this->asCollege($college, $actor)->post(route('admin.users.store'), ['name' => 'Escalation', 'email' => 'escalation@example.org', 'roles' => [$elevated->id]])->assertSessionHasErrors('roles');
        $this->assertDatabaseMissing('users', ['email' => 'escalation@example.org']);
        $target->roles()->attach($elevated->id, ['college_id' => $college->id]);
        $this->asCollege($college, $actor)->patch(route('admin.users.roles.update', $target), ['roles' => []])->assertForbidden();
        $this->assertDatabaseHas('role_user', ['user_id' => $target->id, 'role_id' => $elevated->id]);
    }

    public function test_empty_selection_clears_only_the_active_college_and_global_and_sibling_pivots_survive(): void
    {
        $college = $this->college();
        $other = $this->college('PIVOT-OTHER');
        $super = $this->super($college);
        $target = $this->actor($college);
        $target->colleges()->attach($other->id);
        $global = Role::query()->whereNull('college_id')->where('slug', Role::SUPER_ADMIN_SLUG)->firstOrFail();
        $target->roles()->attach($global->id, ['college_id' => null]);
        $ownRole = $this->role($college);
        $siblingRole = $this->role($other);
        $target->roles()->attach($ownRole->id, ['college_id' => $college->id]);
        $target->roles()->attach($siblingRole->id, ['college_id' => $other->id]);
        $this->asCollege($college, $super)->patch(route('admin.users.roles.update', $target), ['roles' => []])->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('role_user', ['user_id' => $target->id, 'role_id' => $ownRole->id, 'college_id' => $college->id]);
        $this->assertDatabaseHas('role_user', ['user_id' => $target->id, 'role_id' => $global->id, 'college_id' => null]);
        $this->assertDatabaseHas('role_user', ['user_id' => $target->id, 'role_id' => $siblingRole->id, 'college_id' => $other->id]);
        $this->assertTrue($target->isSuperAdmin());
    }

    public function test_existing_assignment_service_preserves_the_full_multi_college_pivot_tuple(): void
    {
        $college = $this->college();
        $other = $this->college('SYSTEM-SHARED');
        $target = $this->actor($college);
        $target->colleges()->attach($other->id);
        $global = Role::create(['college_id' => null, 'name' => 'Global Reader', 'slug' => 'global-reader', 'is_system' => true]);
        $service = app(RolePermissionService::class);
        $service->assign($target, $global, $college->id);
        $service->assign($target, $global, $other->id);
        $service->assign($target, $global, $college->id);
        $this->assertSame(2, DB::table('role_user')->where('user_id', $target->id)->where('role_id', $global->id)->count());
        $this->assertDatabaseHas('role_user', ['user_id' => $target->id, 'role_id' => $global->id, 'college_id' => $college->id]);
        $this->assertDatabaseHas('role_user', ['user_id' => $target->id, 'role_id' => $global->id, 'college_id' => $other->id]);
    }

    public function test_duplicate_emails_do_not_attach_foreign_accounts_and_super_admin_linking_reuses_the_identity(): void
    {
        $college = $this->college();
        $other = $this->college('EXISTING');
        $actor = $this->actor($college, ['users.create']);
        $existing = $this->actor($other, ['campuses.view']);
        $count = User::count();
        $this->asCollege($college, $actor)->post(route('admin.users.store'), ['name' => 'Duplicate', 'email' => $existing->email])->assertSessionHasErrors('email');
        $this->assertFalse($existing->colleges()->whereKey($college->id)->exists());
        $this->asCollege($college, $actor)->get(route('admin.users.link'))->assertForbidden();
        $this->asCollege($college, $actor)->post(route('admin.users.link.store'), ['user_id' => $existing->id])->assertForbidden();
        $super = $this->super($college);
        $this->asCollege($college, $super)->post(route('admin.users.link.store'), ['user_id' => $existing->id])->assertSessionHasNoErrors();
        $this->asCollege($college, $super)->post(route('admin.users.link.store'), ['user_id' => $existing->id])->assertSessionHasNoErrors();
        $this->assertSame($count, User::count());
        $this->assertSame(2, $existing->colleges()->count());
        $this->assertDatabaseHas('user_college', ['user_id' => $existing->id, 'college_id' => $other->id, 'is_default' => true]);
        $this->assertSame(1, $existing->roles()->count());
    }

    public function test_forged_ownership_credentials_and_combined_role_profile_updates_are_rejected(): void
    {
        $college = $this->college();
        $actor = $this->actor($college, self::MANAGE);
        $target = $this->actor($college);
        $this->asCollege($college, $actor)->post(route('admin.users.store'), ['name' => 'Forged', 'email' => 'forged@example.org', 'college_id' => 999, 'password' => 'NeverAcceptThis'])->assertSessionHasErrors(['college_id', 'password']);
        $this->asCollege($college, $actor)->put(route('admin.users.update', $target), ['name' => 'Forged', 'email' => 'forged@example.org', 'roles' => [], 'remember_token' => 'NotAccepted', 'is_active' => false])->assertSessionHasErrors(['roles', 'remember_token', 'is_active']);
        $this->assertDatabaseMissing('users', ['email' => 'forged@example.org']);
        $this->assertTrue($target->refresh()->is_active);
    }
}
