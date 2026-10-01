<?php

namespace Tests\Feature\Layout;

use App\Models\Permission;
use Tests\Feature\Departments\DepartmentTestHelpers;
use Tests\TestCase;

/**
 * The rail tooltip: the label a collapsed sidebar icon shows on hover.
 *
 * The element is produced by public/js/erp-sidebar.js and painted by
 * public/css/erp-sidebar.css — it exists in neither the Blade nor the built bundle — so
 * this file reads those two files and the rendered markup they operate on, rather than
 * pretending a browser was involved. Each test guards one failure that actually happened:
 *
 *   - the tooltip is appended to <body>, where the sidebar's custom properties are out of
 *     scope, so a `var(--erp-nav-*)` colour silently became the page's own dark text and
 *     the pill read as an empty shape;
 *   - it must sit above the content cards, the sticky header and the account panel, and
 *     must never be clipped by the scrolling navigation list;
 *   - every label must line up on the same column, at the rail's right edge;
 *   - and since the rail hides the labels visually, the text still has to stay in the
 *     accessibility tree, with exactly one copy of each module name in the markup.
 */
class SidebarRailTooltipTest extends TestCase
{
    use DepartmentTestHelpers;

    /**
     * group id => [the display label now, the display label before, a permission the row
     * is still gated on, a route its children still link].
     */
    private const RENAMED_MODULES = [
        'hr' => ['Human Resource (HR)', 'HR / Staff Management', 'faculties.view', 'employees.index'],
        'certificates' => ['Certificate', 'Certificate Management (EC)', 'certificates.view', 'certificates.templates.index'],
        'finance' => ['Fees', 'Finance / Fees', 'fee_structures.view', 'fee-structures.index'],
        'transport' => ['Transport', 'Transport Management', 'vehicles.view', 'transport.dashboard'],
        'library' => ['Library', 'Library Management', 'books.view', 'library.dashboard'],
        'hostel' => ['Hostel', 'Hostel Management', 'hostels.view', 'hostels.dashboard'],
        'communication' => ['Communication', 'Communication Management', 'notices.view', 'communication.dashboard'],
        'inventory' => ['Inventory', 'Inventory / Asset Management', 'inventory_items.view', 'inventory.dashboard'],
        // The administration row is gated by its own link collection, not a perm string.
        'administration-settings' => ['Settings', 'Administration / Settings', null, 'admin.users.index'],
    ];

    /** A dashboard as a user who holds every permission, i.e. with all rows rendered. */
    private function dashboard(): string
    {
        $college = $this->makeCollege('TIP2');
        $user = $this->makeUserWithPermissions($college, Permission::query()->pluck('slug')->all());

        return $this->asCollege($college, $user)->get(route('dashboard'))->assertOk()->getContent();
    }

    private function uri(string $route): string
    {
        return parse_url(route($route), PHP_URL_PATH);
    }

    /** The markup one group label introduces, up to the next group or the list's end. */
    private function block(string $aside, string $label): string
    {
        $start = strpos($aside, 'nav-group__label">'.$label.'<');
        $this->assertNotFalse($start, "The sidebar must render a {$label} group.");
        $next = strpos($aside, 'nav-group__label">', $start + 1);
        $end = $next === false ? (strlen($aside)) : $next;

        return substr($aside, $start, $end - $start);
    }

    public function test_the_tip_paints_itself_because_it_is_rendered_outside_the_sidebar(): void
    {
        $rule = $this->tipRule();

        // No inherited token may decide the colours: on <body> they would be undefined.
        $this->assertStringNotContainsString('var(--erp-nav', $rule, 'The tooltip must not depend on the sidebar tokens it is rendered outside of.');
        $this->assertStringContainsString('system-ui', $rule, 'The typeface must be written out too.');

        $foreground = $this->firstMatch('/(?<![\w-])color:\s*(#[0-9a-fA-F]{6})/', $rule);
        $background = $this->firstMatch('/background-color:\s*(#[0-9a-fA-F]{6})/', $rule);
        $this->assertNotNull($foreground, 'The label needs an explicit colour.');
        $this->assertNotNull($background, 'The pill needs an explicit background.');

        // 16.7:1 in the shipped pair; AAA (7:1) is the floor, so a "nicer" colour swap
        // that quietly drops the contrast is still caught.
        $this->assertGreaterThanOrEqual(
            7.0,
            $this->contrastRatio($foreground, $background),
            "The rail label must be readable: {$foreground} on {$background}."
        );
        $this->assertSame('#f8fafc', strtolower($foreground), 'Near-white is the sidebar text colour.');
        $this->assertSame('#0b1a2c', strtolower($background), 'Solid navy, never transparent: the pill floats over content cards.');
    }

    public function test_the_tip_stacks_above_the_chrome_that_could_cover_it(): void
    {
        $rule = $this->tipRule();
        $zIndex = (int) $this->firstMatch('/z-index:\s*(\d+)/', $rule);

        $this->assertSame('fixed', $this->firstMatch('/position:\s*([a-z]+)/', $rule), 'A fixed layer is the only one a scrolling or transformed ancestor cannot clip.');

        // The account panel the tooltip can be open beside, the drawer and its scrim are
        // the only chrome that stacks: the label has to beat all of them, because a
        // hidden label is worth nothing to the person hovering the icon.
        $this->assertGreaterThan($this->zIndexOf('.erp-user-menu__panel {'), $zIndex, 'The label must clear the open account panel.');
        $chrome = $this->css().' '.(string) file_get_contents(public_path('css/erp-user-menu.css'));
        preg_match_all('/z-index:\s*(\d+)/', $chrome, $layers);
        $this->assertNotEmpty($layers[1]);
        $this->assertSame(max(array_map('intval', $layers[1])), $zIndex, 'The rail label is the topmost layer of the shell, above the sidebar, the scrim and every content card.');
    }

    public function test_the_tip_never_widens_the_sidebar_or_moves_the_layout(): void
    {
        $rule = $this->tipRule();
        $css = $this->css();

        // The tooltip participates in no layout at all.
        $this->assertStringContainsString('position: fixed', $rule);
        $this->assertStringContainsString('pointer-events: none', $rule, 'Hovering the pill must not flicker the row or block the icon.');
        // Long labels wrap inside the pill instead of being cut off or pushing the page
        // sideways: `nowrap` + a fixed max-width is what used to clip "Inventory / Asset
        // Management", and a wide pill is what used to create a horizontal scrollbar.
        $this->assertStringNotContainsString('white-space: nowrap', $rule);
        $this->assertStringContainsString('width: max-content', $rule);
        $this->assertMatchesRegularExpression('/max-width:\s*min\(\s*240px,\s*calc\(100vw/', $rule);
        $this->assertStringContainsString('overflow-wrap: anywhere', $rule);
        // The widths the rest of the shell is measured against are untouched.
        $this->assertStringContainsString('--erp-nav-width: 280px', $css);
        $this->assertStringContainsString('--erp-nav-rail-width: 68px', $css);
    }

    public function test_the_label_is_appended_to_the_body_and_anchored_to_the_rail_edge(): void
    {
        $js = $this->js();

        $this->assertStringContainsString("document.body.appendChild(tip)", $js, 'A tooltip inside .erp-nav__list would be clipped by the scroll container.');
        $this->assertStringNotContainsString('sidebar.appendChild(tip)', $js);
        $this->assertStringContainsString('var rail = sidebar.getBoundingClientRect();', $js, 'Every tip must start at the rail edge, not at the icon box, or the labels stagger row by row.');
        $this->assertStringContainsString('var left = rail.right + TIP_GAP;', $js);
        $this->assertStringContainsString("tip.style.left = left + 'px';", $js);
        // Never off-screen: the offset shrinks until the pill fits.
        $this->assertStringContainsString('window.innerWidth - tipBox.width - TIP_EDGE', $js);
        $this->assertStringContainsString('window.innerHeight - tipBox.height - TIP_EDGE', $js);
    }

    public function test_the_tip_shows_for_dashboard_and_every_module_head_and_hides_with_the_pointer(): void
    {
        $js = $this->js();

        $this->assertStringContainsString("heads.concat([sidebar.querySelector('.nav-dashboard')])", $js, 'The Dashboard row and every collapsible group are the tip targets.');
        $this->assertStringContainsString("target.addEventListener('mouseenter'", $js);
        $this->assertStringContainsString("target.addEventListener('mouseleave', hideTip)", $js);
        $this->assertStringContainsString("target.addEventListener('focus'", $js);
        $this->assertStringContainsString("target.addEventListener('blur', hideTip)", $js);
        $this->assertStringContainsString("scroll.addEventListener('scroll', hideTip", $js, 'A tip must not stay behind while its row scrolls away.');
        // Rail mode only, and only where a rail exists: showTip has to bail out when the
        // sidebar is expanded, and when the viewport is under the desktop breakpoint. The
        // pattern is tolerant of the whitespace around `!`, `||` and the braces because
        // that spacing is formatting; the two operands and the `||` between them are the
        // behaviour, so both are still named.
        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*!\s*isRail\(\s*\)\s*\|\|\s*!\s*desktop\.matches\s*\)\s*\{/',
            $js,
            'The rail tooltip must be suppressed in the expanded sidebar and off desktop widths.'
        );
    }

    public function test_the_tip_reads_the_one_copy_of_each_label_and_leaves_the_accessible_name_alone(): void
    {
        $college = $this->makeCollege('TIP1');
        $user = $this->makeUserWithPermissions($college, ['students.view']);
        $aside = $this->aside($this->asCollege($college, $user)->get(route('dashboard'))->assertOk()->getContent());

        // The label text is the tooltip's source, so it must stay in the markup exactly
        // once — no mirrored `title`, `data-tip` or `aria-label` copy of a module name.
        $this->assertStringNotContainsString('title="', $aside);
        $this->assertStringNotContainsString('data-tip', $aside);
        $this->assertStringNotContainsString('aria-label="Students"', $aside);
        $this->assertSame(1, substr_count($aside, 'nav-group__label">Students<'), 'A module name is stated once, and the tooltip reads that copy.');
        $this->assertStringContainsString('<span class="nav-dashboard__icon">', $aside, 'The Dashboard row keeps its icon in the rail: the tooltip is anchored to it.');

        $this->assertStringContainsString(".nav-group__label, .erp-nav-brand__name", $this->js(), 'The text comes from the rendered label.');
        $this->assertStringContainsString("tip.textContent = text", $this->js());
        // `role="tooltip"` plus a described-by link that is removed again: a dangling id
        // would be read out as an empty description.
        $this->assertStringContainsString("tip.setAttribute('role', 'tooltip')", $this->js());
        $this->assertStringContainsString("target.setAttribute('aria-describedby', 'erp-nav-tip')", $this->js());
        $this->assertStringContainsString("tipTarget.removeAttribute('aria-describedby')", $this->js());
    }

    public function test_the_rail_hides_a_label_visually_without_deleting_its_name(): void
    {
        $css = $this->css();
        $rail = $this->ruleAt('.erp-sidebar[data-rail="true"] .nav-group__label,', $css);

        // `display: none` would take the row's accessible name with it; a clipped pixel
        // leaves the layout and the icon centring alone.
        $this->assertStringNotContainsString('display: none', $rail);
        $this->assertStringContainsString('clip-path: inset(50%)', $rail);
        $this->assertStringContainsString('position: absolute', $rail);
        // The Dashboard row keeps its icon: hiding every child span used to empty it.
        $this->assertStringContainsString('.nav-dashboard > span:not(.nav-dashboard__icon)', $css);
        // The decorative parts still leave the layout entirely.
        $hidden = $this->ruleAt('.erp-sidebar[data-rail="true"] .erp-nav-brand__text,', $css);
        $this->assertStringContainsString('display: none', $hidden);
        $this->assertStringContainsString('.nav-group__chevron', $hidden);
    }

    /**
     * The nine module rows whose display labels were shortened, as the rail renders them.
     *
     * A collapsed sidebar shows nothing but these words — the tooltip reads them straight
     * out of `.nav-group__label` — so this is the whole of what the change was meant to
     * do: each new label exactly once, each old display string nowhere, and the child
     * rows and report names underneath untouched.
     */
    public function test_the_rail_shows_the_shortened_module_labels_and_nothing_else(): void
    {
        $aside = $this->aside($this->dashboard());

        foreach (self::RENAMED_MODULES as [$label, $old]) {
            $this->assertSame(
                1,
                substr_count($aside, 'nav-group__label">'.$label.'<'),
                "{$label} must be rendered exactly once, as the group label the tooltip reads."
            );
            $this->assertStringNotContainsString($old, $aside, "The old display string {$old} must be gone from the navigation.");
        }

        // Report names and child rows keep their own wording: the change stopped at the
        // module row. "Inventory / Asset Reports" is a report, not the Inventory module.
        $this->assertStringContainsString('>Inventory / Asset Reports<', $aside);
        $this->assertStringContainsString('>Certificate Reports<', $aside);
        $this->assertStringContainsString('>Finance Reports<', $aside);
        $this->assertStringContainsString('>Hostel Reports<', $aside);

        // The modules that were never in scope render exactly as they did.
        foreach (['Platform', 'Admissions', 'Students', 'Academics', 'Examinations', 'Reports'] as $label) {
            $this->assertSame(1, substr_count($aside, 'nav-group__label">'.$label.'<'), "{$label} is not one of the renamed modules.");
        }
    }

    /**
     * A display label is the only thing a rename of this kind may move. The group `id`
     * (which the open/collapse state, the search filter and `data-navigation` all key
     * off), the permission the row is gated on, and the route each child links, are
     * identifiers the application is built around — so they are asserted against the
     * source of the row and against the rendered href, not against the label.
     */
    public function test_a_label_change_did_not_move_an_id_a_permission_gate_or_a_route(): void
    {
        $source = (string) file_get_contents(resource_path('views/layouts/sidebar.blade.php'))
            .(string) file_get_contents(resource_path('views/administration/partials/navigation.blade.php'));
        $aside = $this->aside($this->dashboard());

        foreach (self::RENAMED_MODULES as $id => [$label, , $permission, $route]) {
            // The row itself: same component, same id, same icon, new display text.
            $line = null;
            foreach (explode("\n", $source) as $candidate) {
                if (str_contains($candidate, '<x-nav.group id="'.$id.'"')) {
                    $line = trim($candidate);
                    break;
                }
            }
            $this->assertNotNull($line, "The sidebar must still declare a <x-nav.group id=\"{$id}\"> row.");
            $this->assertStringStartsWith(
                '<x-nav.group id="'.$id.'" label="'.$label.'" icon="',
                $line,
                "Only the label of {$id} may differ from the row as it was before the rename."
            );
            if ($permission !== null) {
                $this->assertStringContainsString(
                    'perm="',
                    $line,
                    "The row must still carry its permission gate; a label edit is not a way to widen access."
                );
                $this->assertMatchesRegularExpression(
                    '/perm="[^"]*(?<![a-z_.])'.preg_quote($permission, '/').'(?![a-z_.])/',
                    $line,
                    "{$id} must still be gated on {$permission} (among its other module permissions)."
                );
            }

            // Rendered: the row is still there under its id, and still links the module.
            $this->assertStringContainsString('data-nav-group="'.$id.'"', $aside);
            $this->assertStringContainsString(
                $this->uri($route),
                $this->block($aside, $label),
                "The {$label} row must still link {$route}: a label change moved no route."
            );
        }
    }

    /* helpers ------------------------------------------------------------------------- */

    private function css(): string
    {
        return (string) file_get_contents(public_path('css/erp-sidebar.css'));
    }

    private function js(): string
    {
        return (string) file_get_contents(public_path('js/erp-sidebar.js'));
    }

    /**
     * The authoritative `.erp-nav-tip` rule, i.e. the first one — the later copy inside
     * the print block only hides the element.
     */
    private function tipRule(): string
    {
        return $this->ruleAt('.erp-nav-tip {', $this->css());
    }

    /**
     * The declaration block that starts at the first line matching `$selectorStart`,
     * up to and including its closing brace at column zero.
     */
    private function ruleAt(string $selectorStart, string $css): string
    {
        // A rule that opens with this selector, up to and including its own closing
        // brace. Indentation is allowed: the rail rules live inside the desktop media
        // query, and `.erp-nav-tip` itself does not.
        $pattern = '/^[ \t]*'.preg_quote($selectorStart, '/').'\n(.*?)^[ \t]*\}/ms';
        $matched = preg_match($pattern, $css, $matches);
        $this->assertSame(1, $matched, 'The stylesheet must contain exactly one rule opening with '.$selectorStart.'.');

        return $matches[0];
    }

    private function zIndexOf(string $selectorStart): int
    {
        return (int) $this->firstMatch('/z-index:\s*(\d+)/', $this->ruleAt($selectorStart, (string) file_get_contents(public_path('css/erp-user-menu.css'))));
    }

    private function firstMatch(string $pattern, string $subject): ?string
    {
        return preg_match($pattern, $subject, $matches) ? $matches[1] : null;
    }

    /** WCAG 2.1 relative-luminance contrast ratio between two `#rrggbb` colours. */
    private function contrastRatio(string $one, string $two): float
    {
        $luminance = function (string $hex): float {
            $channels = sscanf($hex, '#%02x%02x%02x');
            $linear = array_map(static function (float $channel): float {
                $channel /= 255;

                return $channel <= 0.03928
                    ? $channel / 12.92
                    : (($channel + 0.055) / 1.055) ** 2.4;
            }, $channels);

            return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
        };

        $first = $luminance($one);
        $second = $luminance($two);

        return (max($first, $second) + 0.05) / (min($first, $second) + 0.05);
    }

    private function aside(string $html): string
    {
        $start = strpos($html, '<aside');
        $this->assertNotFalse($start, 'The layout must render the sidebar.');
        $end = strpos($html, '</aside>', $start);
        $this->assertNotFalse($end, 'The sidebar must close.');

        return substr($html, $start, $end - $start);
    }
}
