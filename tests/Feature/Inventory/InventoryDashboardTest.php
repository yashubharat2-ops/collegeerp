<?php

namespace Tests\Feature\Inventory;

use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\InventoryVendor;
use Tests\TestCase;

/**
 * Inventory / Asset Management — Inventory Dashboard.
 *
 * The dashboard is a read-only aggregation of the Phase 1 masters. Counts must
 * match the active college only, stay gated by inventory_dashboard.view, and
 * render when the module is empty. No dashboard table exists.
 */
class InventoryDashboardTest extends TestCase
{
    use InventoryTestHelpers;

    public function test_the_dashboard_shows_live_counts_for_the_active_college_only(): void
    {
        $college = $this->makeCollege('IDB1');
        $other = $this->makeCollege('IDB1X');
        $user = $this->makeUserWithPermissions($college, ['inventory_dashboard.view']);

        $activeCategory = $this->makeInventoryCategory($college, ['name' => 'Active Category', 'status' => InventoryCategory::STATUS_ACTIVE]);
        $this->makeInventoryCategory($college, ['name' => 'Inactive Category', 'status' => InventoryCategory::STATUS_INACTIVE]);
        $archived = $this->makeInventoryCategory($college, ['name' => 'Archived Category']);
        $archived->delete();

        $this->makeInventoryItem($college, [
            'category_id' => $activeCategory->id,
            'name' => 'Active Consumable',
            'item_type' => InventoryItem::TYPE_CONSUMABLE,
            'status' => InventoryItem::STATUS_ACTIVE,
        ]);
        $this->makeInventoryItem($college, [
            'category_id' => $activeCategory->id,
            'name' => 'Active Asset',
            'item_type' => InventoryItem::TYPE_ASSET,
            'status' => InventoryItem::STATUS_ACTIVE,
        ]);
        $this->makeInventoryItem($college, [
            'category_id' => $activeCategory->id,
            'name' => 'Inactive Asset',
            'item_type' => InventoryItem::TYPE_ASSET,
            'status' => InventoryItem::STATUS_INACTIVE,
        ]);
        $gone = $this->makeInventoryItem($college, ['category_id' => $activeCategory->id, 'name' => 'Deleted Item']);
        $gone->delete();

        $this->makeInventoryVendor($college, ['name' => 'Active Vendor', 'status' => InventoryVendor::STATUS_ACTIVE]);
        $this->makeInventoryVendor($college, ['name' => 'Inactive Vendor', 'status' => InventoryVendor::STATUS_INACTIVE]);
        $archivedVendor = $this->makeInventoryVendor($college, ['name' => 'Archived Vendor']);
        $archivedVendor->delete();

        $foreignCategory = $this->makeInventoryCategory($other, ['name' => 'Foreign Category']);
        $this->makeInventoryItem($other, ['category_id' => $foreignCategory->id, 'name' => 'Foreign Item', 'status' => InventoryItem::STATUS_ACTIVE]);
        $this->makeInventoryVendor($other, ['name' => 'Foreign Vendor']);

        $this->asCollege($college, $user)
            ->get(route('inventory.dashboard'))
            ->assertOk()
            ->assertViewIs('inventory_dashboard.index')
            ->assertViewHas('totalCategories', 2)
            ->assertViewHas('totalItems', 3)
            ->assertViewHas('activeItems', 2)
            ->assertViewHas('totalVendors', 2)
            ->assertViewHas('totals', fn (array $totals) => $totals['consumable_items'] === 1
                && $totals['asset_items'] === 2
                && $totals['active_categories'] === 1
                && $totals['active_vendors'] === 1)
            ->assertSee('Inventory Dashboard')
            ->assertSee('Categories')
            ->assertSee('Items / Assets')
            ->assertSee('Active Items')
            ->assertSee('Vendors')
            ->assertSee('Active Category')
            ->assertSee('Active Consumable')
            ->assertDontSee('Foreign Category')
            ->assertDontSee('Foreign Item')
            ->assertDontSee('Foreign Vendor')
            ->assertDontSee('Archived Category')
            ->assertDontSee('Deleted Item');
    }

    public function test_the_dashboard_renders_an_empty_module(): void
    {
        $college = $this->makeCollege('IDB2');
        $user = $this->makeUserWithPermissions($college, ['inventory_dashboard.view']);

        $this->asCollege($college, $user)
            ->get(route('inventory.dashboard'))
            ->assertOk()
            ->assertViewHas('totalCategories', 0)
            ->assertViewHas('totalItems', 0)
            ->assertViewHas('activeItems', 0)
            ->assertViewHas('totalVendors', 0)
            ->assertSee('Getting started')
            ->assertSee('No inventory recorded yet for this college.');
    }

    public function test_the_dashboard_is_permission_gated(): void
    {
        $college = $this->makeCollege('IDB3');
        $nobody = $this->makeUserWithPermissions($college, ['dashboard.view']);
        $mastersOnly = $this->makeUserWithPermissions($college, ['inventory_categories.view', 'inventory_items.view', 'inventory_vendors.view']);

        $this->get(route('inventory.dashboard'))->assertRedirect(route('login'));
        $this->asCollege($college, $nobody)->get(route('inventory.dashboard'))->assertForbidden();
        $this->asCollege($college, $mastersOnly)->get(route('inventory.dashboard'))->assertForbidden();
    }

    public function test_the_dashboard_writes_nothing_when_visited(): void
    {
        $college = $this->makeCollege('IDB4');
        $super = $this->makeSuperAdmin($college);
        $this->makeInventoryItem($college);

        $count = fn () => $this->withTenant($college, fn () => [
            'categories' => InventoryCategory::query()->count(),
            'items' => InventoryItem::query()->count(),
            'vendors' => InventoryVendor::query()->count(),
        ]);

        $before = $count();

        $this->asCollege($college, $super)->get(route('inventory.dashboard'))->assertOk();

        $this->assertSame($before, $count());
    }
}
