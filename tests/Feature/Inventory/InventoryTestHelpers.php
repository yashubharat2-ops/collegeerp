<?php

namespace Tests\Feature\Inventory;

use App\Models\College;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\InventoryVendor;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Tests\Feature\ExamAttendance\ExamAttendanceTestHelpers;

/**
 * Shared fixtures for Inventory / Asset Management (Phase 1).
 *
 * Reuses the project-wide college and RBAC fixtures and adds only what this
 * module owns: categories, items/assets and vendors. Fixtures are created
 * directly (not through HTTP) so each test exercises one behaviour, and are
 * always stamped with an explicit college_id.
 */
trait InventoryTestHelpers
{
    use ExamAttendanceTestHelpers;

    /**
     * Page renders must not depend on a Vite build. The layout's @vite
     * directive throws when public/build/manifest.json is absent, which is
     * the normal state of a fresh checkout.
     */
    protected function setUpInventoryTestHelpers(): void
    {
        $this->withoutVite();
    }

    /** Every Inventory permission slug seeded by DatabaseSeeder. */
    private const INVENTORY_PERMISSIONS = [
        'inventory_dashboard.view',
        'inventory_categories.view', 'inventory_categories.create', 'inventory_categories.update', 'inventory_categories.delete',
        'inventory_items.view', 'inventory_items.create', 'inventory_items.update', 'inventory_items.delete',
        'inventory_vendors.view', 'inventory_vendors.create', 'inventory_vendors.update', 'inventory_vendors.delete',
    ];

    /**
     * Run a callback with the tenant context bound to $college.
     *
     * Inventory models carry CollegeScope, which resolves to `whereRaw('1 = 0')`
     * when no tenant is active. Assertions that read these models directly
     * (outside an HTTP request) therefore have to pin the tenant explicitly.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function withTenant(College $college, callable $callback): mixed
    {
        $context = app(TenantContext::class);
        $context->set($college);

        try {
            return $callback();
        } finally {
            $context->clear();
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeInventoryCategory(College $college, array $overrides = []): InventoryCategory
    {
        return InventoryCategory::create(array_merge([
            'college_id' => $college->id,
            'name' => 'Category '.Str::upper(Str::random(4)),
            'code' => 'CAT-'.Str::upper(Str::random(6)),
            'description' => 'Test category',
            'status' => InventoryCategory::STATUS_ACTIVE,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeInventoryVendor(College $college, array $overrides = []): InventoryVendor
    {
        return InventoryVendor::create(array_merge([
            'college_id' => $college->id,
            'name' => 'Vendor '.Str::upper(Str::random(4)),
            'code' => 'VEN-'.Str::upper(Str::random(6)),
            'status' => InventoryVendor::STATUS_ACTIVE,
        ], $overrides));
    }

    /**
     * An item (consumable or asset) with a category created if not supplied.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function makeInventoryItem(College $college, array $overrides = []): InventoryItem
    {
        $categoryId = $overrides['category_id'] ?? $this->makeInventoryCategory($college)->id;

        return InventoryItem::create(array_merge([
            'college_id' => $college->id,
            'category_id' => $categoryId,
            'name' => 'Item '.Str::upper(Str::random(4)),
            'code' => 'ITM-'.Str::upper(Str::random(6)),
            'item_type' => InventoryItem::TYPE_CONSUMABLE,
            'unit' => 'pcs',
            'quantity' => '1.00',
            'status' => InventoryItem::STATUS_ACTIVE,
        ], $overrides, [
            'category_id' => $categoryId,
        ]));
    }
}
