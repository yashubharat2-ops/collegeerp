<?php

namespace Tests\Feature\Inventory;

use App\Models\InventoryItem;
use App\Models\InventoryStockMovement;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Inventory / Asset Management — Phase 2, Stock.
 *
 * Covers the ledger: stock in, stock out and corrections; the on-hand quantity
 * moving in the same transaction; the balance snapshot on every row; the
 * refusal to go negative; immutability; isolation and per-ability RBAC. Also
 * pins that a quantity typed on the item form still lands in the ledger, so no
 * balance is ever unexplained.
 */
class InventoryStockMovementTest extends TestCase
{
    use InventoryTestHelpers;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function movementPayload(InventoryItem $item, array $overrides = []): array
    {
        return array_merge([
            'item_id' => $item->id,
            'type' => InventoryStockMovement::TYPE_STOCK_IN,
            'direction' => InventoryStockMovement::DIRECTION_IN,
            'quantity' => '5',
            'unit_price' => '10.00',
            'reference' => ' grn-1 ',
            'reason' => null,
            'notes' => null,
            'movement_date' => '2026-10-01',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function itemPayload(int $categoryId, array $overrides = []): array
    {
        return array_merge([
            'name' => 'A4 Ream',
            'code' => 'A4-001',
            'category_id' => $categoryId,
            'item_type' => InventoryItem::TYPE_CONSUMABLE,
            'brand' => null,
            'model' => null,
            'serial_number' => null,
            'unit' => 'ream',
            'quantity' => '5',
            'description' => null,
            'status' => InventoryItem::STATUS_ACTIVE,
        ], $overrides);
    }

    public function test_stock_in_raises_the_on_hand_quantity_and_snapshots_the_balance(): void
    {
        $college = $this->makeCollege('ISTK1');
        $user = $this->makeUserWithPermissions($college, ['inventory_stock.view', 'inventory_stock.in']);
        $item = $this->makeInventoryItem($college, ['name' => 'A4 Ream', 'unit' => 'ream', 'quantity' => '2.00']);

        $this->asCollege($college, $user)
            ->post(route('inventory-stock.store'), $this->movementPayload($item))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('inventory-stock.index', ['item_id' => $item->id]));

        $this->withTenant($college, function () use ($item, $user): void {
            $this->assertSame('7.00', $item->fresh()->quantity);

            $movement = InventoryStockMovement::query()->firstOrFail();
            $this->assertSame(InventoryStockMovement::TYPE_STOCK_IN, $movement->type);
            $this->assertSame(InventoryStockMovement::DIRECTION_IN, $movement->direction);
            $this->assertSame('5.00', $movement->quantity);
            $this->assertSame('7.00', $movement->balance_after, 'The row records the balance it left behind.');
            $this->assertSame('10.00', $movement->unit_price);
            $this->assertSame('GRN-1', $movement->reference, 'References are stored upper-cased.');
            $this->assertSame('2026-10-01', $movement->movement_date->toDateString());
            $this->assertSame($user->id, $movement->created_by);
            $this->assertNull($movement->purchase_order_id);
        });
    }

    public function test_stock_out_lowers_the_quantity_and_a_correction_can_go_either_way(): void
    {
        $college = $this->makeCollege('ISTK2');
        $user = $this->makeUserWithPermissions($college, ['inventory_stock.view', 'inventory_stock.out', 'inventory_stock.adjust']);
        $item = $this->makeInventoryItem($college, ['quantity' => '10.00']);

        $this->asCollege($college, $user)
            ->post(route('inventory-stock.store'), $this->movementPayload($item, [
                'type' => InventoryStockMovement::TYPE_STOCK_OUT,
                'direction' => InventoryStockMovement::DIRECTION_OUT,
                'quantity' => '4',
                'reason' => 'Issued to the chemistry lab',
                'unit_price' => null,
            ]))
            ->assertSessionHasNoErrors();

        $this->withTenant($college, fn () => $this->assertSame('6.00', $item->fresh()->quantity));

        // A correction downward.
        $this->asCollege($college, $user)
            ->post(route('inventory-stock.store'), $this->movementPayload($item, [
                'type' => InventoryStockMovement::TYPE_ADJUSTMENT,
                'direction' => InventoryStockMovement::DIRECTION_OUT,
                'quantity' => '1.5',
                'reason' => 'Damaged in the store room',
            ]))
            ->assertSessionHasNoErrors();

        // …and a correction upward.
        $this->asCollege($college, $user)
            ->post(route('inventory-stock.store'), $this->movementPayload($item, [
                'type' => InventoryStockMovement::TYPE_ADJUSTMENT,
                'direction' => InventoryStockMovement::DIRECTION_IN,
                'quantity' => '0.5',
                'reason' => 'Found during stock take',
            ]))
            ->assertSessionHasNoErrors();

        $this->withTenant($college, function () use ($item): void {
            $this->assertSame('5.00', $item->fresh()->quantity);

            $rows = InventoryStockMovement::query()->orderBy('id')->get();
            $this->assertSame(['out', 'out', 'in'], $rows->pluck('direction')->all());
            $this->assertSame(['6.00', '4.50', '5.00'], $rows->pluck('balance_after')->all());
            $this->assertSame(['4.00', '1.50', '0.50'], $rows->pluck('quantity')->all());
            $this->assertSame('Damaged in the store room', $rows[1]->reason);
        });
    }

    public function test_stock_can_never_go_negative(): void
    {
        $college = $this->makeCollege('ISTK3');
        $user = $this->makeUserWithPermissions($college, ['inventory_stock.view', 'inventory_stock.out']);
        $item = $this->makeInventoryItem($college, ['quantity' => '3.00']);

        $this->asCollege($college, $user)
            ->post(route('inventory-stock.store'), $this->movementPayload($item, [
                'type' => InventoryStockMovement::TYPE_STOCK_OUT,
                'direction' => InventoryStockMovement::DIRECTION_OUT,
                'quantity' => '3.01',
                'reason' => 'Written off',
            ]))
            ->assertSessionHasErrors('quantity');

        $this->withTenant($college, function () use ($item): void {
            $this->assertSame('3.00', $item->fresh()->quantity, 'A refused movement changes nothing.');
            $this->assertSame(0, InventoryStockMovement::query()->count());
        });

        // Exactly the balance is allowed.
        $this->asCollege($college, $user)
            ->post(route('inventory-stock.store'), $this->movementPayload($item, [
                'type' => InventoryStockMovement::TYPE_STOCK_OUT,
                'direction' => InventoryStockMovement::DIRECTION_OUT,
                'quantity' => '3.00',
                'reason' => 'Written off',
            ]))
            ->assertSessionHasNoErrors();

        $this->withTenant($college, fn () => $this->assertSame('0.00', $item->fresh()->quantity));
    }

    public function test_the_direction_is_fixed_by_the_movement_type(): void
    {
        $college = $this->makeCollege('ISTK4');
        $user = $this->makeUserWithPermissions($college, ['inventory_stock.view', 'inventory_stock.in', 'inventory_stock.out']);
        $item = $this->makeInventoryItem($college, ['quantity' => '1.00']);

        // A stock in cannot be forced to move stock out.
        $this->asCollege($college, $user)
            ->post(route('inventory-stock.store'), $this->movementPayload($item, ['direction' => InventoryStockMovement::DIRECTION_OUT]))
            ->assertSessionHasErrors('direction');

        // A stock out cannot be forced to move stock in.
        $this->asCollege($college, $user)
            ->post(route('inventory-stock.store'), $this->movementPayload($item, [
                'type' => InventoryStockMovement::TYPE_STOCK_OUT,
                'direction' => InventoryStockMovement::DIRECTION_IN,
                'reason' => 'Issued',
            ]))
            ->assertSessionHasErrors('direction');

        // A correction must say which way it goes.
        $this->asCollege($college, $user)
            ->post(route('inventory-stock.store'), $this->movementPayload($item, [
                'type' => InventoryStockMovement::TYPE_ADJUSTMENT,
                'direction' => '',
                'reason' => 'Stock take',
            ]))
            ->assertSessionHasErrors('direction');

        $this->withTenant($college, function () use ($item): void {
            $this->assertSame('1.00', $item->fresh()->quantity);
            $this->assertSame(0, InventoryStockMovement::query()->count());
        });
    }

    public function test_a_reason_is_required_when_stock_leaves_or_is_corrected(): void
    {
        $college = $this->makeCollege('ISTK5');
        $user = $this->makeUserWithPermissions($college, ['inventory_stock.view', 'inventory_stock.out', 'inventory_stock.adjust']);
        $item = $this->makeInventoryItem($college, ['quantity' => '5.00']);

        $this->asCollege($college, $user)
            ->post(route('inventory-stock.store'), $this->movementPayload($item, [
                'type' => InventoryStockMovement::TYPE_STOCK_OUT,
                'direction' => InventoryStockMovement::DIRECTION_OUT,
                'reason' => '',
            ]))
            ->assertSessionHasErrors('reason');

        $this->asCollege($college, $user)
            ->post(route('inventory-stock.store'), $this->movementPayload($item, [
                'type' => InventoryStockMovement::TYPE_ADJUSTMENT,
                'direction' => InventoryStockMovement::DIRECTION_IN,
                'reason' => '',
            ]))
            ->assertSessionHasErrors('reason');

        // Stock in needs no reason.
        $this->asCollege($college, $user)
            ->post(route('inventory-stock.store'), $this->movementPayload($item, ['reason' => '']))
            ->assertSessionHasNoErrors();
    }

    public function test_the_ledger_is_immutable(): void
    {
        $college = $this->makeCollege('ISTK6');
        $user = $this->makeUserWithPermissions($college, ['inventory_stock.view', 'inventory_stock.in']);
        $item = $this->makeInventoryItem($college);

        $this->assertFalse(Schema::hasColumn('inventory_stock_movements', 'deleted_at'), 'Movements are never soft-deleted.');
        $this->assertFalse(Route::has('inventory-stock.edit'));
        $this->assertFalse(Route::has('inventory-stock.update'));
        $this->assertFalse(Route::has('inventory-stock.destroy'));
        $this->assertFalse(Route::has('inventory-stock.show'));

        $this->asCollege($college, $user)->post(route('inventory-stock.store'), $this->movementPayload($item))->assertSessionHasNoErrors();

        $this->withTenant($college, function (): void {
            $this->assertSame(1, InventoryStockMovement::query()->count());
        });
    }

    public function test_validation_rejects_incomplete_or_invalid_movements(): void
    {
        $college = $this->makeCollege('ISTK7');
        $user = $this->makeUserWithPermissions($college, ['inventory_stock.view', 'inventory_stock.in']);
        $item = $this->makeInventoryItem($college);
        $other = $this->makeCollege('ISTK7X');
        $foreignItem = $this->makeInventoryItem($other);

        $this->asCollege($college, $user)
            ->post(route('inventory-stock.store'), [])
            ->assertSessionHasErrors(['item_id', 'type', 'direction', 'quantity', 'movement_date']);

        $this->asCollege($college, $user)
            ->post(route('inventory-stock.store'), $this->movementPayload($item, ['type' => 'teleport', 'quantity' => '0', 'movement_date' => 'not-a-date']))
            ->assertSessionHasErrors(['type', 'quantity', 'movement_date']);

        // An item of another college can never be moved here.
        $this->asCollege($college, $user)
            ->post(route('inventory-stock.store'), $this->movementPayload($foreignItem))
            ->assertSessionHasErrors('item_id');

        $this->withTenant($college, function (): void {
            $this->assertSame(0, InventoryStockMovement::query()->count());
        });
    }

    public function test_stock_is_isolated_per_college(): void
    {
        $college = $this->makeCollege('ISTK8');
        $other = $this->makeCollege('ISTK8X');
        $user = $this->makeUserWithPermissions($college, ['inventory_stock.view', 'inventory_stock.in']);
        $mine = $this->makeInventoryItem($college, ['name' => 'Mine Only', 'code' => 'MINE']);
        $theirs = $this->makeInventoryItem($other, ['name' => 'Theirs Only', 'code' => 'THEIRS']);

        $this->asCollege($college, $user)->post(route('inventory-stock.store'), $this->movementPayload($mine, ['reference' => 'MINE-REF']))->assertSessionHasNoErrors();
        $this->asCollege($other, $this->makeUserWithPermissions($other, ['inventory_stock.view', 'inventory_stock.in']))
            ->post(route('inventory-stock.store'), $this->movementPayload($theirs, ['reference' => 'THEIRS-REF']))
            ->assertSessionHasNoErrors();

        $this->asCollege($college, $user)
            ->get(route('inventory-stock.index'))
            ->assertOk()
            ->assertSee('Mine Only')
            ->assertSee('MINE-REF')
            ->assertDontSee('Theirs Only')
            ->assertDontSee('THEIRS-REF');

        // A foreign item id simply cannot be moved under this tenant.
        $this->asCollege($college, $user)
            ->post(route('inventory-stock.store'), $this->movementPayload($theirs))
            ->assertSessionHasErrors('item_id');

        $this->withTenant($college, function () use ($theirs): void {
            $this->assertSame(0, InventoryStockMovement::query()->where('item_id', $theirs->id)->count());
        });
    }

    public function test_the_ledger_filters_by_item_type_direction_and_date(): void
    {
        $college = $this->makeCollege('ISTK9');
        $user = $this->makeUserWithPermissions($college, ['inventory_stock.view', 'inventory_stock.in', 'inventory_stock.out']);
        $paper = $this->makeInventoryItem($college, ['name' => 'Paper Only', 'code' => 'PAPER', 'quantity' => '10.00']);
        $pens = $this->makeInventoryItem($college, ['name' => 'Pens Only', 'code' => 'PENS', 'quantity' => '10.00']);

        $this->asCollege($college, $user)->post(route('inventory-stock.store'), $this->movementPayload($paper, ['quantity' => '2', 'movement_date' => '2026-10-01', 'reference' => 'EARLY']))->assertSessionHasNoErrors();
        $this->asCollege($college, $user)->post(route('inventory-stock.store'), $this->movementPayload($pens, [
            'type' => InventoryStockMovement::TYPE_STOCK_OUT,
            'direction' => InventoryStockMovement::DIRECTION_OUT,
            'quantity' => '3',
            'movement_date' => '2026-11-15',
            'reference' => 'LATE',
            'reason' => 'Issued',
        ]))->assertSessionHasNoErrors();

        $this->asCollege($college, $user)
            ->get(route('inventory-stock.index'))
            ->assertOk()
            ->assertSee('EARLY')
            ->assertSee('LATE');

        $this->asCollege($college, $user)
            ->get(route('inventory-stock.index', ['item_id' => $pens->id]))
            ->assertOk()
            ->assertSee('LATE')
            ->assertDontSee('EARLY');

        $this->asCollege($college, $user)
            ->get(route('inventory-stock.index', ['direction' => InventoryStockMovement::DIRECTION_OUT]))
            ->assertOk()
            ->assertSee('LATE')
            ->assertDontSee('EARLY');

        $this->asCollege($college, $user)
            ->get(route('inventory-stock.index', ['type' => InventoryStockMovement::TYPE_STOCK_IN]))
            ->assertOk()
            ->assertSee('EARLY')
            ->assertDontSee('LATE');

        $this->asCollege($college, $user)
            ->get(route('inventory-stock.index', ['from' => '2026-11-01', 'to' => '2026-11-30']))
            ->assertOk()
            ->assertSee('LATE')
            ->assertDontSee('EARLY');
    }

    public function test_recording_stock_is_gated_per_ability_and_the_ledger_needs_the_view_permission(): void
    {
        $college = $this->makeCollege('ISTK10');
        $viewer = $this->makeUserWithPermissions($college, ['inventory_stock.view']);
        $receiver = $this->makeUserWithPermissions($college, ['inventory_stock.in']);
        $nobody = $this->makeUserWithPermissions($college, ['dashboard.view']);
        $item = $this->makeInventoryItem($college, ['quantity' => '10.00']);
        $out = $this->movementPayload($item, ['type' => InventoryStockMovement::TYPE_STOCK_OUT, 'direction' => InventoryStockMovement::DIRECTION_OUT, 'reason' => 'Issued']);
        $correction = $this->movementPayload($item, ['type' => InventoryStockMovement::TYPE_ADJUSTMENT, 'direction' => InventoryStockMovement::DIRECTION_IN, 'reason' => 'Stock take']);

        $this->get(route('inventory-stock.index'))->assertRedirect(route('login'));

        $this->asCollege($college, $nobody)->get(route('inventory-stock.index'))->assertForbidden();
        $this->asCollege($college, $nobody)->get(route('inventory-stock.create'))->assertForbidden();
        $this->asCollege($college, $nobody)->post(route('inventory-stock.store'), $this->movementPayload($item))->assertForbidden();

        // A viewer reads the ledger but cannot write to it.
        $this->asCollege($college, $viewer)->get(route('inventory-stock.index'))->assertOk()->assertDontSee('Record movement');
        $this->asCollege($college, $viewer)->post(route('inventory-stock.store'), $this->movementPayload($item))->assertForbidden();
        $this->asCollege($college, $viewer)->post(route('inventory-stock.store'), $out)->assertForbidden();

        // A receiver may book stock in, reach the form, but not write stock off.
        $this->asCollege($college, $receiver)->get(route('inventory-stock.create'))->assertOk();
        $this->asCollege($college, $receiver)->post(route('inventory-stock.store'), $this->movementPayload($item))->assertRedirect();
        $this->asCollege($college, $receiver)->post(route('inventory-stock.store'), $out)->assertForbidden();
        $this->asCollege($college, $receiver)->post(route('inventory-stock.store'), $correction)->assertForbidden();
        $this->asCollege($college, $receiver)->get(route('inventory-stock.index'))->assertForbidden();
    }

    public function test_an_opening_quantity_on_a_new_item_is_recorded_in_the_ledger(): void
    {
        $college = $this->makeCollege('ISTK11');
        $user = $this->makeUserWithPermissions($college, ['inventory_items.create', 'inventory_stock.view']);
        $category = $this->makeInventoryCategory($college);

        $this->asCollege($college, $user)
            ->post(route('inventory-items.store'), $this->itemPayload($category->id, ['quantity' => '5']))
            ->assertSessionHasNoErrors();

        $item = $this->withTenant($college, fn () => InventoryItem::query()->firstOrFail());

        $this->withTenant($college, function () use ($item): void {
            $movement = InventoryStockMovement::query()->firstOrFail();
            $this->assertSame(InventoryStockMovement::TYPE_OPENING, $movement->type);
            $this->assertSame(InventoryStockMovement::DIRECTION_IN, $movement->direction);
            $this->assertSame('5.00', $movement->quantity);
            $this->assertSame('5.00', $movement->balance_after);
            $this->assertSame($item->id, $movement->item_id);
        });
    }

    public function test_a_quantity_corrected_on_the_item_form_is_recorded_as_an_adjustment(): void
    {
        $college = $this->makeCollege('ISTK12');
        $user = $this->makeUserWithPermissions($college, ['inventory_items.update', 'inventory_stock.view']);
        $category = $this->makeInventoryCategory($college);
        $item = $this->makeInventoryItem($college, ['category_id' => $category->id, 'code' => 'A4-001', 'quantity' => '5.00']);

        $this->asCollege($college, $user)
            ->put(route('inventory-items.update', $item), $this->itemPayload($category->id, ['quantity' => '2']))
            ->assertSessionHasNoErrors();

        $this->withTenant($college, function () use ($item): void {
            $this->assertSame('2.00', $item->fresh()->quantity);

            $movement = InventoryStockMovement::query()->firstOrFail();
            $this->assertSame(InventoryStockMovement::TYPE_ADJUSTMENT, $movement->type);
            $this->assertSame(InventoryStockMovement::DIRECTION_OUT, $movement->direction);
            $this->assertSame('3.00', $movement->quantity);
            $this->assertSame('2.00', $movement->balance_after);
            $this->assertSame('Corrected on the item form', $movement->reason);
        });

        // Saving again without touching the quantity writes nothing new.
        $this->asCollege($college, $user)
            ->put(route('inventory-items.update', $item), $this->itemPayload($category->id, ['quantity' => '2', 'name' => 'A4 Ream (renamed)']))
            ->assertSessionHasNoErrors();

        $this->withTenant($college, function (): void {
            $this->assertSame(1, InventoryStockMovement::query()->count());
        });
    }

    /**
     * The ability a movement needs depends on its type, which is only known
     * once the submission has been read — so a payload that cannot name a
     * usable type is answered with the fields to fix, while a well-formed
     * movement the user may not record is still refused outright. Checking the
     * ability first would turn every typo into a bare 403.
     */
    public function test_a_malformed_movement_gets_field_errors_and_an_ungrantable_one_is_refused(): void
    {
        $college = $this->makeCollege('ISTK13');
        $user = $this->makeUserWithPermissions($college, ['inventory_stock.view', 'inventory_stock.in']);
        $item = $this->makeInventoryItem($college, ['quantity' => '4.00']);

        // Nothing usable submitted at all.
        $this->asCollege($college, $user)
            ->post(route('inventory-stock.store'), [])
            ->assertSessionHasErrors(['item_id', 'type', 'direction', 'quantity', 'movement_date']);

        // A type that can never write stock is a field error, not a refusal.
        $this->asCollege($college, $user)
            ->post(route('inventory-stock.store'), $this->movementPayload($item, ['type' => 'teleport']))
            ->assertSessionHasErrors('type');

        // A correction this user may not record, but whose direction is missing:
        // the missing field is reported first.
        $this->asCollege($college, $user)
            ->post(route('inventory-stock.store'), $this->movementPayload($item, [
                'type' => InventoryStockMovement::TYPE_ADJUSTMENT,
                'direction' => '',
                'reason' => 'Stock take',
            ]))
            ->assertSessionHasErrors('direction');

        // The same correction, complete: now the ability decides, and it says no.
        $this->asCollege($college, $user)
            ->post(route('inventory-stock.store'), $this->movementPayload($item, [
                'type' => InventoryStockMovement::TYPE_ADJUSTMENT,
                'direction' => InventoryStockMovement::DIRECTION_IN,
                'reason' => 'Stock take',
            ]))
            ->assertForbidden();

        $this->withTenant($college, function () use ($item): void {
            $this->assertSame('4.00', $item->fresh()->quantity);
            $this->assertSame(0, InventoryStockMovement::query()->count());
        });
    }
}
