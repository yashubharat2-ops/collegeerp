<?php

namespace Tests\Feature\Inventory;

use App\Models\InventoryItem;
use App\Models\InventoryStockMovement;
use Tests\TestCase;

/**
 * Inventory Phase 2 final structure — Purchase & Stock.
 *
 * Validates the 4 required modules:
 *  - Purchase Orders (existing)
 *  - Goods Receipt / Stock In
 *  - Stock Adjustment (must generate inventory transaction)
 *  - Inventory Transactions (immutable ledger)
 *
 * Architecture: PO → Goods Receipt / Stock In → Inventory Transactions → Current Stock
 */
class InventoryPhase2FinalTest extends TestCase
{
    use InventoryTestHelpers;

    private function goodsReceiptPayload(InventoryItem $item, array $overrides = []): array
    {
        return array_merge([
            'item_id' => $item->id,
            'quantity' => '5',
            'unit_price' => '10.00',
            'reference' => 'GRN-FINAL-1',
            'reason' => null,
            'notes' => null,
            'movement_date' => '2026-10-01',
        ], $overrides);
    }

    private function adjustmentPayload(InventoryItem $item, array $overrides = []): array
    {
        return array_merge([
            'item_id' => $item->id,
            'type' => InventoryStockMovement::TYPE_ADJUSTMENT,
            'direction' => InventoryStockMovement::DIRECTION_OUT,
            'quantity' => '2',
            'unit_price' => null,
            'reference' => 'ADJ-FINAL-1',
            'reason' => 'Damaged during audit',
            'notes' => null,
            'movement_date' => '2026-10-02',
        ], $overrides);
    }

    public function test_goods_receipt_module_lists_incoming_and_allows_stock_in(): void
    {
        $college = $this->makeCollege('IPF1');
        $user = $this->makeUserWithPermissions($college, ['inventory_goods_receipts.view', 'inventory_goods_receipts.create', 'inventory_transactions.view']);
        $item = $this->makeInventoryItem($college, ['quantity' => '3.00']);

        // Empty list initially
        $this->asCollege($college, $user)
            ->get(route('inventory-goods-receipts.index'))
            ->assertOk()
            ->assertSee('Goods Receipt / Stock In');

        // Record a manual stock in via new module
        $this->asCollege($college, $user)
            ->post(route('inventory-goods-receipts.store'), $this->goodsReceiptPayload($item))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('inventory-goods-receipts.index', ['item_id' => $item->id]));

        $this->withTenant($college, function () use ($item) {
            $this->assertSame('8.00', $item->fresh()->quantity);
            $movement = InventoryStockMovement::query()->firstOrFail();
            $this->assertSame(InventoryStockMovement::TYPE_STOCK_IN, $movement->type);
            $this->assertSame(InventoryStockMovement::DIRECTION_IN, $movement->direction);
            $this->assertSame('8.00', $movement->balance_after);
        });

        // It appears in both goods receipt and transactions ledger
        $this->asCollege($college, $user)
            ->get(route('inventory-goods-receipts.index'))
            ->assertOk()
            ->assertSee('GRN-FINAL-1');

        $this->asCollege($college, $user)
            ->get(route('inventory-transactions.index'))
            ->assertOk()
            ->assertSee('GRN-FINAL-1')
            ->assertSee('Stock in');
    }

    public function test_stock_adjustment_module_lists_and_generates_transaction(): void
    {
        $college = $this->makeCollege('IPF2');
        $user = $this->makeUserWithPermissions($college, [
            'inventory_stock_adjustments.view', 'inventory_stock_adjustments.create',
            'inventory_transactions.view',
        ]);
        $item = $this->makeInventoryItem($college, ['quantity' => '10.00']);

        $this->asCollege($college, $user)
            ->get(route('inventory-stock-adjustments.index'))
            ->assertOk()
            ->assertSee('Stock Adjustment');

        $this->asCollege($college, $user)
            ->post(route('inventory-stock-adjustments.store'), $this->adjustmentPayload($item))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('inventory-stock-adjustments.index', ['item_id' => $item->id]));

        $this->withTenant($college, function () use ($item) {
            $this->assertSame('8.00', $item->fresh()->quantity);
            $movement = InventoryStockMovement::query()->firstOrFail();
            $this->assertSame(InventoryStockMovement::TYPE_ADJUSTMENT, $movement->type);
            $this->assertSame(InventoryStockMovement::DIRECTION_OUT, $movement->direction);
            $this->assertSame('8.00', $movement->balance_after);
            $this->assertSame('Damaged during audit', $movement->reason);
        });

        // Adjustment must also appear in inventory transactions (PO → GR → Transactions → Stock)
        $this->asCollege($college, $user)
            ->get(route('inventory-transactions.index'))
            ->assertOk()
            ->assertSee('ADJ-FINAL-1')
            ->assertSee('Adjustment');
    }

    public function test_stock_adjustment_requires_reason_and_cannot_go_negative(): void
    {
        $college = $this->makeCollege('IPF3');
        $user = $this->makeUserWithPermissions($college, ['inventory_stock_adjustments.create']);
        $item = $this->makeInventoryItem($college, ['quantity' => '1.00']);

        $this->asCollege($college, $user)
            ->post(route('inventory-stock-adjustments.store'), $this->adjustmentPayload($item, ['reason' => '']))
            ->assertSessionHasErrors('reason');

        $this->asCollege($college, $user)
            ->post(route('inventory-stock-adjustments.store'), $this->adjustmentPayload($item, ['quantity' => '5', 'reason' => 'Too much']))
            ->assertSessionHasErrors('quantity');

        $this->withTenant($college, fn () => $this->assertSame('1.00', $item->fresh()->quantity));
    }

    public function test_inventory_transactions_is_read_only_ledger(): void
    {
        $college = $this->makeCollege('IPF4');
        $user = $this->makeUserWithPermissions($college, ['inventory_transactions.view', 'inventory_goods_receipts.create']);
        $item = $this->makeInventoryItem($college, ['quantity' => '2.00']);

        $this->asCollege($college, $user)
            ->post(route('inventory-goods-receipts.store'), $this->goodsReceiptPayload($item))
            ->assertSessionHasNoErrors();

        $this->asCollege($college, $user)
            ->get(route('inventory-transactions.index'))
            ->assertOk()
            ->assertSee('Inventory Transactions')
            ->assertSee('On hand after');

        // No create/update/delete routes for transactions
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('inventory-transactions.create'));
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('inventory-transactions.store'));
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('inventory-transactions.edit'));
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('inventory-transactions.update'));
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('inventory-transactions.destroy'));
    }

    public function test_new_modules_are_permission_gated(): void
    {
        $college = $this->makeCollege('IPF5');
        $viewer = $this->makeUserWithPermissions($college, ['inventory_transactions.view']);
        $goodsCreator = $this->makeUserWithPermissions($college, ['inventory_goods_receipts.view', 'inventory_goods_receipts.create']);
        $adjustCreator = $this->makeUserWithPermissions($college, ['inventory_stock_adjustments.view', 'inventory_stock_adjustments.create']);
        $nobody = $this->makeUserWithPermissions($college, ['dashboard.view']);
        $item = $this->makeInventoryItem($college, ['quantity' => '5.00']);

        $this->asCollege($college, $nobody)->get(route('inventory-goods-receipts.index'))->assertForbidden();
        $this->asCollege($college, $nobody)->get(route('inventory-stock-adjustments.index'))->assertForbidden();
        $this->asCollege($college, $nobody)->get(route('inventory-transactions.index'))->assertForbidden();

        $this->asCollege($college, $viewer)->get(route('inventory-transactions.index'))->assertOk();
        $this->asCollege($college, $viewer)->get(route('inventory-goods-receipts.index'))->assertForbidden();
        $this->asCollege($college, $viewer)->post(route('inventory-goods-receipts.store'), $this->goodsReceiptPayload($item))->assertForbidden();

        $this->asCollege($college, $goodsCreator)->get(route('inventory-goods-receipts.index'))->assertOk();
        $this->asCollege($college, $goodsCreator)->post(route('inventory-goods-receipts.store'), $this->goodsReceiptPayload($item))->assertSessionHasNoErrors();
        $this->asCollege($college, $goodsCreator)->get(route('inventory-transactions.index'))->assertForbidden();
        $this->asCollege($college, $goodsCreator)->post(route('inventory-stock-adjustments.store'), $this->adjustmentPayload($item))->assertForbidden();

        $this->asCollege($college, $adjustCreator)->get(route('inventory-stock-adjustments.index'))->assertOk();
        $this->asCollege($college, $adjustCreator)->post(route('inventory-stock-adjustments.store'), $this->adjustmentPayload($item, ['quantity' => '1']))->assertSessionHasNoErrors();
        $this->asCollege($college, $adjustCreator)->post(route('inventory-goods-receipts.store'), $this->goodsReceiptPayload($item))->assertForbidden();
    }

    public function test_purchase_order_receipt_appears_in_goods_receipt_and_transactions(): void
    {
        $college = $this->makeCollege('IPF6');
        $user = $this->makeUserWithPermissions($college, [
            'inventory_purchase_orders.view', 'inventory_purchase_orders.receive',
            'inventory_goods_receipts.view', 'inventory_transactions.view',
        ]);
        $vendor = $this->makeInventoryVendor($college);
        $item = $this->makeInventoryItem($college, ['quantity' => '0.00']);
        $order = $this->makeInventoryPurchaseOrder($college, ['vendor_id' => $vendor->id, 'status' => \App\Models\InventoryPurchaseOrder::STATUS_SUBMITTED]);
        $line = $this->addPurchaseOrderLine($order, $item, ['quantity' => '10.00']);

        $this->asCollege($college, $user)
            ->post(route('inventory-purchase-orders.receive.store', $order), [
                'receipts' => [['line_id' => $line->id, 'quantity' => '10']],
                'reference' => 'GRN-PO-FINAL',
                'movement_date' => '2026-10-05',
            ])
            ->assertSessionHasNoErrors();

        $this->asCollege($college, $user)
            ->get(route('inventory-goods-receipts.index'))
            ->assertOk()
            ->assertSee('GRN-PO-FINAL')
            ->assertSee('Purchase receipt');

        $this->asCollege($college, $user)
            ->get(route('inventory-transactions.index'))
            ->assertOk()
            ->assertSee('GRN-PO-FINAL')
            ->assertSee('Purchase receipt');
    }
}
