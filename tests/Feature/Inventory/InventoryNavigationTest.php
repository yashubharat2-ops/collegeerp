<?php

namespace Tests\Feature\Inventory;

use App\Models\College;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Sidebar navigation for the single Inventory / Asset Management section.
 *
 * The 16 operational entries remain in their existing order:
 *   - Inventory Dashboard
 *   - Item Categories
 *   - Items / Assets
 *   - Vendors
 *   - Purchase Orders
 *   - Goods Receipt / Stock In
 *   - Stock Adjustment
 *   - Inventory Transactions
 *   - Item Issue / Allocation
 *   - Asset Assignment
 *   - Asset Return
 *   - Asset Maintenance
 *   - Current Stock, Low Stock, Asset Register and Stock / Transaction Reports
 *     remain under Inventory / Asset Management;
 *   - Inventory / Asset Reports is a single separately permission-gated link
 *     in the shared REPORTS section, after Finance Reports.
 *
 * There must be no separate "Purchase & Stock", "Asset Management", or
 * "Phase 4" headings. Each entry has its own view permission.
 */
class InventoryNavigationTest extends TestCase
{
    use InventoryTestHelpers;

    private const ALL_ENTRIES = [
        'Inventory Dashboard'     => ['inventory_dashboard.view',       'inventory.dashboard'],
        'Item Categories'         => ['inventory_categories.view',      'inventory-categories.index'],
        'Items / Assets'          => ['inventory_items.view',           'inventory-items.index'],
        'Vendors'                 => ['inventory_vendors.view',         'inventory-vendors.index'],
        'Purchase Orders'         => ['inventory_purchase_orders.view', 'inventory-purchase-orders.index'],
        'Goods Receipt / Stock In'=> ['inventory_goods_receipts.view',  'inventory-goods-receipts.index'],
        'Stock Adjustment'        => ['inventory_stock_adjustments.view','inventory-stock-adjustments.index'],
        'Inventory Transactions'  => ['inventory_transactions.view',    'inventory-transactions.index'],
        'Item Issue / Allocation' => ['inventory_issues.view',          'inventory-issues.index'],
        'Asset Assignment'        => ['inventory_assignments.view',     'inventory-assignments.index'],
        'Asset Return'            => ['inventory_asset_returns.view',   'inventory-asset-returns.index'],
        'Asset Maintenance'       => ['inventory_maintenance.view',     'inventory-maintenances.index'],
        'Current Stock'           => ['inventory_current_stock.view',   'inventory-current-stock.index'],
        'Low Stock'               => ['inventory_low_stock.view',       'inventory-low-stock.index'],
        'Asset Register'          => ['inventory_asset_register.view',  'inventory-asset-register.index'],
        'Stock / Transaction Reports' => ['inventory_stock_reports.view', 'inventory-stock-reports.index'],
    ];

    /** Permission slugs that gate the outer section (any one of these shows it). */
    private const SECTION_GATE_PERMISSIONS = [
        'inventory_dashboard.view',
        'inventory_categories.view',
        'inventory_items.view',
        'inventory_vendors.view',
        'inventory_purchase_orders.view',
        'inventory_goods_receipts.view',
        'inventory_stock_adjustments.view',
        'inventory_transactions.view',
        'inventory_stock.view',
        'inventory_issues.view',
        'inventory_assignments.view',
        'inventory_asset_returns.view',
        'inventory_maintenance.view',
        'inventory_current_stock.view',
        'inventory_low_stock.view',
        'inventory_asset_register.view',
        'inventory_stock_reports.view',
    ];

    /** Forbidden sub-headings that must NOT appear in the sidebar. */
    private const FORBIDDEN_HEADINGS = [
        'Purchase &amp; Stock',
        'Purchase & Stock',
        '>Purchase</div>',
        '>Stock</div>',
        'Inventory Operations',
        'Stock Management',
        '>Asset Management</div>',
        '>Reports</div>',
        '>Phase 3</div>',
        '>Phase 4</div>',
    ];

    private const HEADING = '>Inventory / Asset Management</div>';

    /** Any sidebar section heading (they share the uppercase heading styling). */
    private const SECTION_HEADING_PATTERN = '/<div class="[^"]*\buppercase tracking-widest\b[^"]*">/';

    private function href(string $routeName): string
    {
        return 'href="'.route($routeName).'"';
    }

    /** Extract the HTML chunk between the Inventory heading and the next section heading. */
    private function inventoryGroup(string $html): string
    {
        $start = strpos($html, self::HEADING);
        $this->assertNotFalse($start, 'Missing Inventory / Asset Management heading.');

        $after = $start + strlen(self::HEADING);
        // The group ends at the NEXT sidebar section heading, whichever it is
        // (e.g. REPORTS, when the user may see it, or Platform). Slicing up to
        // Platform would wrongly count the REPORTS links as Inventory entries.
        $matched = preg_match(self::SECTION_HEADING_PATTERN, $html, $next, PREG_OFFSET_CAPTURE, $after);
        $this->assertSame(1, $matched, 'Another sidebar section must follow Inventory.');

        return substr($html, $after, $next[0][1] - $after);
    }

    public function test_the_inventory_section_lists_exactly_the_16_operational_entries_in_order(): void
    {
        $college = $this->makeCollege('INAV1');
        $user = $this->makeUserWithPermissions($college, array_column(self::ALL_ENTRIES, 0));

        $html = $this->asCollege($college, $user)->get(route('dashboard'))->assertOk()->getContent();

        // 1. Exactly one Inventory / Asset Management section.
        $this->assertSame(1, substr_count($html, self::HEADING), 'Exactly one Inventory / Asset Management heading.');
        $this->assertSame(1, substr_count($html, '<aside'), 'One sidebar.');

        // 2. No forbidden sub-headings remain.
        foreach (self::FORBIDDEN_HEADINGS as $bad) {
            $this->assertStringNotContainsString($bad, $html, "Forbidden heading must not appear: {$bad}");
        }

        // 3. All 16 operational entries live inside that single section.
        $group = $this->inventoryGroup($html);
        $this->assertSame(16, substr_count($group, 'class="nav-link"'), 'Inventory section must contain exactly 16 entries.');

        // 4. Order matches spec.
        $cursor = -1;
        foreach (self::ALL_ENTRIES as $label => [$permission, $route]) {
            $position = strpos($group, $this->href($route));
            $this->assertNotFalse($position, "Missing entry: {$label}");
            $this->assertStringContainsString($label, $group);
            $this->assertGreaterThan($cursor, $position, "{$label} out of order.");
            $cursor = $position;
        }

        // The old Stock Movements nav entry stays absent.
        $this->assertStringNotContainsString('Stock Movements', $html);
    }

    public function test_each_entry_is_gated_on_its_own_view_permission(): void
    {
        $college = $this->makeCollege('INAV2');

        foreach (self::ALL_ENTRIES as $label => [$permission, $route]) {
            $user = $this->makeUserWithPermissions($college, [$permission]);
            $html = $this->asCollege($college, $user)->get(route('dashboard'))->assertOk()->getContent();

            $this->assertSame(1, substr_count($html, self::HEADING), "Section must appear for permission {$permission}.");

            // None of the forbidden headings may appear.
            foreach (self::FORBIDDEN_HEADINGS as $bad) {
                $this->assertStringNotContainsString($bad, $html, "Forbidden heading must not appear when user only has {$permission}: {$bad}");
            }

            $group = $this->inventoryGroup($html);
            $this->assertSame(1, substr_count($group, 'class="nav-link"'), "Only {$label} may render for {$permission}.");
            $this->assertStringContainsString($this->href($route), $group);
        }
    }

    public function test_the_section_is_hidden_without_any_inventory_view_permission(): void
    {
        $college = $this->makeCollege('INAV3');
        $stranger = $this->makeUserWithPermissions($college, ['students.view', 'hostels.view']);

        $response = $this->asCollege($college, $stranger)
            ->get(route('dashboard'))
            ->assertOk();

        $response->assertDontSee('Inventory / Asset Management', false);
        foreach (self::FORBIDDEN_HEADINGS as $bad) {
            // assertDontSee with escaped=false; these are raw substrings we want absent.
            $this->assertStringNotContainsString(
                html_entity_decode($bad, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                $response->getContent(),
                "Forbidden heading must not appear for a non-inventory user: {$bad}"
            );
        }

        foreach (self::ALL_ENTRIES as $label => [$permission, $route]) {
            $response->assertDontSee($this->href($route), false);
        }
        $response->assertDontSee($this->href('inventory-reports.index'), false);

        // Write/delete permissions alone (no *.view) must not surface the nav.
        $writer = $this->makeUserWithPermissions($college, ['inventory_items.create', 'inventory_vendors.delete']);
        $html = $this->asCollege($college, $writer)->get(route('dashboard'))->assertOk()->getContent();
        $this->assertStringNotContainsString(self::HEADING, $html);
    }

    public function test_the_section_sits_between_communication_and_platform(): void
    {
        $college = $this->makeCollege('INAV4');
        $super = $this->makeSuperAdmin($college);

        $html = $this->asCollege($college, $super)->get(route('dashboard'))->assertOk()->getContent();

        $communication = strpos($html, '>Communication Management</div>');
        $inventory     = strpos($html, self::HEADING);
        $platform      = strrpos($html, '>Platform</div>');

        $this->assertNotFalse($communication);
        $this->assertNotFalse($inventory);
        $this->assertNotFalse($platform);
        $this->assertGreaterThan($communication, $inventory, 'Inventory must come after Communication.');
        $this->assertGreaterThan($inventory, $platform, 'Platform must come after Inventory.');

        // Exactly one Inventory heading, exactly 16 operational entries in it.
        $this->assertSame(1, substr_count($html, self::HEADING));
        $this->assertSame(16, substr_count($this->inventoryGroup($html), 'class="nav-link"'));

        foreach (self::FORBIDDEN_HEADINGS as $bad) {
            $this->assertStringNotContainsString($bad, $html, "Forbidden heading must be absent for super admin: {$bad}");
        }

        $this->assertStringNotContainsString('Stock Movements', $this->inventoryGroup($html));

        // Phase 3 tables still exist; Phase 4 introduces no tables.
        foreach (['inventory_issues', 'inventory_assignments', 'inventory_maintenances'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} belongs to Phase 3 and must exist.");
        }

        foreach (['inventory_reports', 'assets'] as $table) {
            $this->assertFalse(Schema::hasTable($table), "{$table} must not be created for reports or assets.");
        }
    }

    public function test_inventory_asset_reports_is_in_the_shared_reports_section_after_finance(): void
    {
        $college = $this->makeCollege('INAV5');
        $user = $this->makeUserWithPermissions($college, [
            'finance_reports.view',
            'inventory_reports.view',
            'hr_reports.view',
        ]);

        $html = $this->asCollege($college, $user)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringNotContainsString(self::HEADING, $html, 'The reports permission must not reveal operational Inventory navigation.');
        $this->assertSame(1, substr_count($html, '>REPORTS</div>'), 'Keep one plain shared REPORTS heading.');
        $this->assertSame(1, substr_count($html, $this->href('inventory-reports.index')));

        $reportsHeading = strpos($html, '>REPORTS</div>');
        $this->assertNotFalse($reportsHeading);
        $afterHeading = $reportsHeading + strlen('>REPORTS</div>');
        $matched = preg_match(self::SECTION_HEADING_PATTERN, $html, $next, PREG_OFFSET_CAPTURE, $afterHeading);
        $this->assertSame(1, $matched, 'The Reports section must end at the next sidebar section.');
        $reportsGroup = substr($html, $afterHeading, $next[0][1] - $afterHeading);

        $finance = strpos($reportsGroup, $this->href('finance-reports.index'));
        $inventory = strpos($reportsGroup, $this->href('inventory-reports.index'));
        $hr = strpos($reportsGroup, $this->href('hr-reports.index'));
        $this->assertNotFalse($finance);
        $this->assertNotFalse($inventory);
        $this->assertNotFalse($hr);
        $this->assertGreaterThan($finance, $inventory, 'Inventory / Asset Reports must follow Finance Reports.');
        $this->assertGreaterThan($inventory, $hr, 'Inventory / Asset Reports must precede HR Reports.');
        $this->assertStringContainsString('Inventory / Asset Reports', $reportsGroup);
        $this->assertSame(3, substr_count($reportsGroup, 'class="nav-link"'));
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
        $response->assertSee($this->href('inventory-reports.index'), false)->assertSee('Inventory / Asset Reports');

        foreach (self::FORBIDDEN_HEADINGS as $bad) {
            $this->assertStringNotContainsString($bad, $response->getContent());
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
            // Phase 3 — the seeded college admin holds the Phase 3 family too.
            'inventory-issues.index', 'inventory-issues.create',
            'inventory-assignments.index', 'inventory-assignments.create',
            'inventory-asset-returns.index',
            'inventory-maintenances.index', 'inventory-maintenances.create',
            // Phase 4 read-only screens.
            'inventory-current-stock.index', 'inventory-low-stock.index',
            'inventory-asset-register.index', 'inventory-stock-reports.index',
            'inventory-reports.index',
            // backward compat routes still work
            'inventory-stock.index',
        ] as $route) {
            $this->asCollege($college, $user)->get(route($route))->assertOk();
        }
    }
}
