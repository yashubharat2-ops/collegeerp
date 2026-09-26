<?php

namespace Tests\Feature\Inventory;

use App\Models\InventoryAssignment;
use App\Models\InventoryItem;
use App\Models\InventoryStockMovement;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Inventory / Asset Management — Phase 3, Asset Assignment.
 *
 * An assignment is custody, not consumption: the asset's on-hand quantity
 * and the stock ledger are untouched, and the assignment row IS the
 * custody history. At most one ACTIVE assignment per asset; a return keeps
 * the row and a later re-assignment is a NEW row. Covers the one-active
 * rule, the item-type guard, the RBAC on the own permission family, tenant
 * isolation and the append-only route surface.
 */
class InventoryAssignmentTest extends TestCase
{
    use InventoryTestHelpers;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function assignmentPayload(InventoryItem $item, int $assigneeId, array $overrides = []): array
    {
        return array_merge([
            'item_id' => $item->id,
            'assigned_to_type' => 'student',
            'assigned_to_id' => $assigneeId,
            'purpose' => 'Project work',
            'assigned_on' => '2026-09-26',
        ], $overrides);
    }

    public function test_the_phase_three_assignment_routes_exist_and_are_append_only(): void
    {
        $this->assertTrue(Schema::hasTable('inventory_assignments'));
        $this->assertFalse(Schema::hasTable('assets'));

        $this->assertTrue(Route::has('inventory-assignments.index'));
        $this->assertTrue(Route::has('inventory-assignments.create'));
        $this->assertTrue(Route::has('inventory-assignments.store'));
        // No update / delete: the history is corrected by returns, not edits.
        $this->assertFalse(Route::has('inventory-assignments.update'));
        $this->assertFalse(Route::has('inventory-assignments.edit'));
        $this->assertFalse(Route::has('inventory-assignments.delete'));
        $this->assertFalse(Route::has('inventory-assignments.restore'));
    }

    public function test_assigning_an_asset_does_not_touch_stock_or_the_ledger(): void
    {
        $college = $this->makeCollege('IAS1');
        $user = $this->makeUserWithPermissions($college, ['inventory_assignments.view', 'inventory_assignments.create']);
        $asset = $this->makeAsset($college, ['name' => 'Projector', 'quantity' => '1.00']);
        $student = $this->makeStudent($college, ['first_name' => 'Kiran', 'last_name' => 'Sharma']);

        $this->asCollege($college, $user)
            ->post(route('inventory-assignments.store'), $this->assignmentPayload($asset, $student->id))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('inventory-assignments.index', ['item_id' => $asset->id]));

        $this->withTenant($college, function () use ($college, $asset, $student, $user): void {
            $assignment = InventoryAssignment::query()->firstOrFail();
            $this->assertSame($asset->id, $assignment->item_id);
            $this->assertSame('student', $assignment->assigned_to_type);
            $this->assertSame($student->id, $assignment->assigned_to_id);
            $this->assertSame('Kiran Sharma', $assignment->assigneeName());
            $this->assertSame('2026-09-26', $assignment->assigned_on->toDateString());
            $this->assertTrue($assignment->isActive());
            $this->assertSame($college->id, $assignment->college_id);
            $this->assertSame($user->id, $assignment->created_by);

            // Custody, not consumption.
            $this->assertSame('1.00', $asset->fresh()->quantity);
            $this->assertSame(0, InventoryStockMovement::query()->count(), 'No ledger row is written for an assignment.');
        });

        $this->asCollege($college, $user)
            ->get(route('inventory-assignments.index'))
            ->assertOk()
            ->assertSee('Projector')
            ->assertSee('Kiran Sharma');
    }

    public function test_an_asset_with_an_active_assignment_cannot_be_assigned_again(): void
    {
        $college = $this->makeCollege('IAS2');
        $user = $this->makeUserWithPermissions($college, ['inventory_assignments.create']);
        $asset = $this->makeAsset($college);
        $student = $this->makeStudent($college);
        $this->makeInventoryAssignment($college, $asset);

        $this->asCollege($college, $user)
            ->post(route('inventory-assignments.store'), $this->assignmentPayload($asset, $student->id))
            ->assertSessionHasErrors('item_id');

        $this->withTenant($college, fn () => $this->assertSame(1, InventoryAssignment::query()->count(), 'No duplicate active row.'));
    }

    public function test_a_consumable_cannot_be_assigned_and_is_pointed_at_issuing(): void
    {
        $college = $this->makeCollege('IAS3');
        $user = $this->makeUserWithPermissions($college, ['inventory_assignments.create']);
        $consumable = $this->makeIssuableConsumable($college);
        $student = $this->makeStudent($college);

        $this->asCollege($college, $user)
            ->post(route('inventory-assignments.store'), $this->assignmentPayload($consumable, $student->id))
            ->assertSessionHasErrors('item_id');

        $this->withTenant($college, fn () => $this->assertSame(0, InventoryAssignment::query()->count()));
    }

    public function test_the_assignee_must_be_a_student_or_staff_of_the_same_college(): void
    {
        $college = $this->makeCollege('IAS4');
        $other = $this->makeCollege('IAS4X');
        $user = $this->makeUserWithPermissions($college, ['inventory_assignments.create']);
        $asset = $this->makeAsset($college);
        $foreignStudent = $this->makeStudent($other);
        $faculty = $this->makeFaculty($college);

        $this->asCollege($college, $user)
            ->post(route('inventory-assignments.store'), $this->assignmentPayload($asset, $foreignStudent->id))
            ->assertSessionHasErrors('assigned_to_id');

        $this->withTenant($college, fn () => $this->assertSame(0, InventoryAssignment::query()->count()));

        $this->asCollege($college, $user)
            ->post(route('inventory-assignments.store'), $this->assignmentPayload($asset, $faculty->id, ['assigned_to_type' => 'faculty']))
            ->assertSessionHasNoErrors();

        $this->withTenant($college, function () use ($faculty): void {
            $assignment = InventoryAssignment::query()->firstOrFail();
            $this->assertSame('faculty', $assignment->assigned_to_type);
            $this->assertSame($faculty->id, $assignment->assigned_to_id);
        });
    }

    public function test_a_foreign_colleges_asset_cannot_be_assigned(): void
    {
        $college = $this->makeCollege('IAS5');
        $other = $this->makeCollege('IAS5X');
        $user = $this->makeUserWithPermissions($college, ['inventory_assignments.create']);
        $foreignAsset = $this->makeAsset($other);
        $student = $this->makeStudent($college);

        $this->asCollege($college, $user)
            ->post(route('inventory-assignments.store'), $this->assignmentPayload($foreignAsset, $student->id))
            ->assertSessionHasErrors('item_id');

        $this->withTenant($college, fn () => $this->assertSame(0, InventoryAssignment::query()->count()));
    }

    public function test_viewing_requires_the_own_view_permission(): void
    {
        $college = $this->makeCollege('IAS6');
        $assignment = $this->makeInventoryAssignment($college, $this->makeAsset($college));

        $this->asCollege($college, $this->makeBystanderUser($college))
            ->get(route('inventory-assignments.index'))
            ->assertForbidden();

        $viewer = $this->makeUserWithPermissions($college, ['inventory_assignments.view']);
        $this->asCollege($college, $viewer)
            ->get(route('inventory-assignments.index'))
            ->assertOk()
            ->assertSee('Fixture assignment');
    }

    public function test_assigning_requires_the_create_permission(): void
    {
        $college = $this->makeCollege('IAS7');
        $viewer = $this->makeUserWithPermissions($college, ['inventory_assignments.view']);
        $asset = $this->makeAsset($college);
        $student = $this->makeStudent($college);

        $this->asCollege($college, $viewer)
            ->post(route('inventory-assignments.store'), $this->assignmentPayload($asset, $student->id))
            ->assertForbidden();

        $this->withTenant($college, fn () => $this->assertSame(0, InventoryAssignment::query()->count()));
    }

    public function test_assignments_are_tenant_isolated_in_the_listing(): void
    {
        $college = $this->makeCollege('IAS8');
        $other = $this->makeCollege('IAS8X');
        $viewer = $this->makeUserWithPermissions($college, ['inventory_assignments.view']);
        $this->makeInventoryAssignment($college, $this->makeAsset($college), ['purpose' => 'Home college']);
        $this->makeInventoryAssignment($other, $this->makeAsset($other), ['purpose' => 'Other college']);

        $this->asCollege($college, $viewer)
            ->get(route('inventory-assignments.index'))
            ->assertOk()
            ->assertSee('Home college')
            ->assertDontSee('Other college', false);
    }

    public function test_the_status_filter_narrows_the_history(): void
    {
        $college = $this->makeCollege('IAS9');
        $viewer = $this->makeUserWithPermissions($college, ['inventory_assignments.view']);
        $asset = $this->makeAsset($college);
        $this->makeInventoryAssignment($college, $asset, ['status' => InventoryAssignment::STATUS_RETURNED, 'purpose' => 'Older lending']);
        $this->makeInventoryAssignment($college, $asset, ['status' => InventoryAssignment::STATUS_ACTIVE, 'purpose' => 'Current lending']);

        $this->asCollege($college, $viewer)
            ->get(route('inventory-assignments.index', ['status' => 'active']))
            ->assertOk()
            ->assertSee('Current lending')
            ->assertDontSee('Older lending', false);
    }
}
