<?php

namespace Tests\Feature\Account;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Departments\DepartmentTestHelpers;
use Tests\TestCase;

/**
 * The account panel's "My Profile" screen: `profile.edit` / `profile.update`.
 *
 * What is being pinned here is the boundary, not the layout: a signed-in user may
 * correct their own name and e-mail address, and nothing else. The ERP has no separate
 * self-service user record — the row behind this screen is the same `users` row the
 * Administration module manages, so every field that decides access (status, roles,
 * permissions, college membership, the password itself) has to be refused by name, and
 * the screen has to stay structurally unable to address a second user.
 */
class AccountProfileTest extends TestCase
{
    use DepartmentTestHelpers;

    public function test_a_guest_cannot_reach_the_profile_screen(): void
    {
        $this->get(route('profile.edit'))->assertRedirect(route('login'));
        $this->put(route('profile.update'), ['name' => 'Nobody', 'email' => 'nobody@example.test'])
            ->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_the_profile_screen_opens_with_the_signed_in_users_own_values(): void
    {
        $college = $this->makeCollege('PROF1');
        $user = $this->makeUserWithPermissions($college, ['students.view']);

        $page = $this->asCollege($college, $user)->get(route('profile.edit'))->assertOk()->getContent();

        // Only the two editable fields exist, prefilled from the user's own row.
        $this->assertStringContainsString('name="name" value="'.$user->name.'"', $page);
        $this->assertStringContainsString('name="email" type="email" value="'.$user->email.'"', $page);
        $this->assertStringContainsString('<form method="POST" action="'.route('profile.update').'"', $page);
        $this->assertStringContainsString('name="_token"', $page);
        $this->assertStringContainsString('name="_method" value="PUT"', $page);

        // Their access is shown, as text: no control a user could flip.
        $this->assertStringContainsString($college->code.' Dept Role', $page);
        foreach (['is_active', 'roles', 'college_id', 'password', 'status'] as $field) {
            $this->assertStringNotContainsString('name="'.$field.'"', $page, "The profile form must not expose {$field}.");
        }
        // No photo upload was invented: the user record has no column for it.
        $this->assertStringNotContainsString('type="file"', $page);
        $this->assertStringNotContainsString('enctype="multipart/form-data"', $page);
    }

    public function test_a_user_can_change_their_own_name_and_email(): void
    {
        $college = $this->makeCollege('PROF2');
        $user = $this->makeUserWithPermissions($college, ['students.view']);

        $this->asCollege($college, $user)
            ->put(route('profile.update'), [
                'name' => '  Renamed Person ',
                'email' => 'Renamed.Person@Example.Test',
            ])
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHas('success');

        $user->refresh();
        $this->assertSame('Renamed Person', $user->name);
        // The same trimming/lower-casing the administration user form applies.
        $this->assertSame('renamed.person@example.test', $user->email);

        $audit = AuditLog::query()->where('action', 'account.profile_updated')->sole();
        $this->assertSame($user->getKey(), $audit->user_id);
        $this->assertSame($college->getKey(), $audit->college_id);
        $this->assertSame('profile.update', $audit->route_name);
        $this->assertSame(['name' => $user->name, 'email' => $user->email], $audit->new_values);
    }

    public function test_no_audit_row_is_written_when_nothing_actually_changed(): void
    {
        $college = $this->makeCollege('PROF3');
        $user = $this->makeUserWithPermissions($college, []);

        $this->asCollege($college, $user)
            ->put(route('profile.update'), ['name' => $user->name, 'email' => $user->email])
            ->assertRedirect(route('profile.edit'));

        $this->assertSame(0, AuditLog::query()->where('action', 'account.profile_updated')->count());
    }

    public function test_the_email_must_stay_unique_but_the_users_own_address_is_accepted(): void
    {
        $college = $this->makeCollege('PROF4');
        $user = $this->makeUserWithPermissions($college, []);
        $taken = $this->makeUserWithPermissions($college, []);

        // Re-saving their own address (with other fields, or unchanged) is not a clash.
        $this->asCollege($college, $user)
            ->put(route('profile.update'), ['name' => 'Keeps Address', 'email' => $user->email])
            ->assertSessionHasNoErrors();

        $this->asCollege($college, $user)
            ->put(route('profile.update'), ['name' => 'Keeps Address', 'email' => $taken->email])
            ->assertSessionHasErrors('email');

        $this->assertSame($taken->email, $taken->fresh()->email, 'Another account must be untouched.');
    }

    public function test_protected_fields_are_refused_rather_than_silently_ignored(): void
    {
        $college = $this->makeCollege('PROF5');
        $user = $this->makeUserWithPermissions($college, ['students.view']);
        $role = $user->roles()->sole();
        $nameBefore = $user->name;
        $hashBefore = $user->password;

        $this->asCollege($college, $user)
            ->put(route('profile.update'), [
                'name' => 'Escalated Person',
                'email' => $user->email,
                'is_active' => '0',
                'roles' => [$role->getKey()],
                'college_id' => $college->getKey(),
                'password' => 'an-injected-password',
            ])
            ->assertSessionHasErrors(['is_active', 'roles', 'college_id', 'password']);

        $user->refresh();
        $this->assertSame($nameBefore, $user->name, 'A request carrying a prohibited field must not be applied at all.');
        $this->assertTrue($user->is_active);
        $this->assertSame([$role->getKey()], $user->roles()->pluck('roles.id')->all());
        $this->assertSame($hashBefore, $user->password);
        $this->assertTrue(Hash::check('password', $user->password), 'The injected password must never reach the hash.');
    }

    public function test_the_screen_cannot_be_pointed_at_another_user(): void
    {
        $college = $this->makeCollege('PROF6');
        $user = $this->makeUserWithPermissions($college, []);
        $other = $this->makeUserWithPermissions($college, []);
        $otherName = $other->name;

        // There is no {user} segment on the route, so an identifier can only arrive in
        // the payload — where it is refused instead of deciding whose row is written.
        $this->asCollege($college, $user)
            ->put(route('profile.update'), [
                'name' => 'Impostor',
                'email' => $user->email,
                'id' => $other->getKey(),
                'user_id' => $other->getKey(),
            ])
            ->assertSessionHasErrors(['id', 'user_id']);

        $this->assertSame($otherName, $other->fresh()->name);
        $this->assertStringNotContainsString('Impostor', $user->fresh()->name);
    }

    public function test_a_blank_or_overlong_name_is_rejected(): void
    {
        $college = $this->makeCollege('PROF7');
        $user = $this->makeUserWithPermissions($college, []);

        $this->asCollege($college, $user)
            ->put(route('profile.update'), ['name' => '   ', 'email' => $user->email])
            ->assertSessionHasErrors('name');

        $this->asCollege($college, $user)
            ->put(route('profile.update'), ['name' => str_repeat('a', 256), 'email' => $user->email])
            ->assertSessionHasErrors('name');

        $this->asCollege($college, $user)
            ->put(route('profile.update'), ['name' => 'Fine', 'email' => 'not-an-address'])
            ->assertSessionHasErrors('email');
    }
}
