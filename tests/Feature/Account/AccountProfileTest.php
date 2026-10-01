<?php

namespace Tests\Feature\Account;

use App\Models\AuditLog;
use App\Models\College;
use App\Models\User;
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
 * the screen has to stay structurally unable to address a second user. The write is
 * audited against the college the person is actually working in, and a submit that
 * changes nothing must not leave an audit row behind.
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
        $user = $this->account($college, ['students.view']);

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
        $user = $this->account($college, ['students.view']);
        $stored = ['name' => $user->name, 'email' => $user->email];
        $stampBefore = $user->updated_at;

        $this->asCollege($college, $user)
            ->put(route('profile.update'), [
                'name' => '  Renamed Person ',
                'email' => 'Renamed.Person@Example.Test',
            ])
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHas('success');

        $user->refresh();
        $this->assertSame('Renamed Person', $user->name);
        $this->assertTrue($user->updated_at->greaterThan($stampBefore), 'A real change does write the row.');
        // The same trimming/lower-casing the administration user form applies.
        $this->assertSame('renamed.person@example.test', $user->email);

        $audit = AuditLog::query()->where('action', 'account.profile_updated')->sole();
        $this->assertSame($user->getKey(), $audit->user_id);
        // The college in the audit row is the active tenant context, never null and
        // never the subject's default college: that is what `tenant` on the route buys.
        $this->assertSame($college->getKey(), $audit->college_id);
        $this->assertSame('profile.update', $audit->route_name);
        $this->assertSame('PUT', $audit->method);
        $this->assertSame($this->sorted($stored), $this->sorted($audit->old_values));
        $this->assertSame(
            $this->sorted(['name' => 'Renamed Person', 'email' => 'renamed.person@example.test']),
            $this->sorted($audit->new_values)
        );
    }

    public function test_the_audit_row_carries_the_college_that_is_actually_active(): void
    {
        $home = $this->makeCollege('PROF8');
        $away = $this->makeCollege('PROF9');
        $user = $this->account($home, []);
        $user->colleges()->attach($away->getKey(), ['is_default' => false]);

        // Working in the second college: the person and their own row are the same, but
        // the audit has to say where the change was made.
        $this->asCollege($away, $user)
            ->put(route('profile.update'), ['name' => 'Renamed Elsewhere', 'email' => $user->email])
            ->assertRedirect(route('profile.edit'));

        $audit = AuditLog::query()->where('action', 'account.profile_updated')->sole();
        $this->assertSame($away->getKey(), $audit->college_id, 'The audit must record the active college, not the default one.');
        $this->assertSame($user->getKey(), $audit->user_id);
        $this->assertSame(User::class, $audit->subject_type);
        $this->assertSame($user->getKey(), $audit->subject_id);
        $this->assertSame(['name' => 'Renamed Elsewhere'], $audit->new_values, 'Only the field that moved is audited.');
    }

    public function test_no_audit_row_is_written_when_nothing_actually_changed(): void
    {
        $college = $this->makeCollege('PROF3');
        $user = $this->account($college, []);
        $stored = [$user->name, $user->email];
        $stampBefore = $user->updated_at;

        $this->asCollege($college, $user)
            ->put(route('profile.update'), ['name' => '  '.$user->name.' ', 'email' => '  '.$user->email.' '])
            ->assertRedirect(route('profile.edit'));

        $this->assertSame(0, AuditLog::query()->where('action', 'account.profile_updated')->count());
        $user->refresh();
        $this->assertSame($stored, [$user->name, $user->email]);
        $this->assertTrue($stampBefore->equalTo($user->updated_at), 'A submit that changes nothing must not write the row either.');
    }

    public function test_the_email_must_stay_unique_but_the_users_own_address_is_accepted(): void
    {
        $college = $this->makeCollege('PROF4');
        $user = $this->account($college, []);
        $taken = $this->account($college, []);

        // Re-saving their own address (with other fields, or unchanged) is not a clash.
        $this->asCollege($college, $user)
            ->put(route('profile.update'), ['name' => 'Keeps Address', 'email' => '  '.strtoupper($user->email).' '])
            ->assertSessionHasNoErrors();
        $this->assertSame('Keeps Address', $user->fresh()->name);

        $this->asCollege($college, $user)
            ->put(route('profile.update'), ['name' => 'Takes Another Address', 'email' => $taken->email])
            ->assertSessionHasErrors('email');

        $this->assertSame($taken->email, $taken->fresh()->email, 'Another account must be untouched.');
        $this->assertSame('Keeps Address', $user->fresh()->name, 'A rejected request writes nothing else either.');
        $this->assertSame(
            1,
            AuditLog::query()->where('action', 'account.profile_updated')->count(),
            'The rejected submit must not add an audit row.'
        );
    }

    public function test_protected_fields_are_refused_rather_than_silently_ignored(): void
    {
        $college = $this->makeCollege('PROF5');
        $user = $this->account($college, ['students.view']);
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
        $this->assertTrue(Hash::check('password', $user->password));
        $this->assertSame(0, AuditLog::query()->where('action', 'account.profile_updated')->count());
    }

    public function test_the_screen_cannot_be_pointed_at_another_user(): void
    {
        $college = $this->makeCollege('PROF6');
        $user = $this->account($college, []);
        $other = $this->account($college, []);
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
        $user = $this->account($college, []);

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

    public function test_the_route_names_permissions_and_protection_are_unchanged_by_the_display_labels(): void
    {
        // The module names in the sidebar are display text; this screen never referred to
        // them, and neither its routes nor its verbs moved.
        $this->assertSame('/profile', parse_url(route('profile.edit'), PHP_URL_PATH));
        $this->assertSame('/profile', parse_url(route('profile.update'), PHP_URL_PATH));

        // `auth` plus `tenant`, and nothing else: the tenant middleware is what gives
        // the audit row its college, and no college-scope gate belongs on a profile.
        $middleware = app('router')->getRoutes()->getByName('profile.update')->gatherMiddleware();
        $this->assertContains('auth', $middleware);
        $this->assertContains('tenant', $middleware, 'Without TenantContext the audit row is written with a null college.');
        $this->assertNotContains('tenant.access', $middleware, 'A profile is owned by its user; the college gate belongs to module screens.');

        $college = $this->makeCollege('PROF10');
        $user = $this->account($college, []);

        // Deliberately no `students.view`: a profile is owned, not permissioned, and
        // adding a gate here would be a second RBAC system.
        $this->asCollege($college, $user)->get(route('profile.edit'))->assertOk();
    }

    /* helpers ------------------------------------------------------------------------- */

    /**
     * The account whose profile the test edits, with its address stored the way every
     * write in this application stores it.
     *
     * DepartmentTestHelpers builds the address with Str::random(), whose mixed case this
     * form normalises away on the way in. Left as it is, "same name and same address"
     * would not be the same address and "another account already holds it" would not be
     * a clash, so the two rules under test would be measured against data the ERP cannot
     * produce — the administration form lower-cases addresses exactly the same way.
     */
    private function account(College $college, array $slugs = []): User
    {
        $user = $this->makeUserWithPermissions($college, $slugs);
        // `updated_at` is pushed a week back so that a test can tell a write from a
        // no-op even when both happen inside the same second.
        $user->forceFill([
            'email' => strtolower($user->email),
            'updated_at' => now()->subWeek(),
        ])->save();

        return $user->fresh();
    }

    /**
     * Compare an audit map by content: JSON round-trips keep insertion order, which is
     * not part of what is being asserted, and a map compared key by key is a map that
     * cannot pass by accident of ordering.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function sorted(array $values): array
    {
        ksort($values);

        return $values;
    }
}
