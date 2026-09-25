<?php

namespace Tests\Feature\Inventory;

use App\Models\College;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Sidebar navigation for Inventory / Asset Management — Phases 1 + 2.
 *
 *   - a single "Inventory / Asset Management" section, rendered exactly once;
 *   - it lists exactly the six entries (the four Phase 1 masters plus purchase
 *     orders and stock movements), in order;
 *   - every entry is individually gated on its own view permission;
 *   - the section is hidden entirely without any inventory view permission;
 *   - the phases that are not built yet (issue/return, asset assignment,
 *     maintenance, reports) are not rendered.
 */
class InventoryNavigationTest extends TestCase
{
    use InventoryTestHelpers;

    /**
     * label => [permission, route], in display order.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const ENTRIES = [
        'Inventory Dashboard' => ['inventory_dashboard.view', 'inventory.dashboard'],
        'Item Categories' => ['inventory_categories.view', 'inventory-categories.index'],
        'Items / Assets' => ['inventory_items.view', 'inventory-items.index'],
        'Vendors' => ['inventory_vendors.view', 'inventory-vendors.index'],
        'Purchase Orders' => ['inventory_purchase_orders.view', 'inventory-purchase-orders.index'],
        'Stock Movements' => ['inventory_stock.view', 'inventory-stock.index'],
    ];

    private const FUTURE = [
        'Issue / Return',
        'Asset Assignment',
        'Maintenance',
        'Inventory Reports',
    ];

    private const HEADING = '>Inventory / Asset Management</div>';

    private function href(string $routeName): string
    {
        return 'href="'.route($routeName).'"';
    }

    private function inventoryNavGroup(string $html): string
    {
        $start = strpos($html, self::HEADING);
        $this->assertNotFalse($start, 'The sidebar must have an Inventory / Asset Management group heading.');

        $after = $start + strlen(self::HEADING);
        $end = strpos($html, 'uppercase tracking-widest', $after);

        return $end === false ? substr($html, $after) : substr($html, $after, $end - $after);
    }

    public function test_the_section_lists_exactly_the_six_inventory_entries_in_order(): void
    {
        $college = $this->makeCollege('INAV1');
        $user = $this->makeUserWithPermissions($college, array_column(self::ENTRIES, 0));

        $html = $this->asCollege($college, $user)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, self::HEADING), 'There must be exactly one Inventory / Asset Management section.');
        $this->assertSame(1, substr_count($html, '<aside'), 'The layout must keep one sidebar.');

        $group = $this->inventoryNavGroup($html);
        $this->assertSame(6, substr_count($group, 'class="nav-link"'), 'Exactly six Inventory entries.');

        $cursor = -1;
        foreach (self::ENTRIES as $label => [$permission, $route]) {
            $position = strpos($group, $this->href($route));
            $this->assertNotFalse($position, "Missing inventory entry route: {$label}");
            $this->assertStringContainsString($label, $group);
            $this->assertGreaterThan($cursor, $position, "{$label} is out of order.");
            $cursor = $position;
        }

        foreach (self::FUTURE as $future) {
            $this->assertStringNotContainsString($future, $group, "{$future} must not be rendered.");
        }
    }

    public function test_each_entry_is_gated_on_its_own_view_permission(): void
    {
        $college = $this->makeCollege('INAV2');

        foreach (self::ENTRIES as $label => [$permission, $route]) {
            $user = $this->makeUserWithPermissions($college, [$permission]);
            $group = $this->inventoryNavGroup($this->asCollege($college, $user)->get(route('dashboard'))->assertOk()->getContent());

            $this->assertSame(1, substr_count($group, 'class="nav-link"'), "Only the {$label} entry may render for {$permission}.");
            $this->assertStringContainsString($this->href($route), $group);

            foreach (self::ENTRIES as $otherLabel => [$otherPermission, $otherRoute]) {
                if ($otherRoute !== $route) {
                    $this->assertStringNotContainsString($this->href($otherRoute), $group, "{$otherLabel} must be hidden without {$otherPermission}.");
                }
            }
        }
    }

    public function test_the_section_is_hidden_without_any_inventory_view_permission(): void
    {
        $college = $this->makeCollege('INAV3');
        $stranger = $this->makeUserWithPermissions($college, ['students.view', 'hostels.view']);

        $response = $this->asCollege($college, $stranger)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(self::HEADING, false);

        foreach (self::ENTRIES as $label => [$permission, $route]) {
            $response->assertDontSee($this->href($route), false);
        }

        $writer = $this->makeUserWithPermissions($college, ['inventory_items.create', 'inventory_vendors.delete']);
        $this->asCollege($college, $writer)->get(route('dashboard'))->assertOk()->assertDontSee(self::HEADING, false);
    }

    public function test_the_section_does_not_disturb_communication_and_closes_before_settings(): void
    {
        $college = $this->makeCollege('INAV4');
        $super = $this->makeSuperAdmin($college);

        $html = $this->asCollege($college, $super)->get(route('dashboard'))->assertOk()->getContent();

        $communication = strpos($html, '>Communication Management</div>');
        $inventory = strpos($html, self::HEADING);
        $platform = strrpos($html, '>Platform</div>');

        $this->assertNotFalse($communication);
        $this->assertNotFalse($inventory);
        $this->assertGreaterThan($communication, $inventory);
        $this->assertGreaterThan($inventory, $platform);
        $this->assertSame(6, substr_count($this->inventoryNavGroup($html), 'class="nav-link"'));

        foreach (self::FUTURE as $future) {
            $this->assertStringNotContainsString($future, $this->inventoryNavGroup($html));
        }

        foreach (['inventory_issues', 'asset_assignments', 'inventory_maintenances', 'assets'] as $table) {
            $this->assertFalse(Schema::hasTable($table), "{$table} belongs to a later phase.");
        }
    }

    public function test_a_seeded_college_admin_sees_every_entry_and_can_open_each_screen(): void
    {
        $college = College::query()->where('code', 'DEMO')->firstOrFail();
        $role = Role::query()->where('college_id', $college->id)->where('slug', 'college-admin')->firstOrFail();

        $user = User::create([
            'name' => 'Seeded Inventory Admin',
            'email' => 'seeded-inventory-admin@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->colleges()->attach($college->id, ['is_default' => true]);
        $user->roles()->attach($role->id, ['college_id' => $college->id]);

        $this->assertFalse($user->isSuperAdmin());

        $response = $this->asCollege($college, $user)->get(route('dashboard'))->assertOk();

        foreach (self::ENTRIES as $label => [$permission, $route]) {
            $response->assertSee($this->href($route), false)->assertSee($label);
        }

        foreach ([
            'inventory.dashboard',
            'inventory-categories.index', 'inventory-categories.create',
            'inventory-items.index', 'inventory-items.create',
            'inventory-vendors.index', 'inventory-vendors.create',
            'inventory-purchase-orders.index', 'inventory-purchase-orders.create',
            'inventory-stock.index', 'inventory-stock.create',
        ] as $route) {
            $this->asCollege($college, $user)->get(route($route))->assertOk();
        }
    }
}
