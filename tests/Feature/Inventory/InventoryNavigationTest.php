<?php

namespace Tests\Feature\Inventory;

use App\Models\College;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Sidebar navigation for Inventory / Asset Management — Phases 1 + 2 final.
 *
 * Final structure:
 *   - Inventory / Asset Management: 4 entries (Dashboard, Categories, Items, Vendors)
 *   - Purchase & Stock: 4 entries (Purchase Orders, Goods Receipt / Stock In,
 *     Stock Adjustment, Inventory Transactions)
 *
 *   - Stock Movements menu is removed/renamed.
 *   - Each entry individually gated.
 *   - Future phases still absent.
 */
class InventoryNavigationTest extends TestCase
{
    use InventoryTestHelpers;

    private const PHASE1_ENTRIES = [
        'Inventory Dashboard' => ['inventory_dashboard.view', 'inventory.dashboard'],
        'Item Categories' => ['inventory_categories.view', 'inventory-categories.index'],
        'Items / Assets' => ['inventory_items.view', 'inventory-items.index'],
        'Vendors' => ['inventory_vendors.view', 'inventory-vendors.index'],
    ];

    private const PHASE2_ENTRIES = [
        'Purchase Orders' => ['inventory_purchase_orders.view', 'inventory-purchase-orders.index'],
        'Goods Receipt / Stock In' => ['inventory_goods_receipts.view', 'inventory-goods-receipts.index'],
        'Stock Adjustment' => ['inventory_stock_adjustments.view', 'inventory-stock-adjustments.index'],
        'Inventory Transactions' => ['inventory_transactions.view', 'inventory-transactions.index'],
    ];

    private const ALL_ENTRIES = [
        'Inventory Dashboard' => ['inventory_dashboard.view', 'inventory.dashboard'],
        'Item Categories' => ['inventory_categories.view', 'inventory-categories.index'],
        'Items / Assets' => ['inventory_items.view', 'inventory-items.index'],
        'Vendors' => ['inventory_vendors.view', 'inventory-vendors.index'],
        'Purchase Orders' => ['inventory_purchase_orders.view', 'inventory-purchase-orders.index'],
        'Goods Receipt / Stock In' => ['inventory_goods_receipts.view', 'inventory-goods-receipts.index'],
        'Stock Adjustment' => ['inventory_stock_adjustments.view', 'inventory-stock-adjustments.index'],
        'Inventory Transactions' => ['inventory_transactions.view', 'inventory-transactions.index'],
    ];

    private const FUTURE = [
        'Issue / Return',
        'Asset Assignment',
        'Maintenance',
        'Inventory Reports',
        'Stock Movements',
    ];

    private const HEADING_PHASE1 = '>Inventory / Asset Management</div>';
    private const HEADING_PHASE2 = '>Purchase & Stock</div>';

    private function href(string $routeName): string
    {
        return 'href="'.route($routeName).'"';
    }

    private function navGroup(string $html, string $heading): string
    {
        $start = strpos($html, $heading);
        $this->assertNotFalse($start, "Missing heading: {$heading}");

        $after = $start + strlen($heading);
        $end = strpos($html, 'uppercase tracking-widest', $after);

        return $end === false ? substr($html, $after) : substr($html, $after, $end - $after);
    }

    public function test_the_sections_list_exactly_the_final_entries_in_order(): void
    {
        $college = $this->makeCollege('INAV1');
        $user = $this->makeUserWithPermissions($college, array_column(self::ALL_ENTRIES, 0));

        $html = $this->asCollege($college, $user)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, self::HEADING_PHASE1), 'Exactly one Inventory / Asset Management heading.');
        $this->assertSame(1, substr_count($html, self::HEADING_PHASE2), 'Exactly one Purchase & Stock heading.');
        $this->assertSame(1, substr_count($html, '<aside'), 'One sidebar.');

        $phase1Group = $this->navGroup($html, self::HEADING_PHASE1);
        $phase2Group = $this->navGroup($html, self::HEADING_PHASE2);

        $this->assertSame(4, substr_count($phase1Group, 'class="nav-link"'), 'Phase 1: 4 entries.');
        $this->assertSame(4, substr_count($phase2Group, 'class="nav-link"'), 'Phase 2: Purchase & Stock must contain exactly 4 entries.');

        $cursor = -1;
        foreach (self::PHASE1_ENTRIES as $label => [$permission, $route]) {
            $position = strpos($phase1Group, $this->href($route));
            $this->assertNotFalse($position, "Missing Phase1 entry: {$label}");
            $this->assertStringContainsString($label, $phase1Group);
            $this->assertGreaterThan($cursor, $position, "{$label} out of order in Phase1.");
            $cursor = $position;
        }

        $cursor = -1;
        foreach (self::PHASE2_ENTRIES as $label => [$permission, $route]) {
            $position = strpos($phase2Group, $this->href($route));
            $this->assertNotFalse($position, "Missing Phase2 entry: {$label}");
            $this->assertStringContainsString($label, $phase2Group);
            $this->assertGreaterThan($cursor, $position, "{$label} out of order in Phase2.");
            $cursor = $position;
        }

        foreach (self::FUTURE as $future) {
            // Stock Movements must not appear anywhere in the final nav
            if ($future === 'Stock Movements') {
                $this->assertStringNotContainsString('Stock Movements', $html, 'Stock Movements menu must be removed/renamed.');
            } else {
                $this->assertStringNotContainsString($future, $phase2Group, "{$future} must not be rendered.");
            }
        }
    }

    public function test_each_entry_is_gated_on_its_own_view_permission(): void
    {
        $college = $this->makeCollege('INAV2');

        foreach (self::PHASE1_ENTRIES as $label => [$permission, $route]) {
            $user = $this->makeUserWithPermissions($college, [$permission]);
            $html = $this->asCollege($college, $user)->get(route('dashboard'))->assertOk()->getContent();
            $group = $this->navGroup($html, self::HEADING_PHASE1);
            $this->assertSame(1, substr_count($group, 'class="nav-link"'), "Only {$label} may render for {$permission}.");
            $this->assertStringContainsString($this->href($route), $group);
        }

        foreach (self::PHASE2_ENTRIES as $label => [$permission, $route]) {
            $user = $this->makeUserWithPermissions($college, [$permission]);
            $html = $this->asCollege($college, $user)->get(route('dashboard'))->assertOk()->getContent();
            $group = $this->navGroup($html, self::HEADING_PHASE2);
            $this->assertSame(1, substr_count($group, 'class="nav-link"'), "Only {$label} may render for {$permission}.");
            $this->assertStringContainsString($this->href($route), $group);
        }
    }

    public function test_the_sections_are_hidden_without_any_inventory_view_permission(): void
    {
        $college = $this->makeCollege('INAV3');
        $stranger = $this->makeUserWithPermissions($college, ['students.view', 'hostels.view']);

        $response = $this->asCollege($college, $stranger)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(self::HEADING_PHASE1, false)
            ->assertDontSee(self::HEADING_PHASE2, false);

        foreach (self::ALL_ENTRIES as $label => [$permission, $route]) {
            $response->assertDontSee($this->href($route), false);
        }

        $writer = $this->makeUserWithPermissions($college, ['inventory_items.create', 'inventory_vendors.delete']);
        $html = $this->asCollege($college, $writer)->get(route('dashboard'))->assertOk()->getContent();
        $this->assertStringNotContainsString(self::HEADING_PHASE1, $html);
        $this->assertStringNotContainsString(self::HEADING_PHASE2, $html);
    }

    public function test_the_sections_do_not_disturb_communication_and_close_before_settings(): void
    {
        $college = $this->makeCollege('INAV4');
        $super = $this->makeSuperAdmin($college);

        $html = $this->asCollege($college, $super)->get(route('dashboard'))->assertOk()->getContent();

        $communication = strpos($html, '>Communication Management</div>');
        $inventory = strpos($html, self::HEADING_PHASE1);
        $purchaseStock = strpos($html, self::HEADING_PHASE2);
        $platform = strrpos($html, '>Platform</div>');

        $this->assertNotFalse($communication);
        $this->assertNotFalse($inventory);
        $this->assertNotFalse($purchaseStock);
        $this->assertGreaterThan($communication, $inventory);
        $this->assertGreaterThan($inventory, $purchaseStock);
        $this->assertGreaterThan($purchaseStock, $platform);

        $this->assertSame(4, substr_count($this->navGroup($html, self::HEADING_PHASE1), 'class="nav-link"'));
        $this->assertSame(4, substr_count($this->navGroup($html, self::HEADING_PHASE2), 'class="nav-link"'));

        foreach (self::FUTURE as $future) {
            if ($future === 'Stock Movements') {
                continue;
            }
            $this->assertStringNotContainsString($future, $this->navGroup($html, self::HEADING_PHASE2));
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

        foreach (self::ALL_ENTRIES as $label => [$permission, $route]) {
            $response->assertSee($this->href($route), false)->assertSee($label);
        }

        foreach ([
            'inventory.dashboard',
            'inventory-categories.index', 'inventory-categories.create',
            'inventory-items.index', 'inventory-items.create',
            'inventory-vendors.index', 'inventory-vendors.create',
            'inventory-purchase-orders.index', 'inventory-purchase-orders.create',
            'inventory-goods-receipts.index', 'inventory-goods-receipts.create',
            'inventory-stock-adjustments.index', 'inventory-stock-adjustments.create',
            'inventory-transactions.index',
            // backward compat routes still work
            'inventory-stock.index',
        ] as $route) {
            $this->asCollege($college, $user)->get(route($route))->assertOk();
        }
    }
}
