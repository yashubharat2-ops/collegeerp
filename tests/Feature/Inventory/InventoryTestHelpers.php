<?php

namespace Tests\Feature\Inventory;

use App\Models\College;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\InventoryPurchaseOrder;
use App\Models\InventoryPurchaseOrderItem;
use App\Models\InventoryVendor;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Tests\Feature\ExamAttendance\ExamAttendanceTestHelpers;

/**
 * Shared fixtures for Inventory / Asset Management (Phases 1 + 2).
 *
 * Reuses the project-wide college and RBAC fixtures and adds only what this
 * module owns: categories, items/assets, vendors, purchase orders and their
 * lines. Fixtures are created directly (not through HTTP) so each test
 * exercises one behaviour, and are always stamped with an explicit college_id.
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

    /** Every Phase 1 permission slug seeded by DatabaseSeeder. */
    private const INVENTORY_PERMISSIONS = [
        'inventory_dashboard.view',
        'inventory_categories.view', 'inventory_categories.create', 'inventory_categories.update', 'inventory_categories.delete',
        'inventory_items.view', 'inventory_items.create', 'inventory_items.update', 'inventory_items.delete',
        'inventory_vendors.view', 'inventory_vendors.create', 'inventory_vendors.update', 'inventory_vendors.delete',
    ];

    /** Every Phase 2 permission slug (purchase orders and stock) seeded by DatabaseSeeder — final structure. */
    private const INVENTORY_PHASE2_PERMISSIONS = [
        'inventory_purchase_orders.view', 'inventory_purchase_orders.create', 'inventory_purchase_orders.update', 'inventory_purchase_orders.delete', 'inventory_purchase_orders.receive',
        'inventory_stock.view', 'inventory_stock.in', 'inventory_stock.out', 'inventory_stock.adjust',
        'inventory_goods_receipts.view', 'inventory_goods_receipts.create',
        'inventory_stock_adjustments.view', 'inventory_stock_adjustments.create',
        'inventory_transactions.view',
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

    /**
     * A purchase order header. Lines are added with addPurchaseOrderLine();
     * a vendor is created unless one is supplied.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function makeInventoryPurchaseOrder(College $college, array $overrides = []): InventoryPurchaseOrder
    {
        $vendorId = $overrides['vendor_id'] ?? $this->makeInventoryVendor($college)->id;

        return InventoryPurchaseOrder::create(array_merge([
            'college_id' => $college->id,
            'vendor_id' => $vendorId,
            'number' => 'PO-'.Str::upper(Str::random(6)),
            'po_date' => '2026-09-30',
            'status' => InventoryPurchaseOrder::STATUS_DRAFT,
            'total_amount' => '0.00',
        ], $overrides, [
            'vendor_id' => $vendorId,
        ]));
    }

    /**
     * Add a line to a purchase order and keep the header total honest.
     *
     * The header is updated through a scope-free query because fixtures run
     * outside an HTTP request, where CollegeScope would match no rows.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function addPurchaseOrderLine(InventoryPurchaseOrder $order, InventoryItem $item, array $overrides = []): InventoryPurchaseOrderItem
    {
        $line = InventoryPurchaseOrderItem::create(array_merge([
            'college_id' => $order->college_id,
            'purchase_order_id' => $order->id,
            'item_id' => $item->id,
            'quantity' => '10.00',
            'unit_price' => '100.00',
            'received_quantity' => '0.00',
        ], $overrides));

        $total = '0.00';

        foreach ($order->lines()->withoutGlobalScopes()->get() as $row) {
            $total = bcadd($total, bcmul($row->quantity, $row->unit_price, 2), 2);
        }

        InventoryPurchaseOrder::withoutGlobalScopes()->whereKey($order->id)->update(['total_amount' => $total]);
        $order->total_amount = $total;

        return $line;
    }
}
