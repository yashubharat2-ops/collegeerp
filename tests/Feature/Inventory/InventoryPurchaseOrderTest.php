<?php

namespace Tests\Feature\Inventory;

use App\Models\AuditLog;
use App\Models\InventoryItem;
use App\Models\InventoryPurchaseOrder;
use App\Models\InventoryPurchaseOrderItem;
use App\Models\InventoryStockMovement;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Inventory / Asset Management — Phase 2, Purchase Orders.
 *
 * Covers the lifecycle (draft → submitted → partially received → received,
 * plus cancelled), the server-computed line totals, tenant-safe vendors and
 * items, order-number uniqueness, and the stock effect of a goods receipt.
 */
class InventoryPurchaseOrderTest extends TestCase
{
    use InventoryTestHelpers;

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(int $vendorId, array $lines, array $overrides = []): array
    {
        return array_merge([
            'number' => 'PO-2026-001',
            'vendor_id' => $vendorId,
            'po_date' => '2026-09-30',
            'expected_date' => '2026-10-15',
            'notes' => 'Annual stationery order',
            'lines' => $lines,
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function line(int $itemId, string $quantity, string $unitPrice = '100.00'): array
    {
        return ['item_id' => $itemId, 'quantity' => $quantity, 'unit_price' => $unitPrice];
    }

    public function test_the_phase_two_tables_exist_and_there_is_still_no_asset_master(): void
    {
        $this->assertTrue(Schema::hasTable('inventory_purchase_orders'));
        $this->assertTrue(Schema::hasTable('inventory_purchase_order_items'));
        $this->assertTrue(Schema::hasTable('inventory_stock_movements'));
        $this->assertFalse(Schema::hasTable('assets'));
        $this->assertFalse(Schema::hasTable('inventory_issues'));
    }

    public function test_a_new_order_is_a_draft_with_server_computed_line_totals(): void
    {
        $college = $this->makeCollege('IPO1');
        $user = $this->makeUserWithPermissions($college, ['inventory_purchase_orders.view', 'inventory_purchase_orders.create']);
        $vendor = $this->makeInventoryVendor($college);
        $paper = $this->makeInventoryItem($college, ['name' => 'A4 Ream', 'code' => 'PAPER']);
        $pen = $this->makeInventoryItem($college, ['name' => 'Ball Pen', 'code' => 'PEN']);

        $this->asCollege($college, $user)
            ->post(route('inventory-purchase-orders.store'), $this->payload($vendor->id, [
                $this->line($paper->id, '3', '100.50'),
                $this->line($pen->id, '2', '10'),
            ], ['number' => ' po-2026-001 ']))
            ->assertSessionHasNoErrors();

        $order = $this->withTenant($college, fn () => InventoryPurchaseOrder::query()->firstOrFail());

        $this->assertSame($college->id, $order->college_id);
        $this->assertSame($vendor->id, $order->vendor_id);
        $this->assertSame('PO-2026-001', $order->number, 'Order numbers are trimmed and stored upper-cased.');
        $this->assertSame(InventoryPurchaseOrder::STATUS_DRAFT, $order->status, 'A new order is always a draft.');
        // 3 × 100.50 + 2 × 10.00 = 321.50, computed server-side.
        $this->assertSame('321.50', $order->total_amount);
        $this->assertSame('2026-09-30', $order->po_date->toDateString());
        $this->assertSame('2026-10-15', $order->expected_date->toDateString());
        $this->assertSame($user->id, $order->created_by);
        $this->assertSame($user->id, $order->updated_by);

        $lines = $this->withTenant($college, fn () => $order->lines()->orderBy('id')->get());
        $this->assertCount(2, $lines);
        $this->assertSame(['3.00', '2.00'], $lines->pluck('quantity')->all());
        $this->assertSame(['100.50', '10.00'], $lines->pluck('unit_price')->all());
        $this->assertSame(['0.00', '0.00'], $lines->pluck('received_quantity')->all());

        // Nothing has moved yet: a draft books no stock.
        $this->withTenant($college, function () {
            $this->assertSame(0, InventoryStockMovement::query()->count());
        });

        $this->assertSame(1, AuditLog::query()->where('action', 'inventory_purchase_orders.created')->count());
    }

    public function test_the_college_id_status_and_total_cannot_be_forged(): void
    {
        $college = $this->makeCollege('IPO2');
        $other = $this->makeCollege('IPO2X');
        $user = $this->makeUserWithPermissions($college, ['inventory_purchase_orders.create']);
        $vendor = $this->makeInventoryVendor($college);
        $item = $this->makeInventoryItem($college);

        $this->asCollege($college, $user)
            ->post(route('inventory-purchase-orders.store'), $this->payload($vendor->id, [$this->line($item->id, '4', '25')], [
                'college_id' => $other->id,
                'status' => InventoryPurchaseOrder::STATUS_RECEIVED,
                'total_amount' => '1.00',
                'created_by' => 9999,
                'updated_by' => 9999,
            ]))
            ->assertSessionHasNoErrors();

        $order = $this->withTenant($college, fn () => InventoryPurchaseOrder::query()->firstOrFail());

        $this->assertSame($college->id, $order->college_id);
        $this->assertSame(InventoryPurchaseOrder::STATUS_DRAFT, $order->status);
        $this->assertSame('100.00', $order->total_amount);
        $this->assertSame($user->id, $order->created_by);
        $this->assertSame($user->id, $order->updated_by);
    }

    public function test_the_vendor_and_every_ordered_item_must_belong_to_the_active_college(): void
    {
        $college = $this->makeCollege('IPO3');
        $other = $this->makeCollege('IPO3X');
        $user = $this->makeUserWithPermissions($college, ['inventory_purchase_orders.create']);
        $vendor = $this->makeInventoryVendor($college);
        $item = $this->makeInventoryItem($college);
        $foreignVendor = $this->makeInventoryVendor($other);
        $foreignItem = $this->makeInventoryItem($other);

        $this->asCollege($college, $user)
            ->post(route('inventory-purchase-orders.store'), $this->payload($foreignVendor->id, [$this->line($item->id, '1')]))
            ->assertSessionHasErrors('vendor_id');

        $this->asCollege($college, $user)
            ->post(route('inventory-purchase-orders.store'), $this->payload($vendor->id, [$this->line($foreignItem->id, '1')]))
            ->assertSessionHasErrors('lines.0.item_id');

        $this->withTenant($college, function () {
            $this->assertSame(0, InventoryPurchaseOrder::query()->count());
        });
    }

    public function test_order_numbers_are_unique_per_college_and_reusable_after_soft_delete(): void
    {
        $college = $this->makeCollege('IPO4');
        $other = $this->makeCollege('IPO4X');
        $user = $this->makeUserWithPermissions($college, ['inventory_purchase_orders.create', 'inventory_purchase_orders.delete']);
        $vendor = $this->makeInventoryVendor($college);
        $item = $this->makeInventoryItem($college);
        $lines = [$this->line($item->id, '1')];

        $this->asCollege($college, $user)
            ->post(route('inventory-purchase-orders.store'), $this->payload($vendor->id, $lines))
            ->assertSessionHasNoErrors();

        // Another college may reuse the same number.
        $otherUser = $this->makeUserWithPermissions($other, ['inventory_purchase_orders.create']);
        $this->asCollege($other, $otherUser)
            ->post(route('inventory-purchase-orders.store'), $this->payload($this->makeInventoryVendor($other)->id, [$this->line($this->makeInventoryItem($other)->id, '1')]))
            ->assertSessionHasNoErrors();

        $this->asCollege($college, $user)
            ->post(route('inventory-purchase-orders.store'), $this->payload($vendor->id, $lines, ['number' => ' po-2026-001 ']))
            ->assertSessionHasErrors('number');

        $order = $this->withTenant($college, fn () => InventoryPurchaseOrder::query()->firstOrFail());
        $this->asCollege($college, $user)->delete(route('inventory-purchase-orders.destroy', $order))->assertRedirect();
        $this->assertSoftDeleted('inventory_purchase_orders', ['id' => $order->id]);

        // A soft-deleted number can be used again.
        $this->asCollege($college, $user)
            ->post(route('inventory-purchase-orders.store'), $this->payload($vendor->id, $lines))
            ->assertSessionHasNoErrors();
    }

    public function test_orders_are_isolated_per_college(): void
    {
        $college = $this->makeCollege('IPO5');
        $other = $this->makeCollege('IPO5X');
        $user = $this->makeUserWithPermissions($college, self::INVENTORY_PHASE2_PERMISSIONS);
        $vendor = $this->makeInventoryVendor($college, ['name' => 'Mine Supplies']);
        $item = $this->makeInventoryItem($college);
        $mine = $this->makeInventoryPurchaseOrder($college, ['vendor_id' => $vendor->id, 'number' => 'MINE']);
        $this->addPurchaseOrderLine($mine, $item);

        $foreignVendor = $this->makeInventoryVendor($other, ['name' => 'Theirs Supplies']);
        $theirs = $this->makeInventoryPurchaseOrder($other, ['vendor_id' => $foreignVendor->id, 'number' => 'THEIRS']);
        $this->addPurchaseOrderLine($theirs, $this->makeInventoryItem($other));

        $this->asCollege($college, $user)
            ->get(route('inventory-purchase-orders.index'))
            ->assertOk()
            ->assertSee('MINE')
            ->assertSee('Mine Supplies')
            ->assertDontSee('THEIRS')
            ->assertDontSee('Theirs Supplies');

        $this->asCollege($college, $user)->get(route('inventory-purchase-orders.show', $theirs))->assertNotFound();
        $this->asCollege($college, $user)->get(route('inventory-purchase-orders.edit', $theirs))->assertNotFound();
        $this->asCollege($college, $user)->put(route('inventory-purchase-orders.update', $theirs), $this->payload($vendor->id, [$this->line($item->id, '1')]))->assertNotFound();
        $this->asCollege($college, $user)->delete(route('inventory-purchase-orders.destroy', $theirs))->assertNotFound();
        $this->asCollege($college, $user)->post(route('inventory-purchase-orders.submit', $theirs))->assertNotFound();
        $this->asCollege($college, $user)->post(route('inventory-purchase-orders.cancel', $theirs))->assertNotFound();
        $this->asCollege($college, $user)->post(route('inventory-purchase-orders.receive.store', $theirs), ['receipts' => [], 'movement_date' => '2026-09-30'])->assertNotFound();
    }

    public function test_only_a_draft_can_be_edited_or_deleted(): void
    {
        $college = $this->makeCollege('IPO6');
        $user = $this->makeUserWithPermissions($college, ['inventory_purchase_orders.update', 'inventory_purchase_orders.delete']);
        $vendor = $this->makeInventoryVendor($college);
        $item = $this->makeInventoryItem($college);
        $order = $this->makeInventoryPurchaseOrder($college, ['vendor_id' => $vendor->id, 'status' => InventoryPurchaseOrder::STATUS_SUBMITTED]);
        $this->addPurchaseOrderLine($order, $item);

        $this->asCollege($college, $user)
            ->put(route('inventory-purchase-orders.update', $order), $this->payload($vendor->id, [$this->line($item->id, '99')], ['number' => 'HACKED']))
            ->assertSessionHasErrors('status');

        $order = $this->withTenant($college, fn () => InventoryPurchaseOrder::query()->whereKey($order->id)->firstOrFail());
        $this->assertSame('PO-', substr($order->number, 0, 3));
        $this->assertNotSame('HACKED', $order->number);
        $this->assertSame('1000.00', $order->total_amount, 'The line set of a submitted order is untouched.');

        $this->asCollege($college, $user)
            ->delete(route('inventory-purchase-orders.destroy', $order))
            ->assertSessionHasErrors('status');

        $this->assertNotSoftDeleted('inventory_purchase_orders', ['id' => $order->id]);
    }

    public function test_a_draft_is_submitted_once_and_can_be_cancelled(): void
    {
        $college = $this->makeCollege('IPO7');
        $user = $this->makeUserWithPermissions($college, ['inventory_purchase_orders.view', 'inventory_purchase_orders.update']);
        $vendor = $this->makeInventoryVendor($college);
        $item = $this->makeInventoryItem($college);
        $order = $this->makeInventoryPurchaseOrder($college, ['vendor_id' => $vendor->id]);
        $this->addPurchaseOrderLine($order, $item);

        $this->asCollege($college, $user)->post(route('inventory-purchase-orders.submit', $order))->assertRedirect();

        $order = $this->withTenant($college, fn () => InventoryPurchaseOrder::query()->whereKey($order->id)->firstOrFail());
        $this->assertSame(InventoryPurchaseOrder::STATUS_SUBMITTED, $order->status);
        $this->assertSame($user->id, $order->updated_by);

        // Submitting twice is refused.
        $this->asCollege($college, $user)->post(route('inventory-purchase-orders.submit', $order))->assertSessionHasErrors('status');

        $this->asCollege($college, $user)->post(route('inventory-purchase-orders.cancel', $order))->assertRedirect();
        $order = $this->withTenant($college, fn () => InventoryPurchaseOrder::query()->whereKey($order->id)->firstOrFail());
        $this->assertSame(InventoryPurchaseOrder::STATUS_CANCELLED, $order->status);

        $actions = AuditLog::query()->where('subject_id', $order->id)->orderBy('id')->pluck('action')->all();
        $this->assertContains('inventory_purchase_orders.submitted', $actions);
        $this->assertContains('inventory_purchase_orders.cancelled', $actions);

        // A cancelled order can no longer be received.
        $this->asCollege($college, $user)->post(route('inventory-purchase-orders.cancel', $order))->assertSessionHasErrors('status');
    }

    public function test_an_order_without_lines_cannot_be_submitted(): void
    {
        $college = $this->makeCollege('IPO8');
        $user = $this->makeUserWithPermissions($college, ['inventory_purchase_orders.update']);
        $order = $this->makeInventoryPurchaseOrder($college);

        $this->asCollege($college, $user)->post(route('inventory-purchase-orders.submit', $order))->assertSessionHasErrors('lines');

        $order = $this->withTenant($college, fn () => InventoryPurchaseOrder::query()->whereKey($order->id)->firstOrFail());
        $this->assertSame(InventoryPurchaseOrder::STATUS_DRAFT, $order->status);
    }

    public function test_receiving_goods_raises_on_hand_stock_and_writes_the_ledger(): void
    {
        $college = $this->makeCollege('IPO9');
        $user = $this->makeUserWithPermissions($college, ['inventory_purchase_orders.view', 'inventory_purchase_orders.receive', 'inventory_stock.view']);
        $vendor = $this->makeInventoryVendor($college);
        $item = $this->makeInventoryItem($college, ['name' => 'A4 Ream', 'unit' => 'ream', 'quantity' => '1.00']);
        $order = $this->makeInventoryPurchaseOrder($college, ['vendor_id' => $vendor->id, 'status' => InventoryPurchaseOrder::STATUS_SUBMITTED]);
        $line = $this->addPurchaseOrderLine($order, $item, ['quantity' => '10.00', 'unit_price' => '100.50']);

        $this->asCollege($college, $user)->get(route('inventory-purchase-orders.receive.create', $order))->assertOk()->assertSee('A4 Ream');

        $this->asCollege($college, $user)
            ->post(route('inventory-purchase-orders.receive.store', $order), [
                'receipts' => [['line_id' => $line->id, 'quantity' => '10']],
                'reference' => ' grn-2026-001 ',
                'movement_date' => '2026-10-02',
                'notes' => 'Delivered by vendor',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('inventory-purchase-orders.show', $order));

        $order = $this->withTenant($college, fn () => InventoryPurchaseOrder::query()->whereKey($order->id)->firstOrFail());
        $this->assertSame(InventoryPurchaseOrder::STATUS_RECEIVED, $order->status);

        $this->withTenant($college, function () use ($line, $item, $order, $user) {
            $this->assertSame('10.00', $line->fresh()->received_quantity);

            $refreshed = $item->fresh();
            $this->assertSame('11.00', $refreshed->quantity, '1 in stock plus 10 received.');

            $movement = InventoryStockMovement::query()->firstOrFail();
            $this->assertSame(InventoryStockMovement::TYPE_PURCHASE_RECEIPT, $movement->type);
            $this->assertSame(InventoryStockMovement::DIRECTION_IN, $movement->direction);
            $this->assertSame('10.00', $movement->quantity);
            $this->assertSame('11.00', $movement->balance_after);
            $this->assertSame('100.50', $movement->unit_price, 'The receipt keeps the price agreed on the order.');
            $this->assertSame($order->id, $movement->purchase_order_id);
            $this->assertSame($item->id, $movement->item_id);
            $this->assertSame('GRN-2026-001', $movement->reference);
            $this->assertSame('2026-10-02', $movement->movement_date->toDateString());
            $this->assertSame($user->id, $movement->created_by);
        });

        // The ledger shows on the stock screen.
        $this->asCollege($college, $user)
            ->get(route('inventory-stock.index'))
            ->assertOk()
            ->assertSee('Purchase receipt')
            ->assertSee('GRN-2026-001');

        $this->assertSame(1, AuditLog::query()->where('action', 'inventory_purchase_orders.received')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'inventory_purchase_order_items.received')->count());
    }

    public function test_an_order_can_be_received_in_several_consignments(): void
    {
        $college = $this->makeCollege('IPO10');
        $user = $this->makeUserWithPermissions($college, ['inventory_purchase_orders.view', 'inventory_purchase_orders.receive']);
        $vendor = $this->makeInventoryVendor($college);
        $item = $this->makeInventoryItem($college, ['quantity' => '1.00']);
        $order = $this->makeInventoryPurchaseOrder($college, ['vendor_id' => $vendor->id, 'status' => InventoryPurchaseOrder::STATUS_SUBMITTED]);
        $line = $this->addPurchaseOrderLine($order, $item, ['quantity' => '10.00']);

        $this->asCollege($college, $user)
            ->post(route('inventory-purchase-orders.receive.store', $order), ['receipts' => [['line_id' => $line->id, 'quantity' => '4']], 'movement_date' => '2026-10-01'])
            ->assertSessionHasNoErrors();

        $order = $this->withTenant($college, fn () => InventoryPurchaseOrder::query()->whereKey($order->id)->firstOrFail());
        $this->assertSame(InventoryPurchaseOrder::STATUS_PARTIALLY_RECEIVED, $order->status);

        $this->withTenant($college, function () use ($line): void {
            $this->assertSame('4.00', $line->fresh()->received_quantity);
            $this->assertSame('6.00', $line->fresh()->remainingQuantity());
        });

        $this->asCollege($college, $user)
            ->post(route('inventory-purchase-orders.receive.store', $order), ['receipts' => [['line_id' => $line->id, 'quantity' => '6']], 'movement_date' => '2026-10-05'])
            ->assertSessionHasNoErrors();

        $this->withTenant($college, function () use ($order, $line, $item) {
            $this->assertSame(InventoryPurchaseOrder::STATUS_RECEIVED, $order->fresh()->status);
            $this->assertSame('10.00', $line->fresh()->received_quantity);
            $this->assertSame('11.00', $item->fresh()->quantity);

            $balances = InventoryStockMovement::query()->orderBy('id')->pluck('balance_after')->all();
            $this->assertSame(['5.00', '11.00'], $balances, 'Each movement records the balance it left behind.');
        });
    }

    public function test_receiving_more_than_the_outstanding_quantity_changes_nothing(): void
    {
        $college = $this->makeCollege('IPO11');
        $user = $this->makeUserWithPermissions($college, ['inventory_purchase_orders.receive']);
        $vendor = $this->makeInventoryVendor($college);
        $item = $this->makeInventoryItem($college, ['quantity' => '1.00']);
        $order = $this->makeInventoryPurchaseOrder($college, ['vendor_id' => $vendor->id, 'status' => InventoryPurchaseOrder::STATUS_SUBMITTED]);
        $line = $this->addPurchaseOrderLine($order, $item, ['quantity' => '10.00']);

        $this->asCollege($college, $user)
            ->post(route('inventory-purchase-orders.receive.store', $order), ['receipts' => [['line_id' => $line->id, 'quantity' => '10.01']], 'movement_date' => '2026-10-01'])
            ->assertSessionHasErrors('receipts.0.quantity');

        $this->withTenant($college, function () use ($order, $line, $item) {
            $this->assertSame(InventoryPurchaseOrder::STATUS_SUBMITTED, $order->fresh()->status);
            $this->assertSame('0.00', $line->fresh()->received_quantity);
            $this->assertSame('1.00', $item->fresh()->quantity);
            $this->assertSame(0, InventoryStockMovement::query()->count());
        });
    }

    public function test_a_receipt_with_no_quantities_is_refused(): void
    {
        $college = $this->makeCollege('IPO12');
        $user = $this->makeUserWithPermissions($college, ['inventory_purchase_orders.receive']);
        $vendor = $this->makeInventoryVendor($college);
        $item = $this->makeInventoryItem($college);
        $order = $this->makeInventoryPurchaseOrder($college, ['vendor_id' => $vendor->id, 'status' => InventoryPurchaseOrder::STATUS_SUBMITTED]);
        $line = $this->addPurchaseOrderLine($order, $item, ['quantity' => '10.00']);

        $this->asCollege($college, $user)
            ->post(route('inventory-purchase-orders.receive.store', $order), ['receipts' => [['line_id' => $line->id, 'quantity' => '']], 'movement_date' => '2026-10-01'])
            ->assertSessionHasErrors('receipts');

        $this->withTenant($college, function () {
            $this->assertSame(0, InventoryStockMovement::query()->count());
        });
    }

    public function test_a_line_of_another_order_cannot_be_received_here(): void
    {
        $college = $this->makeCollege('IPO13');
        $user = $this->makeUserWithPermissions($college, ['inventory_purchase_orders.receive']);
        $vendor = $this->makeInventoryVendor($college);
        $item = $this->makeInventoryItem($college);

        $order = $this->makeInventoryPurchaseOrder($college, ['vendor_id' => $vendor->id, 'status' => InventoryPurchaseOrder::STATUS_SUBMITTED]);
        $this->addPurchaseOrderLine($order, $item, ['quantity' => '10.00']);

        $other = $this->makeInventoryPurchaseOrder($college, ['vendor_id' => $vendor->id, 'status' => InventoryPurchaseOrder::STATUS_SUBMITTED]);
        $foreignLine = $this->addPurchaseOrderLine($other, $item, ['quantity' => '5.00']);

        $this->asCollege($college, $user)
            ->post(route('inventory-purchase-orders.receive.store', $order), ['receipts' => [['line_id' => $foreignLine->id, 'quantity' => '1']], 'movement_date' => '2026-10-01'])
            ->assertSessionHasErrors('receipts.0.line_id');

        $this->withTenant($college, function () {
            $this->assertSame(0, InventoryStockMovement::query()->count());
        });
    }

    public function test_a_draft_or_a_settled_order_cannot_be_received(): void
    {
        $college = $this->makeCollege('IPO14');
        $user = $this->makeUserWithPermissions($college, ['inventory_purchase_orders.receive']);
        $vendor = $this->makeInventoryVendor($college);
        $item = $this->makeInventoryItem($college);
        $order = $this->makeInventoryPurchaseOrder($college, ['vendor_id' => $vendor->id]);
        $line = $this->addPurchaseOrderLine($order, $item, ['quantity' => '2.00']);

        $this->asCollege($college, $user)
            ->post(route('inventory-purchase-orders.receive.store', $order), ['receipts' => [['line_id' => $line->id, 'quantity' => '2']], 'movement_date' => '2026-10-01'])
            ->assertSessionHasErrors('status');

        $this->withTenant($college, fn () => $this->assertSame(0, InventoryStockMovement::query()->count()));

        // Settle the order behind the service's back (fixtures run outside a
        // request, so they write through scope-free queries), then try to
        // receive against it again.
        InventoryPurchaseOrder::withoutGlobalScopes()->whereKey($order->id)->update(['status' => InventoryPurchaseOrder::STATUS_RECEIVED]);
        InventoryPurchaseOrderItem::withoutGlobalScopes()->whereKey($line->id)->update(['received_quantity' => '2.00']);

        $this->asCollege($college, $user)
            ->post(route('inventory-purchase-orders.receive.store', $order), ['receipts' => [['line_id' => $line->id, 'quantity' => '1']], 'movement_date' => '2026-10-01'])
            ->assertSessionHasErrors('status');
    }

    public function test_validation_rejects_incomplete_or_invalid_orders(): void
    {
        $college = $this->makeCollege('IPO15');
        $user = $this->makeUserWithPermissions($college, ['inventory_purchase_orders.create']);
        $vendor = $this->makeInventoryVendor($college);
        $item = $this->makeInventoryItem($college);

        $this->asCollege($college, $user)
            ->post(route('inventory-purchase-orders.store'), [])
            ->assertSessionHasErrors(['number', 'vendor_id', 'po_date', 'lines']);

        // The same item on two lines.
        $this->asCollege($college, $user)
            ->post(route('inventory-purchase-orders.store'), $this->payload($vendor->id, [
                $this->line($item->id, '1'),
                $this->line($item->id, '2'),
            ]))
            ->assertSessionHasErrors('lines.1.item_id');

        // A zero quantity and a negative price.
        $this->asCollege($college, $user)
            ->post(route('inventory-purchase-orders.store'), $this->payload($vendor->id, [
                ['item_id' => $item->id, 'quantity' => '0', 'unit_price' => '-5'],
            ]))
            ->assertSessionHasErrors(['lines.0.quantity', 'lines.0.unit_price']);

        // An expected date before the order date.
        $this->asCollege($college, $user)
            ->post(route('inventory-purchase-orders.store'), $this->payload($vendor->id, [$this->line($item->id, '1')], ['expected_date' => '2026-09-01']))
            ->assertSessionHasErrors('expected_date');

        $this->withTenant($college, function () {
            $this->assertSame(0, InventoryPurchaseOrder::query()->count());
        });
    }

    public function test_the_index_filters_by_status_vendor_and_search(): void
    {
        $college = $this->makeCollege('IPO16');
        $user = $this->makeUserWithPermissions($college, ['inventory_purchase_orders.view']);
        $vendor = $this->makeInventoryVendor($college, ['name' => 'Alpha Supplies']);
        $otherVendor = $this->makeInventoryVendor($college, ['name' => 'Beta Supplies']);
        $item = $this->makeInventoryItem($college);

        $draft = $this->makeInventoryPurchaseOrder($college, ['vendor_id' => $vendor->id, 'number' => 'PO-DRAFT', 'status' => InventoryPurchaseOrder::STATUS_DRAFT]);
        $this->addPurchaseOrderLine($draft, $item);
        $submitted = $this->makeInventoryPurchaseOrder($college, ['vendor_id' => $otherVendor->id, 'number' => 'PO-SUB', 'status' => InventoryPurchaseOrder::STATUS_SUBMITTED]);
        $this->addPurchaseOrderLine($submitted, $item);

        $this->asCollege($college, $user)->get(route('inventory-purchase-orders.index'))->assertOk()->assertSee('PO-DRAFT')->assertSee('PO-SUB');

        $this->asCollege($college, $user)
            ->get(route('inventory-purchase-orders.index', ['status' => InventoryPurchaseOrder::STATUS_SUBMITTED]))
            ->assertOk()
            ->assertSee('PO-SUB')
            ->assertDontSee('PO-DRAFT');

        $this->asCollege($college, $user)
            ->get(route('inventory-purchase-orders.index', ['vendor_id' => $vendor->id]))
            ->assertOk()
            ->assertSee('PO-DRAFT')
            ->assertDontSee('PO-SUB');

        $this->asCollege($college, $user)
            ->get(route('inventory-purchase-orders.index', ['search' => 'po-draft']))
            ->assertOk()
            ->assertSee('PO-DRAFT')
            ->assertDontSee('PO-SUB');
    }

    public function test_every_purchase_order_route_is_permission_gated(): void
    {
        $college = $this->makeCollege('IPO17');
        $viewer = $this->makeUserWithPermissions($college, ['inventory_purchase_orders.view']);
        $nobody = $this->makeUserWithPermissions($college, ['dashboard.view']);
        $vendor = $this->makeInventoryVendor($college);
        $item = $this->makeInventoryItem($college);
        $order = $this->makeInventoryPurchaseOrder($college, ['vendor_id' => $vendor->id]);
        $this->addPurchaseOrderLine($order, $item);
        $payload = $this->payload($vendor->id, [$this->line($item->id, '1')]);

        $this->get(route('inventory-purchase-orders.index'))->assertRedirect(route('login'));

        $this->asCollege($college, $nobody)->get(route('inventory-purchase-orders.index'))->assertForbidden();
        $this->asCollege($college, $nobody)->get(route('inventory-stock.index'))->assertForbidden();

        $this->asCollege($college, $viewer)->get(route('inventory-purchase-orders.index'))->assertOk()->assertDontSee('New purchase order');
        $this->asCollege($college, $viewer)->get(route('inventory-purchase-orders.show', $order))->assertOk();
        $this->asCollege($college, $viewer)->get(route('inventory-purchase-orders.create'))->assertForbidden();
        $this->asCollege($college, $viewer)->post(route('inventory-purchase-orders.store'), $payload)->assertForbidden();
        $this->asCollege($college, $viewer)->get(route('inventory-purchase-orders.edit', $order))->assertForbidden();
        $this->asCollege($college, $viewer)->put(route('inventory-purchase-orders.update', $order), $payload)->assertForbidden();
        $this->asCollege($college, $viewer)->delete(route('inventory-purchase-orders.destroy', $order))->assertForbidden();
        $this->asCollege($college, $viewer)->post(route('inventory-purchase-orders.submit', $order))->assertForbidden();
        $this->asCollege($college, $viewer)->post(route('inventory-purchase-orders.cancel', $order))->assertForbidden();
        $this->asCollege($college, $viewer)->get(route('inventory-purchase-orders.receive.create', $order))->assertForbidden();
        $this->asCollege($college, $viewer)
            ->post(route('inventory-purchase-orders.receive.store', $order), ['receipts' => [['line_id' => 1, 'quantity' => '1']], 'movement_date' => '2026-10-01'])
            ->assertForbidden();
    }

    public function test_a_receipt_for_a_deleted_item_is_refused_with_a_field_error(): void
    {
        $college = $this->makeCollege('IPO19');
        $user = $this->makeUserWithPermissions($college, ['inventory_purchase_orders.receive']);
        $vendor = $this->makeInventoryVendor($college);
        $item = $this->makeInventoryItem($college, ['quantity' => '0.00']);
        $order = $this->makeInventoryPurchaseOrder($college, ['vendor_id' => $vendor->id, 'status' => InventoryPurchaseOrder::STATUS_SUBMITTED]);
        $line = $this->addPurchaseOrderLine($order, $item, ['quantity' => '4.00']);

        // An item can be retired while its order is still open.
        InventoryItem::withoutGlobalScopes()->whereKey($item->id)->update(['deleted_at' => now()]);

        $this->asCollege($college, $user)
            ->post(route('inventory-purchase-orders.receive.store', $order), ['receipts' => [['line_id' => $line->id, 'quantity' => '4']], 'movement_date' => '2026-10-01'])
            ->assertSessionHasErrors('receipts.0.line_id');

        $this->withTenant($college, function () use ($order): void {
            $this->assertSame(InventoryPurchaseOrder::STATUS_SUBMITTED, $order->fresh()->status);
            $this->assertSame(0, InventoryStockMovement::query()->count());
        });
    }

    public function test_the_show_screen_lists_the_lines_and_the_receipts(): void
    {
        $college = $this->makeCollege('IPO18');
        $user = $this->makeUserWithPermissions($college, ['inventory_purchase_orders.view', 'inventory_purchase_orders.receive', 'inventory_purchase_orders.update']);
        $vendor = $this->makeInventoryVendor($college, ['name' => 'Alpha Supplies']);
        $item = $this->makeInventoryItem($college, ['name' => 'A4 Ream', 'code' => 'PAPER', 'unit' => 'ream', 'quantity' => '0.00']);
        $order = $this->makeInventoryPurchaseOrder($college, ['vendor_id' => $vendor->id, 'number' => 'PO-SHOW', 'status' => InventoryPurchaseOrder::STATUS_SUBMITTED]);
        $line = $this->addPurchaseOrderLine($order, $item, ['quantity' => '5.00', 'unit_price' => '120.00']);

        $this->asCollege($college, $user)
            ->get(route('inventory-purchase-orders.show', $order))
            ->assertOk()
            ->assertSee('PO-SHOW')
            ->assertSee('Alpha Supplies')
            ->assertSee('A4 Ream')
            ->assertSee('600.00', false)
            ->assertSee('Receive goods');

        $this->asCollege($college, $user)
            ->post(route('inventory-purchase-orders.receive.store', $order), ['receipts' => [['line_id' => $line->id, 'quantity' => '5']], 'movement_date' => '2026-10-03', 'reference' => 'GRN-9'])
            ->assertSessionHasNoErrors();

        $this->asCollege($college, $user)
            ->get(route('inventory-purchase-orders.show', $order))
            ->assertOk()
            ->assertSee('GRN-9')
            ->assertDontSee('Nothing has been received against this order yet.');
    }

    /**
     * A draft, a cancelled or an already settled order cannot accept goods at
     * all. Every line on it is also "not outstanding", so answering with a
     * per-line quantity error would send the storekeeper to fix numbers that
     * were never the problem: the answer has to be the order's status.
     */
    public function test_an_order_that_cannot_be_received_reports_its_status_not_its_quantities(): void
    {
        $college = $this->makeCollege('IPO20');
        $user = $this->makeUserWithPermissions($college, ['inventory_purchase_orders.receive']);
        $vendor = $this->makeInventoryVendor($college);
        $item = $this->makeInventoryItem($college);

        // Draft: the line still has its whole quantity outstanding.
        $draft = $this->makeInventoryPurchaseOrder($college, ['vendor_id' => $vendor->id]);
        $draftLine = $this->addPurchaseOrderLine($draft, $item, ['quantity' => '5.00']);

        $this->asCollege($college, $user)
            ->post(route('inventory-purchase-orders.receive.store', $draft), ['receipts' => [['line_id' => $draftLine->id, 'quantity' => '1']], 'movement_date' => '2026-10-01'])
            ->assertSessionHasErrors('status');

        // Cancelled: same answer, whatever the quantities say.
        $cancelled = $this->makeInventoryPurchaseOrder($college, ['vendor_id' => $vendor->id, 'status' => InventoryPurchaseOrder::STATUS_CANCELLED]);
        $cancelledLine = $this->addPurchaseOrderLine($cancelled, $item, ['quantity' => '5.00']);

        $this->asCollege($college, $user)
            ->post(route('inventory-purchase-orders.receive.store', $cancelled), ['receipts' => [['line_id' => $cancelledLine->id, 'quantity' => '1']], 'movement_date' => '2026-10-01'])
            ->assertSessionHasErrors('status');

        // A receivable order still reports a bad quantity as a bad quantity.
        $open = $this->makeInventoryPurchaseOrder($college, ['vendor_id' => $vendor->id, 'status' => InventoryPurchaseOrder::STATUS_SUBMITTED]);
        $openLine = $this->addPurchaseOrderLine($open, $item, ['quantity' => '5.00']);

        $this->asCollege($college, $user)
            ->post(route('inventory-purchase-orders.receive.store', $open), ['receipts' => [['line_id' => $openLine->id, 'quantity' => '5.01']], 'movement_date' => '2026-10-01'])
            ->assertSessionHasErrors('receipts.0.quantity');

        $this->withTenant($college, function (): void {
            $this->assertSame(0, InventoryStockMovement::query()->count());
        });
    }
}
