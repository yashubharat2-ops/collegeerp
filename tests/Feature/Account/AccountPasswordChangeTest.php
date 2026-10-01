<?php

namespace Tests\Feature\Account;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Departments\DepartmentTestHelpers;
use Tests\TestCase;

/**
 * The account panel's "Change Password" screen: `password.change.edit` /
 * `password.change.store`.
 *
 * Three things must stay true at once, and each one is a separate failure mode: the
 * screen is reachable by an authenticated user and nobody else; the current password has
 * to be proved before anything is written, so a hijacked session cannot silently take
 * over an account; and the new password is held only as a hash, under the same
 * twelve-character rule the guest reset flow already enforces. The guest flow is asserted
 * alongside, because it owns the neighbouring route names (`password.request`,
 * `password.update`) and this screen must not disturb it.
 */
class AccountPasswordChangeTest extends TestCase
{
    use DepartmentTestHelpers;

    private const NEW_PASSWORD = 'the-replacement-secret';

    private const OLD_PASSWORD = 'password';

    public function test_a_guest_cannot_reach_the_password_screen(): void
    {
        $this->get(route('password.change.edit'))->assertRedirect(route('login'));
        $this->put(route('password.change.store'), [
            'current_password' => self::OLD_PASSWORD,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertRedirect(route('login'));
    }

    public function test_the_password_screen_opens_and_offers_only_password_fields(): void
    {
        $college = $this->makeCollege('PWD1');
        $user = $this->makeUserWithPermissions($college, ['students.view']);

        $page = $this->asCollege($college, $user)->get(route('password.change.edit'))->assertOk()->getContent();

        foreach (['current_password', 'password', 'password_confirmation'] as $field) {
            $this->assertStringContainsString('name="'.$field.'" type="password"', $page, "{$field} must be a password field.");
        }
        $this->assertStringContainsString('<form method="POST" action="'.route('password.change.store').'"', $page);
        $this->assertStringContainsString('name="_token"', $page);
        $this->assertStringContainsString('name="_method" value="PUT"', $page);
        // The twelve-character rule is stated to the user, not only enforced.
        $this->assertStringContainsString('minlength="12"', $page);
        // Identity fields belong to the profile screen, not here.
        $this->assertStringNotContainsString('name="email"', $page);
        $this->assertStringNotContainsString('name="name"', $page);
    }

    public function test_the_current_password_is_required_and_must_be_correct(): void
    {
        $college = $this->makeCollege('PWD2');
        $user = $this->makeUserWithPermissions($college, []);
        $hashBefore = $user->password;

        $this->asCollege($college, $user)->put(route('password.change.store'), [
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertSessionHasErrors('current_password');

        $this->asCollege($college, $user)->put(route('password.change.store'), [
            'current_password' => 'an-intruder-guess',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertSessionHasErrors('current_password');

        $user->refresh();
        $this->assertSame($hashBefore, $user->password, 'A rejected attempt must leave the hash alone.');
        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $user->password));
    }

    public function test_the_new_password_follows_the_rules_the_applications_already_uses(): void
    {
        $college = $this->makeCollege('PWD3');
        $user = $this->makeUserWithPermissions($college, []);

        // Too short: the same floor ResetPasswordRequest sets.
        $this->asCollege($college, $user)->put(route('password.change.store'), [
            'current_password' => self::OLD_PASSWORD,
            'password' => 'too-short',
            'password_confirmation' => 'too-short',
        ])->assertSessionHasErrors('password');

        // Unconfirmed.
        $this->asCollege($college, $user)->put(route('password.change.store'), [
            'current_password' => self::OLD_PASSWORD,
            'password' => 'a-completely-different-secret',
        ])->assertSessionHasErrors('password');

        // Confirmed but not matching.
        $this->asCollege($college, $user)->put(route('password.change.store'), [
            'current_password' => self::OLD_PASSWORD,
            'password' => 'a-completely-different-secret',
            'password_confirmation' => 'another-completely-different-secret',
        ])->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $user->fresh()->password));
    }

    public function test_the_password_is_changed_the_old_one_stops_working_and_the_session_survives(): void
    {
        $college = $this->makeCollege('PWD4');
        $user = $this->makeUserWithPermissions($college, ['students.view']);

        $this->asCollege($college, $user)
            ->put(route('password.change.store'), [
                'current_password' => self::OLD_PASSWORD,
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])
            ->assertRedirect(route('password.change.edit'))
            ->assertSessionHas('success');

        $user->refresh();
        $email = $user->email;
        $stored = (string) $user->getAttributes()['password'];

        $this->assertNotSame(self::OLD_PASSWORD, $stored);
        $this->assertStringNotContainsString(self::NEW_PASSWORD, $stored, 'A plain-text password must never be stored.');
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $stored));
        $this->assertTrue(Hash::needsRehash($stored) === false, 'The stored value must be a usable hash.');
        $this->assertTrue($user->is_active, 'Changing a password must not change anything else.');

        // Still signed in here, and the existing POST logout keeps working unchanged.
        $this->assertAuthenticatedAs($user);
        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();

        // Through the real sign-in route: the old password is refused, the new one works.
        $this->post(route('login.store'), ['email' => $email, 'password' => self::OLD_PASSWORD])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->post(route('login.store'), ['email' => $email, 'password' => self::NEW_PASSWORD])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();

        $audit = AuditLog::query()->where('action', 'account.password_changed')->sole();
        $this->assertSame($user->getKey(), $audit->user_id);
        $this->assertSame([], $audit->new_values);
        $this->assertSame([], $audit->old_values);
        $this->assertStringNotContainsString(self::NEW_PASSWORD, json_encode($audit->toArray()));
    }

    public function test_reusing_the_current_password_is_refused(): void
    {
        $college = $this->makeCollege('PWD5');
        $user = $this->makeUserWithPermissions($college, []);
        $user->forceFill(['password' => 'the-long-current-one'])->save();

        $this->asCollege($college, $user->fresh())
            ->put(route('password.change.store'), [
                'current_password' => 'the-long-current-one',
                'password' => 'the-long-current-one',
                'password_confirmation' => 'the-long-current-one',
            ])
            ->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('the-long-current-one', $user->fresh()->password));
    }

    public function test_one_account_cannot_change_another_users_password(): void
    {
        $college = $this->makeCollege('PWD6');
        $user = $this->makeUserWithPermissions($college, []);
        $other = $this->makeUserWithPermissions($college, []);
        $otherHash = $other->password;

        $this->asCollege($college, $user)->put(route('password.change.store'), [
            'current_password' => self::OLD_PASSWORD,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
            'user_id' => $other->getKey(),
            'id' => $other->getKey(),
            'email' => $other->email,
        ])->assertSessionHasErrors(['user_id', 'id', 'email']);

        $this->assertSame($otherHash, $other->fresh()->password, 'The other account keeps its own password.');
        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $user->fresh()->password), 'And the attempt is not applied to the attacker either.');
    }

    public function test_the_guest_forgot_and_reset_flow_is_untouched(): void
    {
        // The authenticated screen reuses none of the guest route names.
        foreach (['password.request', 'password.email', 'password.reset', 'password.update'] as $name) {
            $this->assertTrue(Route::has($name), "The guest route {$name} must still exist.");
            $this->assertStringNotContainsString('password.change', $name);
        }
        $this->assertStringContainsString('forgot-password', route('password.request'));
        $this->assertNotSame(route('password.request'), route('password.change.edit'));

        // The guest form still renders without an authenticated user.
        $this->get(route('password.request'))->assertOk();

        // A signed-in visitor is still bounced away from it, and a real reset through the
        // broker still hashes a new password for the account that asked.
        $college = $this->makeCollege('PWD7');
        $user = $this->makeUserWithPermissions($college, []);
        $this->asCollege($college, $user)->get(route('password.request'))->assertRedirect();

        $token = Password::broker()->createToken($user);
        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'a-guest-reset-secret',
            'password_confirmation' => 'a-guest-reset-secret',
        ])->assertRedirect(route('login'))->assertSessionHas('status');

        $this->assertTrue(Hash::check('a-guest-reset-secret', $user->fresh()->password));
        $this->assertFalse(Auth::check(), 'Resetting through the guest flow must not sign anybody in.');
    }
}
