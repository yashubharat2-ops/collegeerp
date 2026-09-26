<?php

namespace Tests\Feature\Inventory;

use App\Models\InventoryMaintenance;
use App\Models\InventoryItem;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Inventory / Asset Management — Phase 3, Asset Maintenance.
 *
 * A maintenance record is always linked to an EXISTING asset from the Items
 * / Assets master (no duplicate asset entity), and optional external work
 * reuses the Phase 1 vendor master. A record is a live work order: it can be
 * edited as the status walks scheduled → in progress → completed, with costs
 * and the completion date filled in along the way. Completed requires the
 * completion date. There is no delete route — records are corrected, not
 * removed. Covers the linkage, the status walk, the RBAC on the own
 * permission family and tenant isolation.
 */
class InventoryMaintenanceTest extends TestCase
{
    use InventoryTestHelpers;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function maintenancePayload(InventoryItem $item, array $overrides = []): array
    {
        return array_merge([
            'item_id' => $item->id,
            'vendor_id' => '',
            'title' => 'Annual service',
            'maintenance_type' => InventoryMaintenance::TYPE_PREVENTIVE,
            'status' => InventoryMaintenance::STATUS_SCHEDULED,
            'scheduled_on' => '2026-10-05',
            'completed_on' => '',
            'cost' => '',
            'performed_by' => '',
            'description' => '',
        ], $overrides);
    }

    public function test_the_phase_three_maintenance_routes_exist_with_update_and_no_delete(): void
    {
        $this->assertTrue(Route::has('inventory-maintenances.index'));
        $this->assertTrue(Route::has('inventory-maintenances.create'));
        $this->assertTrue(Route::has('inventory-maintenances.store'));
        $this->assertTrue(Route::has('inventory-maintenances.edit'));
        $this->assertTrue(Route::has('inventory-maintenances.update'));
        // The record is corrected, never removed.
        $this->assertFalse(Route::has('inventory-maintenances.delete'));
        $this->assertFalse(Route::has('inventory-maintenances.restore'));
    }

    public function test_a_maintenance_record_stays_linked_to_its_asset_and_stock_is_not_involved(): void
    {
        $college = $this->makeCollege('IMT1');
        $user = $this->makeUserWithPermissions($college, ['inventory_maintenance.view', 'inventory_maintenance.create']);
        $asset = $this->makeAsset($college, ['name' => 'Projector', 'quantity' => '1.00']);
        $vendor = $this->makeInventoryVendor($college, ['name' => 'ACME Repair']);

        $this->asCollege($college, $user)
            ->post(route('inventory-maintenances.store'), $this->maintenancePayload($asset, [
                'vendor_id' => $vendor->id,
                'title' => 'Lamp replacement',
                'maintenance_type' => InventoryMaintenance::TYPE_REPAIR,
                'status' => InventoryMaintenance::STATUS_COMPLETED,
                'completed_on' => '2026-09-25',
                'cost' => '1250',
                'performed_by' => 'ACME technician',
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('inventory-maintenances.index', ['item_id' => $asset->id]));

        $this->withTenant($college, function () use ($college, $asset, $vendor, $user): void {
            $maintenance = InventoryMaintenance::query()->firstOrFail();
            $this->assertSame($asset->id, $maintenance->item_id, 'Always linked to the existing asset.');
            $this->assertSame($vendor->id, $maintenance->vendor_id, 'External work reuses the Phase 1 vendor master.');
            $this->assertSame('Lamp replacement', $maintenance->title);
            $this->assertSame(InventoryMaintenance::TYPE_REPAIR, $maintenance->maintenance_type);
            $this->assertTrue($maintenance->isCompleted());
            $this->assertSame('2026-09-25', $maintenance->completed_on->toDateString());
            $this->assertSame('1250.00', $maintenance->cost);
            $this->assertSame($college->id, $maintenance->college_id);
            $this->assertSame($user->id, $maintenance->created_by);

            // Work, not movement: the asset's stock is untouched.
            $this->assertSame('1.00', $asset->fresh()->quantity);
        });
    }

    public function test_a_consumable_cannot_have_maintenance(): void
    {
        $college = $this->makeCollege('IMT2');
        $user = $this->makeUserWithPermissions($college, ['inventory_maintenance.create']);
        $consumable = $this->makeIssuableConsumable($college);

        $this->asCollege($college, $user)
            ->post(route('inventory-maintenances.store'), $this->maintenancePayload($consumable))
            ->assertSessionHasErrors('item_id');

        $this->withTenant($college, fn () => $this->assertSame(0, InventoryMaintenance::query()->count()));
    }

    public function test_completed_requires_the_completion_date(): void
    {
        $college = $this->makeCollege('IMT3');
        $user = $this->makeUserWithPermissions($college, ['inventory_maintenance.create']);
        $asset = $this->makeAsset($college);

        $this->asCollege($college, $user)
            ->post(route('inventory-maintenances.store'), $this->maintenancePayload($asset, [
                'status' => InventoryMaintenance::STATUS_COMPLETED,
                'completed_on' => '',
            ]))
            ->assertSessionHasErrors('completed_on');

        $this->withTenant($college, fn () => $this->assertSame(0, InventoryMaintenance::query()->count()));
    }

    public function test_a_foreign_colleges_vendor_cannot_be_linked(): void
    {
        $college = $this->makeCollege('IMT4');
        $other = $this->makeCollege('IMT4X');
        $user = $this->makeUserWithPermissions($college, ['inventory_maintenance.create']);
        $asset = $this->makeAsset($college);
        $foreignVendor = $this->makeInventoryVendor($other);

        $this->asCollege($college, $user)
            ->post(route('inventory-maintenances.store'), $this->maintenancePayload($asset, [
                'vendor_id' => $foreignVendor->id,
            ]))
            ->assertSessionHasErrors('vendor_id');

        $this->withTenant($college, fn () => $this->assertSame(0, InventoryMaintenance::query()->count()));
    }

    public function test_the_status_walks_through_edits_and_the_link_cannot_be_changed(): void
    {
        $college = $this->makeCollege('IMT5');
        $user = $this->makeUserWithPermissions($college, ['inventory_maintenance.view', 'inventory_maintenance.update']);
        $asset = $this->makeAsset($college);
        $otherAsset = $this->makeAsset($college, ['name' => 'Another asset']);
        $maintenance = $this->makeInventoryMaintenance($college, $asset);

        // scheduled → in progress, with a cost.
        $this->asCollege($college, $user)
            ->put(route('inventory-maintenances.update', $maintenance), [
                'title' => $maintenance->title,
                'maintenance_type' => $maintenance->maintenance_type,
                'status' => InventoryMaintenance::STATUS_IN_PROGRESS,
                'vendor_id' => '',
                'scheduled_on' => $maintenance->scheduled_on?->toDateString(),
                'completed_on' => '',
                'cost' => '750.50',
                'performed_by' => 'Internal team',
                'description' => 'Opened the chassis',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('inventory-maintenances.index'));

        // → completed without a date is refused.
        $this->asCollege($college, $user)
            ->put(route('inventory-maintenances.update', $maintenance), [
                'title' => $maintenance->title,
                'maintenance_type' => $maintenance->maintenance_type,
                'status' => InventoryMaintenance::STATUS_COMPLETED,
                'vendor_id' => '',
                'scheduled_on' => $maintenance->scheduled_on?->toDateString(),
                'completed_on' => '',
                'cost' => '750.50',
                'performed_by' => 'Internal team',
                'description' => 'Opened the chassis',
            ])
            ->assertSessionHasErrors('completed_on');

        // → completed with the date succeeds.
        $this->asCollege($college, $user)
            ->put(route('inventory-maintenances.update', $maintenance), [
                'title' => 'Lamp replacement',
                'maintenance_type' => InventoryMaintenance::TYPE_REPAIR,
                'status' => InventoryMaintenance::STATUS_COMPLETED,
                'vendor_id' => '',
                'scheduled_on' => '2026-10-05',
                'completed_on' => '2026-10-06',
                'cost' => '750.50',
                'performed_by' => 'Internal team',
                'description' => 'Lamp replaced, tested',
            ])
            ->assertSessionHasNoErrors();

        $this->withTenant($college, function () use ($maintenance, $asset, $otherAsset, $user): void {
            $fresh = $maintenance->fresh();
            $this->assertTrue($fresh->isCompleted());
            $this->assertSame('2026-10-06', $fresh->completed_on->toDateString());
            $this->assertSame('750.50', $fresh->cost);
            $this->assertSame('Lamp replacement', $fresh->title);
            $this->assertSame($user->id, $fresh->updated_by);
            $this->assertSame($asset->id, $fresh->item_id, 'The asset link is fixed at creation.');
            $this->assertNotSame($otherAsset->id, $fresh->item_id);
        });
    }

    public function test_viewing_requires_the_own_view_permission(): void
    {
        $college = $this->makeCollege('IMT6');
        $maintenance = $this->makeInventoryMaintenance($college, $this->makeAsset($college));

        $this->asCollege($college, $this->makeBystanderUser($college))
            ->get(route('inventory-maintenances.index'))
            ->assertForbidden();

        $viewer = $this->makeUserWithPermissions($college, ['inventory_maintenance.view']);
        $this->asCollege($college, $viewer)
            ->get(route('inventory-maintenances.index'))
            ->assertOk()
            ->assertSee('Fixture maintenance');
    }

    public function test_recording_and_editing_require_their_own_permissions(): void
    {
        $college = $this->makeCollege('IMT7');
        $asset = $this->makeAsset($college);

        $viewer = $this->makeUserWithPermissions($college, ['inventory_maintenance.view']);

        $this->asCollege($college, $viewer)
            ->post(route('inventory-maintenances.store'), $this->maintenancePayload($asset))
            ->assertForbidden();

        $maintenance = $this->makeInventoryMaintenance($college, $asset);
        $this->asCollege($college, $viewer)
            ->get(route('inventory-maintenances.edit', $maintenance))
            ->assertForbidden();
        $this->asCollege($college, $viewer)
            ->put(route('inventory-maintenances.update', $maintenance), [
                'title' => 'Changed',
                'maintenance_type' => $maintenance->maintenance_type,
                'status' => $maintenance->status,
                'vendor_id' => '',
                'scheduled_on' => '',
                'completed_on' => '',
                'cost' => '',
                'performed_by' => '',
                'description' => '',
            ])
            ->assertForbidden();

        $this->withTenant($college, fn () => $this->assertSame('Fixture maintenance', InventoryMaintenance::query()->firstOrFail()->title));
    }

    public function test_a_foreign_colleges_record_cannot_be_edited(): void
    {
        $college = $this->makeCollege('IMT8');
        $other = $this->makeCollege('IMT8X');
        $user = $this->makeUserWithPermissions($college, ['inventory_maintenance.view', 'inventory_maintenance.update']);
        $foreignMaintenance = $this->makeInventoryMaintenance($other, $this->makeAsset($other));

        // Route model binding is tenant-scoped: a foreign id is a 404, not an edit.
        $this->asCollege($college, $user)
            ->get(route('inventory-maintenances.edit', $foreignMaintenance))
            ->assertNotFound();

        $this->asCollege($college, $user)
            ->put(route('inventory-maintenances.update', $foreignMaintenance), [
                'title' => 'Hijacked',
                'maintenance_type' => $foreignMaintenance->maintenance_type,
                'status' => $foreignMaintenance->status,
            ])
            ->assertNotFound();

        $this->withTenant($other, fn () => $this->assertSame('Fixture maintenance', InventoryMaintenance::query()->firstOrFail()->title));
    }

    public function test_maintenances_are_tenant_isolated_in_the_listing(): void
    {
        $college = $this->makeCollege('IMT9');
        $other = $this->makeCollege('IMT9X');
        $viewer = $this->makeUserWithPermissions($college, ['inventory_maintenance.view']);
        $this->makeInventoryMaintenance($college, $this->makeAsset($college), ['title' => 'Home college service']);
        $this->makeInventoryMaintenance($other, $this->makeAsset($other), ['title' => 'Other college service']);

        $this->asCollege($college, $viewer)
            ->get(route('inventory-maintenances.index'))
            ->assertOk()
            ->assertSee('Home college service')
            ->assertDontSee('Other college service', false);
    }

    public function test_the_types_and_status_filters_narrow_the_listing(): void
    {
        $college = $this->makeCollege('IMT10');
        $viewer = $this->makeUserWithPermissions($college, ['inventory_maintenance.view']);
        $asset = $this->makeAsset($college);
        $this->makeInventoryMaintenance($college, $asset, [
            'title' => 'Calibration run',
            'maintenance_type' => InventoryMaintenance::TYPE_CALIBRATION,
            'status' => InventoryMaintenance::STATUS_COMPLETED,
            'completed_on' => '2026-09-10',
        ]);
        $this->makeInventoryMaintenance($college, $asset, [
            'title' => 'Lamp repair',
            'maintenance_type' => InventoryMaintenance::TYPE_REPAIR,
            'status' => InventoryMaintenance::STATUS_SCHEDULED,
        ]);

        $this->asCollege($college, $viewer)
            ->get(route('inventory-maintenances.index', ['status' => 'scheduled']))
            ->assertOk()
            ->assertSee('Lamp repair')
            ->assertDontSee('Calibration run', false);
    }
}
