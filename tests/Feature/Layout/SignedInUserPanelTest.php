<?php

namespace Tests\Feature\Layout;

use App\Models\College;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Departments\DepartmentTestHelpers;
use Tests\TestCase;

/**
 * The signed-in user panel in the top-right header.
 *
 * Guards how the application chrome is split now that the account area exists
 * once instead of twice:
 *   - the left sidebar flows branding → search → Dashboard → module navigation
 *     and holds no user block any more;
 *   - the header panel carries the avatar, the name and the e-mail, and the
 *     entries of an account menu;
 *   - every row that has a screen behind it is a plain link — My Profile,
 *     Change Password and Preferences are the account routes in routes/web.php,
 *     Notifications is the real Communication screen and only renders as a link
 *     for a user whose permission lets them open it (same gate as the sidebar
 *     row). No row is decorative and none is invented;
 *   - the one entry with no route, Help & Support, has to look and read disabled
 *     and must say why, instead of linking somewhere that does not exist;
 *   - Logout keeps posting to the existing `logout` route, once per page;
 *   - System Settings stays under Administration / Settings, never in here.
 */
class SignedInUserPanelTest extends TestCase
{
    use DepartmentTestHelpers;

    /** The enabled rows: label => the route name each one must link to. */
    private const LINKED_ROWS = [
        'My Profile' => 'profile.edit',
        'Change Password' => 'password.change.edit',
        'Preferences' => 'preferences.edit',
    ];

    /** The row that has no route in this application: present, but not clickable. */
    private const DISABLED_ROWS = ['Help &amp; Support'];

    /** The menu order, exactly as the component renders it. */
    private const ROWS = ['My Profile', 'Change Password', 'Preferences', 'Notifications', 'Help &amp; Support', 'Logout'];

    private function dashboard(College $college, User $user): string
    {
        return $this->asCollege($college, $user)->get(route('dashboard'))->assertOk()->getContent();
    }

    /** Everything between the panel's opening tag and the end of the disclosure. */
    private function panel(string $html): string
    {
        $start = strpos($html, 'erp-user-menu__panel');
        $this->assertNotFalse($start, 'The header must render the account panel.');
        $end = strpos($html, '</details>', $start);
        $this->assertNotFalse($end, 'The panel must close its disclosure.');

        return substr($html, $start, $end - $start);
    }

    private function aside(string $html): string
    {
        $start = strpos($html, '<aside');
        $this->assertNotFalse($start, 'The layout must render the sidebar.');
        $end = strpos($html, '</aside>', $start);
        $this->assertNotFalse($end, 'The sidebar must close.');

        return substr($html, $start, $end - $start);
    }

    /** The exact opening tag of an enabled row, so no extra attribute can hide in it. */
    private function linkTag(string $routeName): string
    {
        return '<a class="erp-user-menu__item" role="menuitem" tabindex="-1" href="'.route($routeName).'">';
    }

    public function test_the_sidebar_holds_no_copy_of_the_signed_in_user(): void
    {
        $college = $this->makeCollege('PANEL1');
        $user = $this->makeUserWithPermissions($college, ['students.view']);
        $aside = $this->aside($this->dashboard($college, $user));

        $this->assertSame(1, substr_count($aside, '<aside'), 'One sidebar.');
        $this->assertStringNotContainsString('erp-nav-user', $aside, 'The sidebar user block must be gone, not merely restyled.');
        $this->assertStringNotContainsString($user->email, $aside, 'The sidebar must not print the account e-mail.');
        $this->assertStringNotContainsString($user->name, $aside, 'The sidebar must not print the account name.');

        // The remaining blocks keep the required top-to-bottom order.
        $positions = [];
        foreach (['erp-nav-brand', 'erp-nav-search', 'nav-dashboard', 'erp-nav__section-label'] as $class) {
            $position = strpos($aside, 'class="'.$class.'"');
            $this->assertNotFalse($position, "The sidebar must still render .{$class}");
            $positions[] = $position;
        }
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, 'Sidebar order must be branding → search → Dashboard → Navigation.');
    }

    public function test_the_header_panel_carries_the_signed_in_identity(): void
    {
        $college = $this->makeCollege('PANEL2');
        $user = $this->makeUserWithPermissions($college, ['students.view']);
        $html = $this->dashboard($college, $user);
        $panel = $this->panel($html);

        $this->assertStringContainsString('<details class="erp-user-menu" data-user-menu>', $html);
        $this->assertStringContainsString('aria-label="Account menu for '.$user->name.'"', $html);
        $this->assertStringContainsString($user->email, $panel, 'The panel repeats the e-mail the sidebar used to show.');
        $this->assertStringContainsString($user->name, $panel, 'And the name.');
        // "PANEL2 Staff" → "PS": on the trigger and again in the panel header, which
        // is the block the sidebar used to be — the avatar is never a stock image.
        $this->assertStringContainsString('<span class="erp-user-menu__avatar" aria-hidden="true">PS</span>', $html);
        $this->assertStringContainsString('<span class="erp-user-menu__avatar erp-user-menu__avatar--lg" aria-hidden="true">PS</span>', $panel);
        $this->assertStringNotContainsString('<img', $panel, 'The panel must not invent an avatar URL.');
        // A disclosure, so the rows are reachable before any script has run.
        $this->assertStringNotContainsString('<div class="erp-user-menu__panel" id="erp-user-menu-panel" role="menu" aria-label="Account" hidden>', $html);
        $this->assertStringContainsString('<div class="erp-user-menu__panel" id="erp-user-menu-panel" role="menu" aria-label="Account">', $html);
    }

    public function test_the_panel_offers_every_entry_and_links_only_to_screens_that_exist(): void
    {
        $college = $this->makeCollege('PANEL3');
        $user = $this->makeUserWithPermissions($college, []);
        $panel = $this->panel($this->dashboard($college, $user));

        $previous = -1;
        foreach (self::ROWS as $label) {
            $position = strpos($panel, '>'.$label.'<');
            $this->assertNotFalse($position, "The panel must list {$label}.");
            $this->assertGreaterThan($previous, $position, "{$label} must keep its place in the menu.");
            $previous = $position;
        }

        // The three account rows are ordinary links now, each with its own screen.
        foreach (self::LINKED_ROWS as $label => $name) {
            $this->assertSame(1, substr_count($panel, $this->linkTag($name)), "{$label} must be a link to {$name}.");
            $this->assertSame(1, substr_count($panel, '<span class="erp-user-menu__label">'.$label.'</span>'), "{$label} is stated once.");
        }
        // Notifications is not permitted for this user, so it and Help & Support are the
        // only disabled rows — nothing else may carry the marker.
        $this->assertSame(3, substr_count($panel, 'href='), 'Only the four account screens and Notifications can be links.');
        $this->assertSame(2, substr_count($panel, 'aria-disabled="true"'));
        // Every disabled row explains itself in its tooltip, so the menu never looks
        // broken: the reason is one read away instead of a dead link.
        $this->assertSame(
            2,
            preg_match_all('/<span class="erp-user-menu__item erp-user-menu__item--muted"[^>]*aria-disabled="true"[^>]*title="[^"]{20,}"/', $panel),
            'Each unavailable entry must carry a tooltip explaining why.'
        );
        foreach (self::DISABLED_ROWS as $label) {
            $this->assertMatchesRegularExpression('/<span class="erp-user-menu__item erp-user-menu__item--muted"[^>]*>\s*<span class="erp-user-menu__icon">.*<span class="erp-user-menu__label">'.preg_quote($label, '/').'<\/span>/s', $panel, "{$label} must stay a disabled row.");
        }

        // The guest password flow is not a menu destination for a signed-in user.
        $this->assertStringNotContainsString(route('password.request'), $panel);
        $this->assertStringNotContainsString('forgot-password', $panel);
        $this->assertStringNotContainsString('admin.system-settings', $panel, 'System Settings stays under Administration / Settings.');
        $this->assertStringNotContainsString('>System Settings<', $panel);
        // No help route has been invented for the disabled row either.
        $this->assertFalse(Route::has('help'));
        $this->assertFalse(Route::has('help.index'));
    }

    public function test_every_enabled_row_opens_the_screen_it_points_at(): void
    {
        $college = $this->makeCollege('PANEL4');
        $user = $this->makeUserWithPermissions($college, ['notifications.view']);

        foreach (self::LINKED_ROWS as $routeName) {
            $this->asCollege($college, $user)->get(route($routeName))->assertOk();
        }
        $this->asCollege($college, $user)->get(route('notifications.index'))->assertOk();
    }

    public function test_notifications_is_a_real_link_only_for_users_who_may_open_it(): void
    {
        $college = $this->makeCollege('PANEL5');

        $recipient = $this->makeUserWithPermissions($college, ['notifications.view']);
        $panel = $this->panel($this->dashboard($college, $recipient));
        $this->assertStringContainsString('href="'.route('notifications.index').'"', $panel);
        $this->assertSame(4, substr_count($panel, 'href='), 'Three account rows plus Notifications.');
        $this->assertSame(1, substr_count($panel, 'aria-disabled="true"'), 'Only Help & Support is disabled for this user.');
        // The link is not decorative: the screen it points at really opens.
        $this->asCollege($college, $recipient)->get(route('notifications.index'))->assertOk();

        $outsider = $this->makeUserWithPermissions($college, ['students.view']);
        $outsiderPanel = $this->panel($this->dashboard($college, $outsider));
        $this->assertStringNotContainsString('href="'.route('notifications.index').'"', $outsiderPanel, 'No link may be offered to a screen that answers 403.');
        $this->assertStringContainsString('>Notifications<', $outsiderPanel, 'The entry stays visible, disabled.');
        $this->assertSame(3, substr_count($outsiderPanel, 'href='), 'The account rows are unaffected by the notifications gate.');
        $this->asCollege($college, $outsider)->get(route('notifications.index'))->assertForbidden();
    }

    public function test_logout_keeps_its_existing_post_and_is_offered_once(): void
    {
        $college = $this->makeCollege('PANEL6');
        $user = $this->makeUserWithPermissions($college, []);
        $html = $this->dashboard($college, $user);
        $panel = $this->panel($html);

        $this->assertStringContainsString('<form method="POST" action="'.route('logout').'" class="erp-user-menu__logout"', $panel);
        $this->assertStringContainsString('<input type="hidden" name="_token"', $panel);
        $this->assertStringContainsString('<button type="submit" class="erp-user-menu__item erp-user-menu__item--danger" role="menuitem"', $panel);
        // The duplicated standalone logout control is gone, so exactly one remains.
        $this->assertSame(1, substr_count($html, 'action="'.route('logout').'"'));

        // Behaviour unchanged: the same POST still ends the session.
        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_the_panel_script_follows_the_layouts_external_module_rule(): void
    {
        $college = $this->makeCollege('PANEL7');
        $user = $this->makeUserWithPermissions($college, []);
        $html = $this->dashboard($college, $user);

        preg_match_all('/<script\b[^>]*>/', $html, $tags);
        $this->assertNotEmpty($tags[0]);
        $found = false;
        foreach ($tags[0] as $tag) {
            $this->assertMatchesRegularExpression('/^<script\b(?=[^>]*\btype="module")(?=[^>]*\bsrc="[^"]+\.js")[^>]*>$/', $tag, 'Layout scripts must stay external module scripts: '.$tag);
            $found = $found || str_contains($tag, 'js/erp-user-menu.js');
        }
        $this->assertTrue($found, 'The account panel behaviour must be loaded as that external module script.');
    }
}
