<?php

namespace Tests\Feature\Inventory;

use App\Models\College;
use App\Models\Faculty;
use App\Models\InventoryAssignment;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\InventoryIssue;
use App\Models\InventoryMaintenance;
use App\Models\InventoryPurchaseOrder;
use App\Models\InventoryPurchaseOrderItem;
use App\Models\InventoryVendor;
use App\Models\Student;
use App\Models\User;
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

    /** Every Phase 3 permission slug (issue, assignment, return, maintenance) seeded by DatabaseSeeder. */
    private const INVENTORY_PHASE3_PERMISSIONS = [
        'inventory_issues.view', 'inventory_issues.create',
        'inventory_assignments.view', 'inventory_assignments.create',
        'inventory_asset_returns.view', 'inventory_asset_returns.create',
        'inventory_maintenance.view', 'inventory_maintenance.create', 'inventory_maintenance.update',
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

    /**
     * Phase 3 fixtures: people (the recipients / assignees), and the Phase 3
     * rows themselves. Created directly with an explicit college_id, the way
     * the rest of this trait works.
     */

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeStudent(College $college, array $overrides = []): Student
    {
        return Student::create(array_merge([
            'college_id' => $college->id,
            'student_number' => 'STU-'.strtoupper(Str::random(6)),
            'first_name' => 'Asha',
            'middle_name' => null,
            'last_name' => 'Test'.Str::upper(Str::random(3)),
            'email' => strtolower('stu-'.Str::random(8)).'@example.test',
            'status' => 'active',
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeFaculty(College $college, array $overrides = []): Faculty
    {
        return Faculty::create(array_merge([
            'college_id' => $college->id,
            'employee_code' => 'EMP-'.strtoupper(Str::random(6)),
            'first_name' => 'Ravi',
            'middle_name' => null,
            'last_name' => 'Staff'.Str::upper(Str::random(3)),
            'email' => strtolower('emp-'.Str::random(8)).'@example.test',
            'status' => 'active',
        ], $overrides));
    }

    /**
     * A consumable with real on-hand stock, ready to be issued.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function makeIssuableConsumable(College $college, array $overrides = []): InventoryItem
    {
        return $this->makeInventoryItem($college, array_merge([
            'item_type' => InventoryItem::TYPE_CONSUMABLE,
            'quantity' => '10.00',
            'status' => InventoryItem::STATUS_ACTIVE,
        ], $overrides));
    }

    /**
     * An individual asset, ready to be assigned.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function makeAsset(College $college, array $overrides = []): InventoryItem
    {
        return $this->makeInventoryItem($college, array_merge([
            'item_type' => InventoryItem::TYPE_ASSET,
            'unit' => 'nos',
            'quantity' => '1.00',
            'serial_number' => 'SN-'.strtoupper(Str::random(6)),
            'status' => InventoryItem::STATUS_ACTIVE,
        ], $overrides));
    }

    /**
     * A recorded issue row WITHOUT its stock movement — direct model writes
     * are only for list / RBAC / tenant fixtures. Ledger-backed issues go
     * through the HTTP routes so the movement is asserted for real.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function makeInventoryIssue(College $college, InventoryItem $item, array $overrides = []): InventoryIssue
    {
        $actor = $this->makeUserWithPermissions($college, []);

        return InventoryIssue::create(array_merge([
            'college_id' => $college->id,
            'item_id' => $item->id,
            'number' => 'ISS-'.strtoupper(Str::random(6)),
            'quantity' => '1.00',
            'issued_to_type' => 'student',
            'issued_to_id' => $this->makeStudent($college)->id,
            'purpose' => 'Fixture issue',
            'reference' => null,
            'movement_date' => '2026-09-15',
            'notes' => null,
            'created_by' => $actor->id,
        ], $overrides));
    }

    /**
     * An assignment row (default: active) — the custody-history fixture.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function makeInventoryAssignment(College $college, InventoryItem $item, array $overrides = []): InventoryAssignment
    {
        $actor = $this->makeUserWithPermissions($college, []);

        return InventoryAssignment::create(array_merge([
            'college_id' => $college->id,
            'item_id' => $item->id,
            'assigned_to_type' => 'student',
            'assigned_to_id' => $this->makeStudent($college)->id,
            'purpose' => 'Fixture assignment',
            'assigned_on' => '2026-09-10',
            'returned_on' => null,
            'returned_by' => null,
            'return_notes' => null,
            'status' => InventoryAssignment::STATUS_ACTIVE,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ], $overrides));
    }

    /**
     * A maintenance record linked to an existing asset.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function makeInventoryMaintenance(College $college, InventoryItem $item, array $overrides = []): InventoryMaintenance
    {
        $actor = $this->makeUserWithPermissions($college, []);

        return InventoryMaintenance::create(array_merge([
            'college_id' => $college->id,
            'item_id' => $item->id,
            'vendor_id' => null,
            'title' => 'Fixture maintenance',
            'maintenance_type' => InventoryMaintenance::TYPE_PREVENTIVE,
            'status' => InventoryMaintenance::STATUS_SCHEDULED,
            'scheduled_on' => '2026-10-01',
            'completed_on' => null,
            'cost' => null,
            'performed_by' => null,
            'description' => null,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ], $overrides));
    }

    /**
     * A user who belongs to the given college but holds no inventory
     * permissions at all — the RBAC negative case.
     */
    private function makeBystanderUser(College $college): User
    {
        return $this->makeUserWithPermissions($college, []);
    }
}
