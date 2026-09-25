<?php

namespace Tests\Feature\Inventory;

use App\Models\InventoryItem;
use App\Models\InventoryPurchaseOrder;
use App\Models\InventoryPurchaseOrderItem;
use App\Models\InventoryStockMovement;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Inventory — the composite tenant foreign keys and their parent keys.
 *
 * Every relationship in this module is a composite `(x_id, college_id)` foreign
 * key, which is what stops a row pointing at another college's record. SQLite
 * resolves such a key against a UNIQUE index in the parent at statement prepare
 * time and ignores partial indexes, so without a plain unique `(id, college_id)`
 * parent key EVERY insert into a child table failed with
 *
 *     foreign key mismatch - "inventory_stock_movements" referencing
 *     "inventory_purchase_orders"
 *
 * even when the foreign key column was NULL — which is what broke writing an
 * opening stock movement from the item form.
 *
 * These tests pin both halves: the parent key exists, the writes that used to
 * fail now succeed, and the relationship still rejects a cross-tenant reference
 * at the database level rather than only in the service.
 */
class InventorySchemaRelationshipTest extends TestCase
{
    use InventoryTestHelpers;

    /**
     * Tables a composite `(x_id, college_id)` foreign key points at.
     *
     * @var list<string>
     */
    private const REFERENCED_TABLES = [
        'inventory_categories',
        'inventory_vendors',
        'inventory_items',
        'inventory_purchase_orders',
    ];

    /**
     * Run a callback with foreign key enforcement on.
     *
     * SQLite enforcement is per-connection and is enabled by
     * config/database.php (`DB_FOREIGN_KEYS`, default true); stating it again is
     * a no-op there and unnecessary on the drivers that always enforce.
     */
    private function withForeignKeysOn(callable $callback): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = ON');
        }

        $callback();
    }

    /**
     * Does a UNIQUE index cover exactly (id, college_id), in either order?
     */
    private function hasParentKey(string $table): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            $columns = array_map(
                fn ($column): string => strtolower((string) $column),
                $index['columns'] ?? [],
            );

            sort($columns);

            if (($index['unique'] ?? false) && $columns === ['college_id', 'id']) {
                return true;
            }
        }

        return false;
    }

    public function test_every_referenced_table_has_a_unique_composite_parent_key(): void
    {
        foreach (self::REFERENCED_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} must exist.");
            $this->assertTrue(
                $this->hasParentKey($table),
                "{$table} needs a UNIQUE index on (id, college_id) so SQLite can resolve the composite foreign keys that reference it. A partial (WHERE deleted_at IS NULL) index does not count.",
            );
        }
    }

    public function test_an_opening_stock_movement_can_be_written_without_a_purchase_order(): void
    {
        $college = $this->makeCollege('IFK1');
        $user = $this->makeUserWithPermissions($college, ['inventory_items.create', 'inventory_stock.view']);
        $category = $this->makeInventoryCategory($college);

        // This is the write that used to raise "foreign key mismatch": an
        // opening movement has no purchase order, and SQLite failed to prepare
        // the statement before it ever looked at the NULL column.
        $this->withForeignKeysOn(function () use ($college, $user, $category): void {
            $this->asCollege($college, $user)
                ->post(route('inventory-items.store'), [
                    'name' => 'A4 Ream',
                    'code' => 'A4-001',
                    'category_id' => $category->id,
                    'item_type' => InventoryItem::TYPE_CONSUMABLE,
                    'brand' => null,
                    'model' => null,
                    'serial_number' => null,
                    'unit' => 'ream',
                    'quantity' => '5',
                    'description' => null,
                    'status' => InventoryItem::STATUS_ACTIVE,
                ])
                ->assertSessionHasNoErrors();
        });

        $this->withTenant($college, function (): void {
            $movement = InventoryStockMovement::query()->firstOrFail();
            $this->assertSame(InventoryStockMovement::TYPE_OPENING, $movement->type);
            $this->assertNull($movement->purchase_order_id);
            $this->assertSame('5.00', $movement->balance_after);
        });
    }

    public function test_a_goods_receipt_can_link_its_movement_to_the_purchase_order(): void
    {
        $college = $this->makeCollege('IFK2');
        $user = $this->makeUserWithPermissions($college, ['inventory_purchase_orders.receive', 'inventory_stock.view']);
        $vendor = $this->makeInventoryVendor($college);
        $item = $this->makeInventoryItem($college, ['quantity' => '0.00']);
        $order = $this->makeInventoryPurchaseOrder($college, [
            'vendor_id' => $vendor->id,
            'status' => InventoryPurchaseOrder::STATUS_SUBMITTED,
        ]);
        $line = $this->addPurchaseOrderLine($order, $item, ['quantity' => '4.00']);

        $this->withForeignKeysOn(function () use ($college, $user, $order, $line): void {
            $this->asCollege($college, $user)
                ->post(route('inventory-purchase-orders.receive.store', $order), [
                    'receipts' => [['line_id' => $line->id, 'quantity' => '4']],
                    'movement_date' => '2026-10-01',
                ])
                ->assertSessionHasNoErrors();
        });

        $this->withTenant($college, function () use ($order, $item): void {
            $movement = InventoryStockMovement::query()->firstOrFail();
            $this->assertSame(InventoryStockMovement::TYPE_PURCHASE_RECEIPT, $movement->type);
            $this->assertSame($order->id, $movement->purchase_order_id, 'The receipt keeps its link to the order.');
            $this->assertSame($item->id, $movement->item_id);
            $this->assertSame('4.00', $item->fresh()->quantity);
        });
    }

    public function test_the_database_rejects_a_movement_pointing_at_another_colleges_purchase_order(): void
    {
        $college = $this->makeCollege('IFK3');
        $other = $this->makeCollege('IFK3X');
        $item = $this->makeInventoryItem($college);
        $foreignOrder = $this->makeInventoryPurchaseOrder($other, [
            'vendor_id' => $this->makeInventoryVendor($other)->id,
        ]);

        $this->withForeignKeysOn(function () use ($college, $item, $foreignOrder): void {
            try {
                InventoryStockMovement::withoutGlobalScopes()->create([
                    // Claims the active college but points at another college's
                    // order: the composite key has no such parent row.
                    'college_id' => $college->id,
                    'item_id' => $item->id,
                    'purchase_order_id' => $foreignOrder->id,
                    'type' => InventoryStockMovement::TYPE_PURCHASE_RECEIPT,
                    'direction' => InventoryStockMovement::DIRECTION_IN,
                    'quantity' => '1.00',
                    'balance_after' => '1.00',
                    'movement_date' => '2026-10-01',
                ]);

                $this->fail('The composite foreign key must reject a purchase order of another college.');
            } catch (QueryException $exception) {
                $this->assertStringContainsStringIgnoringCase('foreign key', $exception->getMessage());
            }
        });

        $this->withTenant($college, fn () => $this->assertSame(0, InventoryStockMovement::query()->count()));
    }

    public function test_the_database_rejects_a_movement_for_another_colleges_item(): void
    {
        $college = $this->makeCollege('IFK4');
        $other = $this->makeCollege('IFK4X');
        $foreignItem = $this->makeInventoryItem($other);

        $this->withForeignKeysOn(function () use ($college, $foreignItem): void {
            try {
                InventoryStockMovement::withoutGlobalScopes()->create([
                    'college_id' => $college->id,
                    'item_id' => $foreignItem->id,
                    'type' => InventoryStockMovement::TYPE_STOCK_IN,
                    'direction' => InventoryStockMovement::DIRECTION_IN,
                    'quantity' => '1.00',
                    'balance_after' => '1.00',
                    'movement_date' => '2026-10-01',
                ]);

                $this->fail('The composite foreign key must reject an item of another college.');
            } catch (QueryException $exception) {
                $this->assertStringContainsStringIgnoringCase('foreign key', $exception->getMessage());
            }
        });

        $this->withTenant($college, fn () => $this->assertSame(0, InventoryStockMovement::query()->count()));
    }

    public function test_the_database_rejects_an_order_line_for_another_colleges_order(): void
    {
        $college = $this->makeCollege('IFK5');
        $other = $this->makeCollege('IFK5X');
        $item = $this->makeInventoryItem($college);
        $foreignOrder = $this->makeInventoryPurchaseOrder($other, [
            'vendor_id' => $this->makeInventoryVendor($other)->id,
        ]);

        $this->withForeignKeysOn(function () use ($college, $item, $foreignOrder): void {
            try {
                InventoryPurchaseOrderItem::withoutGlobalScopes()->create([
                    'college_id' => $college->id,
                    'purchase_order_id' => $foreignOrder->id,
                    'item_id' => $item->id,
                    'quantity' => '1.00',
                    'unit_price' => '1.00',
                    'received_quantity' => '0.00',
                ]);

                $this->fail('The composite foreign key must reject a line on another college\'s order.');
            } catch (QueryException $exception) {
                $this->assertStringContainsStringIgnoringCase('foreign key', $exception->getMessage());
            }
        });

        $this->withTenant($college, fn () => $this->assertSame(0, InventoryPurchaseOrderItem::query()->count()));
    }

    public function test_the_parent_keys_do_not_disturb_the_phase_one_masters(): void
    {
        $college = $this->makeCollege('IFK6');
        $user = $this->makeUserWithPermissions($college, ['inventory_items.view', 'inventory_items.create']);
        $category = $this->makeInventoryCategory($college, ['code' => 'CAT-1']);

        // The Phase 1 masters still behave: a category, an item that references
        // it, and a vendor — all written under enforcement.
        $this->withForeignKeysOn(function () use ($college, $user, $category): void {
            $this->asCollege($college, $user)
                ->post(route('inventory-items.store'), [
                    'name' => 'Ball Pen',
                    'code' => 'PEN-1',
                    'category_id' => $category->id,
                    'item_type' => InventoryItem::TYPE_CONSUMABLE,
                    'brand' => null,
                    'model' => null,
                    'serial_number' => null,
                    'unit' => 'pcs',
                    'quantity' => '0',
                    'description' => null,
                    'status' => InventoryItem::STATUS_ACTIVE,
                ])
                ->assertSessionHasNoErrors();
        });

        $this->withTenant($college, function () use ($college, $category): void {
            $item = InventoryItem::query()->where('code', 'PEN-1')->firstOrFail();
            $this->assertSame($college->id, $item->college_id);
            $this->assertSame($category->id, $item->category_id);

            // The parent key is (id, college_id) and id is already unique, so
            // it can never reject legitimate data.
            $this->assertSame(1, InventoryItem::query()->count());
        });
    }
}
