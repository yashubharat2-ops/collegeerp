<?php

namespace Tests\Feature\Account;

use App\Models\AuditLog;
use App\Models\College;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
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
        $user = $this->account($college, ['students.view']);

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
        $user = $this->account($college, []);
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
        $this->assertSame(0, AuditLog::query()->where('action', 'account.password_changed')->count(), 'A refused attempt is not a change.');
    }

    public function test_the_new_password_follows_the_rules_the_applications_already_uses(): void
    {
        $college = $this->makeCollege('PWD3');
        $user = $this->account($college, []);

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
        $user = $this->account($college, ['students.view']);
        $email = $user->email;

        $this->asCollege($college, $user)
            ->put(route('password.change.store'), [
                'current_password' => self::OLD_PASSWORD,
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])
            ->assertRedirect(route('password.change.edit'))
            ->assertSessionHas('success');

        $user->refresh();
        $stored = (string) $user->getAttributes()['password'];

        $this->assertNotSame(self::OLD_PASSWORD, $stored);
        $this->assertStringNotContainsString(self::NEW_PASSWORD, $stored, 'A plain-text password must never be stored.');
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $stored));
        $this->assertTrue(Hash::needsRehash($stored) === false, 'The stored value must be a usable hash.');
        $this->assertTrue($user->is_active, 'Changing a password must not change anything else.');
        // The write went through the model's own `hashed` cast, so the value that is
        // stored is a hash of exactly what was typed and nothing else changed.
        $this->assertSame($email, $user->email);

        // Still signed in here, on the same session, and the screens behind it still work.
        $this->assertAuthenticatedAs($user);
        $this->get(route('profile.edit'))->assertOk();
        // The existing POST logout keeps working, unchanged.
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
        $this->assertSame($college->getKey(), $audit->college_id, 'The audit row records the active college.');
        $this->assertSame('password.change.store', $audit->route_name);
        $this->assertSame([], $audit->new_values);
        $this->assertSame([], $audit->old_values);
        $this->assertStringNotContainsString(self::NEW_PASSWORD, (string) json_encode($audit->toArray()));
        $this->assertStringNotContainsString(self::OLD_PASSWORD, (string) json_encode($audit->toArray()));
    }

    public function test_reusing_the_current_password_is_refused(): void
    {
        $college = $this->makeCollege('PWD5');
        $user = $this->account($college, []);
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
        $user = $this->account($college, []);
        $other = $this->account($college, []);
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

    /**
     * The controller used to fire `Illuminate\Auth\Events\PasswordChanged`. This Laravel
     * release ships no such class — a successful change therefore died on a missing
     * class instead of saving the password — so nothing in the application may name it.
     * `PasswordReset` is asserted to exist beside it: the guard is about the one class
     * that does not, not about a blanket ban on events.
     */
    public function test_the_change_does_not_reference_a_framework_event_that_does_not_exist(): void
    {
        $this->assertFalse(
            class_exists('Illuminate\Auth\Events\PasswordChanged'),
            'If this Laravel release ever does ship a PasswordChanged event, this test and the controller should be revisited together.'
        );
        $this->assertTrue(class_exists('Illuminate\Auth\Events\PasswordReset'), 'The neighbouring reset event is the real one.');

        $named = [];
        foreach (File::allFiles(app_path()) as $file) {
            if (str_ends_with($file->getFilename(), '.php')
                && str_contains((string) file_get_contents($file->getPathname()), 'PasswordChanged')) {
                $named[] = $file->getRelativePathname();
            }
        }

        $this->assertSame([], $named, 'No application class may import or dispatch a PasswordChanged event.');
    }

    public function test_the_guest_forgot_flow_is_left_alone_and_bounces_a_signed_in_visitor(): void
    {
        // The authenticated screen reuses none of the guest route names or URIs.
        foreach (['password.request', 'password.email', 'password.reset', 'password.update'] as $name) {
            $this->assertTrue(Route::has($name), "The guest route {$name} must still exist.");
            $this->assertStringNotContainsString('password.change', route($name), "The guest route {$name} must not move under the account screen's URI.");
        }
        $this->assertStringContainsString('forgot-password', route('password.request'));
        $this->assertNotSame(route('password.request'), route('password.change.edit'));
        $this->assertNotSame(route('password.update'), route('password.change.store'));

        // A visitor with no session at all still gets the form.
        $this->get(route('password.request'))->assertOk();

        // A signed-in visitor is still redirected away from it — and stays signed in,
        // which is the point of asserting this before the reset below: the guest group's
        // behaviour is the framework's, not this screen's to change.
        $college = $this->makeCollege('PWD7');
        $user = $this->account($college, []);
        $this->asCollege($college, $user)->get(route('password.request'))->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }

    /**
     * The reset itself, run exactly as a visitor runs it: no session, no actingAs. A
     * reset attempted while signed in is redirected by the guest middleware and would
     * say nothing about the flow either way.
     */
    public function test_a_visitor_can_still_reset_a_forgotten_password(): void
    {
        $college = $this->makeCollege('PWD8');
        $user = $this->account($college, []);

        $this->assertGuest();
        $this->get(route('password.reset', ['token' => 'an-arbitrary-token']))->assertOk();

        $token = Password::broker()->createToken($user);
        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'a-guest-reset-secret',
            'password_confirmation' => 'a-guest-reset-secret',
        ])->assertRedirect(route('login'))->assertSessionHas('status');

        $this->assertTrue(Hash::check('a-guest-reset-secret', $user->fresh()->password));
        $this->assertFalse(Hash::check(self::OLD_PASSWORD, $user->fresh()->password), 'The replaced password stops working, as it did before.');
        $this->assertFalse(Auth::check(), 'Resetting through the guest flow must not sign anybody in.');
        $this->assertGuest();
    }

    /* helpers ------------------------------------------------------------------------- */

    /**
     * An account for this file, with its address stored lower-cased the way every write
     * in the application stores it, so that signing in with `$user->email` after a
     * password change exercises the login route rather than string case.
     */
    private function account(College $college, array $slugs = []): User
    {
        $user = $this->makeUserWithPermissions($college, $slugs);
        $user->forceFill(['email' => strtolower($user->email)])->save();

        return $user->fresh();
    }
}
