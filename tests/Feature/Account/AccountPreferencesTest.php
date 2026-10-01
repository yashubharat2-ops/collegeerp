<?php

namespace Tests\Feature\Account;

use App\Models\AuditLog;
use App\Models\UserPreference;
use App\Services\Settings\UserPreferenceService;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Departments\DepartmentTestHelpers;
use Tests\TestCase;

/**
 * The account panel's "Preferences" screen: `preferences.edit` / `preferences.update`.
 *
 * The rule this file exists to enforce is that the screen holds nothing the application
 * does not act on and nothing an administrator owns. Two switches are supported, both
 * rendered from UserPreferenceService::DEFINITIONS, and the tests below check every
 * direction of that contract: the values are stored per user, an unsupported key is never
 * written, the settings a college owns are not reachable here, and the saved value really
 * changes the chrome that reads it.
 */
class AccountPreferencesTest extends TestCase
{
    use DepartmentTestHelpers;

    public function test_a_guest_cannot_reach_the_preferences_screen(): void
    {
        $this->get(route('preferences.edit'))->assertRedirect(route('login'));
        $this->put(route('preferences.update'), ['sidebar_rail_by_default' => '1'])->assertRedirect(route('login'));
    }

    public function test_the_screen_lists_exactly_the_supported_switches(): void
    {
        $college = $this->makeCollege('PREF1');
        $user = $this->makeUserWithPermissions($college, ['students.view']);

        $page = $this->asCollege($college, $user)->get(route('preferences.edit'))->assertOk()->getContent();
        $content = $this->content($page);

        foreach (UserPreferenceService::DEFINITIONS as $definition) {
            $this->assertStringContainsString('name="'.$definition['field'].'"', $content);
            $this->assertStringContainsString($definition['label'], $content);
        }
        // Every control on the page is a supported key, and nothing else is offered: no
        // invented theme/language/timezone/notification or academic-year switch.
        $this->assertSame(
            count(UserPreferenceService::DEFINITIONS),
            substr_count($content, 'type="checkbox"'),
            'The form may not offer a control that has no preference behind it.'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/name="(theme|appearance|dark_mode|language|locale|timezone|digest|weekly_email|notification[a-z_]*|default_academic_year)[a-z_]*"/',
            $content,
            'A preference the application cannot honour must not be presented as one.'
        );
        // Nothing is picked from a list either: a select would be a way to invent values.
        $this->assertStringNotContainsString('<select', $content);
        // Administrator-owned configuration is not folded into the account panel.
        $this->assertStringNotContainsString('/admin/settings', $content);
        $this->assertStringNotContainsString('name="college_id"', $content);
    }

    public function test_preferences_are_stored_for_the_signed_in_user_alone(): void
    {
        $college = $this->makeCollege('PREF2');
        $user = $this->makeUserWithPermissions($college, ['students.view']);
        $other = $this->makeUserWithPermissions($college, ['students.view']);

        $this->asCollege($college, $user)
            ->put(route('preferences.update'), [
                'sidebar_rail_by_default' => '1',
                'header_show_identity' => '0',
            ])
            ->assertRedirect(route('preferences.edit'))
            ->assertSessionHas('success');

        $saved = UserPreference::query()->where('user_id', $user->getKey())->pluck('value', 'key')->all();
        $this->assertSame(
            ['sidebar.rail_by_default' => '1', 'header.show_identity' => '0'],
            $saved,
            'Only the supported keys may be written, under their own names.'
        );
        $this->assertSame(2, UserPreference::query()->count());

        // The second account keeps its own defaults: nothing here is shared or copied.
        $this->assertSame(0, UserPreference::query()->where('user_id', $other->getKey())->count());

        $audit = AuditLog::query()->where('action', 'account.preferences_updated')->sole();
        $this->assertSame($user->getKey(), $audit->user_id);
        $this->assertSame(['sidebar.rail_by_default' => false, 'header.show_identity' => true], $audit->old_values);
        $this->assertSame(['sidebar.rail_by_default' => true, 'header.show_identity' => false], $audit->new_values);
    }

    public function test_an_unsupported_key_in_the_payload_is_never_stored(): void
    {
        $college = $this->makeCollege('PREF3');
        $user = $this->makeUserWithPermissions($college, []);

        $this->asCollege($college, $user)->put(route('preferences.update'), [
            'sidebar_rail_by_default' => '1',
            'header_show_identity' => '1',
            'theme' => 'midnight',
            'language' => 'fr',
            'notifications' => ['weekly_digest' => '1'],
            'default_academic_year' => 2026,
        ])->assertRedirect(route('preferences.edit'));

        $this->assertSame(
            ['header.show_identity', 'sidebar.rail_by_default'],
            UserPreference::query()->pluck('key')->sort()->values()->all()
        );
        $this->assertSame(2, UserPreference::query()->count());
        $this->assertSame(0, UserPreference::query()->whereIn('key', ['theme', 'language', 'notifications', 'default_academic_year'])->count());
        $this->assertTrue(app(UserPreferenceService::class)->resolved($user)['sidebar.rail_by_default']);
    }

    public function test_the_form_cannot_write_another_users_row_or_a_college_setting(): void
    {
        $college = $this->makeCollege('PREF4');
        $user = $this->makeUserWithPermissions($college, []);
        $other = $this->makeUserWithPermissions($college, []);
        $settingsBefore = DB::table('institutional_settings')->count();

        $this->asCollege($college, $user)->put(route('preferences.update'), [
            'sidebar_rail_by_default' => '1',
            'header_show_identity' => '1',
            'user_id' => $other->getKey(),
            'college_id' => $college->getKey(),
        ])->assertSessionHasErrors(['user_id', 'college_id']);

        $this->assertSame(0, UserPreference::query()->count(), 'Nothing is written when the payload is refused.');
        $this->assertSame($settingsBefore, DB::table('institutional_settings')->count(), 'The college settings table is not a fallback.');
    }

    public function test_the_saved_preferences_are_the_ones_the_chrome_uses(): void
    {
        $college = $this->makeCollege('PREF5');
        $user = $this->makeUserWithPermissions($college, ['students.view']);

        $before = $this->asCollege($college, $user)->get(route('dashboard'))->assertOk()->getContent();
        $this->assertStringContainsString('data-rail-default="false"', $this->aside($before));
        $this->assertStringContainsString('erp-user-menu__id', $this->header($before), 'Identity display defaults to on.');

        $this->asCollege($college, $user)->put(route('preferences.update'), [
            'sidebar_rail_by_default' => '1',
            'header_show_identity' => '0',
        ])->assertRedirect(route('preferences.edit'));

        $html = $this->asCollege($college, $user)->get(route('dashboard'))->assertOk()->getContent();
        $this->assertStringContainsString('data-rail-default="true"', $this->aside($html), 'The sidebar must start collapsed for this account.');
        $this->assertStringNotContainsString('erp-user-menu__id', $this->header($html));
        $this->assertStringNotContainsString($user->email, $this->header($html), 'With the identity block off, the e-mail must not be printed.');
        // The control itself stays reachable and named, whatever the preference says.
        $this->assertStringContainsString('aria-label="Account menu for '.$user->name.'"', $this->header($html));
        $this->assertStringContainsString('erp-user-menu__avatar', $this->header($html));
        $this->assertStringContainsString('data-rail="false"', $this->aside($html), 'The server always renders the expanded sidebar; the script applies the preference.');

        // Turning it back on restores the identity block for the same account.
        $this->asCollege($college, $user)->put(route('preferences.update'), [
            'sidebar_rail_by_default' => '0',
            'header_show_identity' => '1',
        ])->assertRedirect(route('preferences.edit'));
        $restored = $this->asCollege($college, $user)->get(route('dashboard'))->assertOk()->getContent();
        $this->assertStringContainsString('erp-user-menu__id', $this->header($restored));
        $this->assertStringContainsString($user->email, $this->header($restored));
        $this->assertStringContainsString('data-rail-default="false"', $this->aside($restored));
    }

    public function test_a_second_college_context_never_forks_the_owners_values(): void
    {
        $first = $this->makeCollege('PREF6');
        $second = $this->makeCollege('PREF7');
        $user = $this->makeUserWithPermissions($first, ['students.view']);
        $user->colleges()->attach($second->getKey(), ['is_default' => false]);

        $this->asCollege($first, $user)->put(route('preferences.update'), [
            'sidebar_rail_by_default' => '1',
            'header_show_identity' => '0',
        ])->assertRedirect(route('preferences.edit'));

        // Same person, different active college: their own interface choices follow them,
        // and no per-college copy of the preference is created.
        $page = $this->content($this->asCollege($second, $user)->get(route('preferences.edit'))->assertOk()->getContent());
        $this->assertSame(1, substr_count($page, 'name="sidebar_rail_by_default" value="1"'), 'The rail switch is on.');
        $this->assertSame(1, substr_count($page, 'type="checkbox" value="1" checked'), 'and it is the only checked switch.');
        $this->assertSame(2, UserPreference::query()->count());
    }

    /** Everything the page renders inside <main>: the screen itself, not the chrome. */
    private function content(string $html): string
    {
        return $this->slice($html, '<main', '</main>');
    }

    private function header(string $html): string
    {
        return $this->slice($html, '<header', '</header>');
    }

    private function aside(string $html): string
    {
        return $this->slice($html, '<aside', '</aside>');
    }

    private function slice(string $html, string $open, string $close): string
    {
        $start = strpos($html, $open);
        $this->assertNotFalse($start, "The page must render {$open}.");
        $end = strpos($html, $close, $start);
        $this->assertNotFalse($end, "The {$open} region must close.");

        return substr($html, $start, $end - $start);
    }
}
