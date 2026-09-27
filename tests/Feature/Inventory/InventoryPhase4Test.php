<?php

namespace Tests\Feature\Inventory;

use App\Domain\Inventory\Services\InventoryStockService;
use App\Models\College;
use App\Models\InventoryItem;
use App\Models\InventoryMaintenance;
use App\Models\InventoryStockMovement;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Read-only Phase 4 functionality, independent RBAC and tenant isolation. */
class InventoryPhase4Test extends TestCase
{
    use InventoryTestHelpers;

    private const SCREENS = [
        'inventory_current_stock.view' => 'inventory-current-stock.index',
        'inventory_low_stock.view' => 'inventory-low-stock.index',
        'inventory_asset_register.view' => 'inventory-asset-register.index',
        'inventory_stock_reports.view' => 'inventory-stock-reports.index',
        'inventory_reports.view' => 'inventory-reports.index',
    ];

    private function move(College $college, InventoryItem $item, User $user, string $quantity, string $date, string $direction = 'in'): void
    {
        $this->withTenant($college, fn () => app(InventoryStockService::class)->apply(
            $item,
            $direction === 'in' ? InventoryStockMovement::TYPE_STOCK_IN : InventoryStockMovement::TYPE_STOCK_OUT,
            $quantity,
            $direction,
            $user,
            ['movement_date' => $date],
        ));
    }

    public function test_current_and_low_stock_come_from_the_ledger_including_zero_balance_items(): void
    {
        $college = $this->makeCollege('I4S1');
        $other = $this->makeCollege('I4S1X');
        $user = $this->makeUserWithPermissions($college, ['inventory_current_stock.view', 'inventory_low_stock.view']);
        $foreignUser = $this->makeUserWithPermissions($other, []);
        $category = $this->makeInventoryCategory($college);
        $pens = $this->makeInventoryItem($college, ['name' => 'Blue Pens', 'quantity' => '0.00', 'category_id' => $category->id]);
        $zero = $this->makeInventoryItem($college, ['name' => 'Unmoved Pens', 'quantity' => '42.00', 'category_id' => $category->id]);
        $high = $this->makeInventoryItem($college, ['name' => 'Paper Reams', 'quantity' => '0.00']);
        $inactive = $this->makeInventoryItem($college, ['name' => 'Inactive Pens', 'status' => 'inactive', 'quantity' => '0.00']);
        $asset = $this->makeAsset($college, ['name' => 'Register Laptop']);
        $foreign = $this->makeInventoryItem($other, ['name' => 'Foreign Pens', 'quantity' => '0.00']);

        $this->move($college, $pens, $user, '8.00', '2026-09-10');
        $this->move($college, $pens, $user, '4.00', '2026-09-11', 'out');
        $this->move($college, $high, $user, '9.00', '2026-09-10');
        $this->move($college, $inactive, $user, '2.00', '2026-09-10');
        $this->move($other, $foreign, $foreignUser, '1.00', '2026-09-10');

        // The catalogue cache is deliberately inconsistent: reports must not
        // rely on it instead of the ledger's signed movements.
        InventoryItem::withoutGlobalScopes()->whereKey($pens->id)->update(['quantity' => '999.00']);

        $this->asCollege($college, $user)->get(route('inventory-current-stock.index'))
            ->assertOk()->assertDontSee('Foreign Pens')->assertDontSee('Archived Pens')
            ->assertViewHas('items', function ($page) use ($pens, $zero, $asset): bool {
                $items = collect($page->items())->keyBy('id');
                $this->assertEquals(4, $items[$pens->id]->on_hand);
                $this->assertEquals(0, $items[$zero->id]->on_hand);
                $this->assertEquals(0, $items[$asset->id]->on_hand);

                return true;
            });

        $this->asCollege($college, $user)->get(route('inventory-current-stock.index', ['item_type' => 'asset']))
            ->assertOk()->assertSee('Register Laptop')->assertDontSee('Blue Pens');
        $this->asCollege($college, $user)->get(route('inventory-current-stock.index', ['category_id' => $foreign->category_id]))
            ->assertOk()->assertDontSee('Blue Pens');

        $this->asCollege($college, $user)->get(route('inventory-low-stock.index'))
            ->assertOk()->assertSee('Blue Pens')->assertSee('Unmoved Pens')
            ->assertDontSee('Paper Reams')->assertDontSee('Inactive Pens')->assertDontSee('Register Laptop')
            ->assertDontSee('Foreign Pens');
        $this->asCollege($college, $user)->get(route('inventory-low-stock.index', ['threshold' => '3', 'category_id' => $category->id]))
            ->assertOk()->assertSee('Unmoved Pens')->assertDontSee('Blue Pens');
        $this->asCollege($college, $user)->get(route('inventory-low-stock.index', ['threshold' => '-1']))
            ->assertSessionHasErrors('threshold');
    }

    public function test_stock_report_reconciles_opening_period_flow_and_closing_without_mixing_tenants(): void
    {
        $college = $this->makeCollege('I4R1');
        $other = $this->makeCollege('I4R1X');
        $user = $this->makeUserWithPermissions($college, ['inventory_stock_reports.view']);
        $item = $this->makeInventoryItem($college, ['name' => 'Lab Gloves', 'quantity' => '0.00']);
        $foreign = $this->makeInventoryItem($other, ['name' => 'Foreign Gloves', 'quantity' => '0.00']);
        $foreignUser = $this->makeUserWithPermissions($other, []);

        $this->move($college, $item, $user, '10.00', '2026-09-01');
        $this->move($college, $item, $user, '3.00', '2026-09-10', 'out');
        $this->move($college, $item, $user, '2.00', '2026-09-12');
        $this->move($college, $item, $user, '1.00', '2026-10-01', 'out');
        $this->move($other, $foreign, $foreignUser, '77.00', '2026-09-12');

        $this->asCollege($college, $user)->get(route('inventory-stock-reports.index', ['from' => '2026-09-09', 'to' => '2026-09-30']))
            ->assertOk()->assertSee('Lab Gloves')->assertDontSee('Foreign Gloves')
            ->assertViewHas('rows', function ($page) use ($item): bool {
                $this->assertCount(1, $page->items());
                $row = $page->items()[0];
                $this->assertSame($item->id, $row->item_id);
                $this->assertEquals(10, $row->opening);
                $this->assertEquals(2, $row->received);
                $this->assertEquals(3, $row->issued);
                $this->assertEquals(9, $row->closing);
                $this->assertSame(2, (int) $row->transactions);

                return true;
            });

        $this->asCollege($college, $user)->get(route('inventory-stock-reports.index', ['to' => '2026-09-30']))
            ->assertOk()->assertViewHas('rows', function ($page): bool {
                $this->assertEquals(0, $page->items()[0]->opening);
                $this->assertEquals(9, $page->items()[0]->closing);

                return true;
            });
        $this->asCollege($college, $user)->get(route('inventory-stock-reports.index', ['item_id' => $foreign->id]))
            ->assertOk()->assertDontSee('Foreign Gloves')->assertViewHas('rows', fn ($rows) => $rows->isEmpty());
        $this->asCollege($college, $user)->get(route('inventory-stock-reports.index', ['from' => '2026-10-02', 'to' => '2026-10-01']))
            ->assertSessionHasErrors('to');

        // Historical ledger activity survives an archived catalogue item.
        $this->withTenant($college, fn () => $item->delete());
        $this->asCollege($college, $user)->get(route('inventory-stock-reports.index'))
            ->assertOk()->assertSee('Lab Gloves')->assertSee('(archived)');
    }

    public function test_asset_register_reads_custody_returns_and_maintenance_without_a_new_asset_master(): void
    {
        $college = $this->makeCollege('I4A1');
        $other = $this->makeCollege('I4A1X');
        $user = $this->makeUserWithPermissions($college, ['inventory_asset_register.view']);
        $asset = $this->makeAsset($college, ['name' => 'Lab Laptop']);
        $spare = $this->makeAsset($college, ['name' => 'Spare Laptop']);
        $staff = $this->makeFaculty($college, ['first_name' => 'Devi', 'last_name' => 'Rao']);
        $this->makeInventoryAssignment($college, $asset, ['status' => 'returned', 'returned_on' => '2026-09-18']);
        $this->makeInventoryAssignment($college, $asset, ['assigned_to_type' => 'faculty', 'assigned_to_id' => $staff->id, 'assigned_on' => '2026-09-21']);
        $this->makeInventoryMaintenance($college, $asset, ['status' => InventoryMaintenance::STATUS_COMPLETED, 'completed_on' => '2026-09-19']);
        $this->makeInventoryMaintenance($college, $asset, ['status' => InventoryMaintenance::STATUS_IN_PROGRESS]);
        $this->makeInventoryItem($college, ['name' => 'Consumable Pens']);
        $this->makeAsset($other, ['name' => 'Foreign Laptop']);

        $this->asCollege($college, $user)->get(route('inventory-asset-register.index'))
            ->assertOk()->assertSee('Lab Laptop')->assertSee('Spare Laptop')->assertSee('Devi Rao')
            ->assertDontSee('Consumable Pens')->assertDontSee('Foreign Laptop')
            ->assertDontSee('Custody history')->assertDontSee('inventory-maintenances?item_id', false)
            ->assertViewHas('assets', function ($page) use ($asset, $staff): bool {
                $row = collect($page->items())->firstWhere('id', $asset->id);
                $this->assertSame($staff->id, $row->activeAssignment->assigned_to_id);
                $this->assertSame('2026-09-18', substr((string) $row->last_returned_on, 0, 10));
                $this->assertSame('2026-09-19', substr((string) $row->last_service_on, 0, 10));
                $this->assertSame(1, (int) $row->open_maintenance_count);

                return true;
            });

        $this->asCollege($college, $user)->get(route('inventory-asset-register.index', ['custody' => 'unassigned']))
            ->assertOk()->assertSee('Spare Laptop')->assertDontSee('Lab Laptop');
        $this->asCollege($college, $user)->get(route('inventory-asset-register.index', ['custody' => 'assigned']))
            ->assertOk()->assertSee('Lab Laptop')->assertDontSee('Spare Laptop');

        // Register viewing never grants the Phase 1 editor or Phase 3 workflows.
        foreach (['inventory-items.index', 'inventory-assignments.index', 'inventory-maintenances.index'] as $route) {
            $this->asCollege($college, $user)->get(route($route))->assertForbidden();
        }
        $this->assertFalse(Schema::hasTable('assets'));
    }

    public function test_inventory_reports_roll_up_existing_categories_ledger_custody_and_maintenance(): void
    {
        $college = $this->makeCollege('I4I1');
        $other = $this->makeCollege('I4I1X');
        $user = $this->makeUserWithPermissions($college, ['inventory_reports.view']);
        $category = $this->makeInventoryCategory($college, ['name' => 'Lab Supplies']);
        $foreignCategory = $this->makeInventoryCategory($other, ['name' => 'Foreign Supplies']);
        $low = $this->makeInventoryItem($college, ['category_id' => $category->id, 'quantity' => '0.00']);
        $high = $this->makeInventoryItem($college, ['category_id' => $category->id, 'quantity' => '0.00']);
        $asset = $this->makeAsset($college, ['category_id' => $category->id]);
        $this->makeInventoryAssignment($college, $asset);
        $this->makeInventoryMaintenance($college, $asset);
        $this->makeInventoryMaintenance($college, $asset, ['status' => InventoryMaintenance::STATUS_IN_PROGRESS]);
        $this->move($college, $low, $user, '3.00', '2026-09-10');
        $this->move($college, $high, $user, '9.00', '2026-09-10');
        InventoryItem::withoutGlobalScopes()->whereKey($low->id)->update(['quantity' => '99.00']);
        $this->makeInventoryItem($other, ['category_id' => $foreignCategory->id]);

        $this->asCollege($college, $user)->get(route('inventory-reports.index', ['category_id' => $category->id]))
            ->assertOk()->assertSee('Lab Supplies')->assertDontSee('Foreign Supplies')
            ->assertViewHas('rows', function ($page): bool {
                $this->assertCount(1, $page->items());
                $row = $page->items()[0];
                $this->assertSame(3, (int) $row->items_count);
                $this->assertSame(1, (int) $row->assets_count);
                $this->assertSame(1, (int) $row->assigned_assets_count);
                $this->assertSame(1, (int) $row->open_maintenance_assets_count);
                $this->assertSame(1, (int) $row->low_count);

                return true;
            });
        $this->asCollege($college, $user)->get(route('inventory-reports.index', ['threshold' => '2']))
            ->assertOk()->assertViewHas('rows', function ($page) use ($category): bool {
                $this->assertSame(0, (int) collect($page->items())->firstWhere('id', $category->id)->low_count);

                return true;
            });
        $this->asCollege($college, $user)->get(route('inventory-reports.index', ['category_id' => $foreignCategory->id]))
            ->assertOk()->assertViewHas('rows', fn ($page) => $page->isEmpty());
        $this->assertFalse(Schema::hasTable('inventory_reports'));
    }

    public function test_each_module_has_its_own_view_permission_and_only_get_routes(): void
    {
        $college = $this->makeCollege('I4RB');
        $stranger = $this->makeBystanderUser($college);

        foreach (self::SCREENS as $permission => $route) {
            $this->assertTrue(Route::has($route));
            $this->assertSame(['GET', 'HEAD'], Route::getRoutes()->getByName($route)->methods());
            $this->asCollege($college, $stranger)->get(route($route))->assertForbidden();
            $viewer = $this->makeUserWithPermissions($college, [$permission]);
            $this->asCollege($college, $viewer)->get(route($route))->assertOk();

            foreach (self::SCREENS as $otherPermission => $otherRoute) {
                if ($permission !== $otherPermission) {
                    $this->asCollege($college, $viewer)->get(route($otherRoute))->assertForbidden();
                }
            }
        }

        foreach (['inventory-current-stock', 'inventory-low-stock', 'inventory-asset-register', 'inventory-stock-reports', 'inventory-reports'] as $prefix) {
            $this->assertFalse(Route::has("{$prefix}.store"));
            $this->assertFalse(Route::has("{$prefix}.update"));
            $this->assertFalse(Route::has("{$prefix}.destroy"));
        }
    }
}
