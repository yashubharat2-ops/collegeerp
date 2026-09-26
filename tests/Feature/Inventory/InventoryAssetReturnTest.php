<?php

namespace Tests\Feature\Inventory;

use App\Models\InventoryAssignment;
use App\Models\InventoryItem;
use App\Models\InventoryStockMovement;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Inventory / Asset Management — Phase 3, Asset Return.
 *
 * A return flips an ACTIVE assignment to `returned` with the return date,
 * the acting user and optional notes. The row is never deleted — its
 * history (who held it, since when, who took it back) stays in place — and a
 * later re-assignment of the same asset is a NEW row. Stock is untouched
 * throughout. Covers history preservation, the double-return guard, the
 * database-level one-active-assignment guard, the RBAC on the own
 * permission family and tenant isolation.
 */
class InventoryAssetReturnTest extends TestCase
{
    use InventoryTestHelpers;

    /**
     * Run a callback with foreign key enforcement on (no-op off SQLite).
     */
    private function withForeignKeysOn(callable $callback): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = ON');
        }

        $callback();
    }

    public function test_the_return_route_surface_is_view_and_record_only(): void
    {
        $this->assertTrue(Route::has('inventory-asset-returns.index'));
        $this->assertTrue(Route::has('inventory-asset-returns.store'));
        // A return is not a delete: no delete / restore route, and the
        // assignment rows themselves stay append-only.
        $this->assertFalse(Route::has('inventory-asset-returns.delete'));
        $this->assertFalse(Route::has('inventory-asset-returns.restore'));
        $this->assertFalse(Route::has('inventory-assignments.delete'));
    }

    public function test_returning_an_asset_preserves_the_assignment_row_and_reassignment_is_a_new_row(): void
    {
        $college = $this->makeCollege('IRT1');
        $assigner = $this->makeUserWithPermissions($college, ['inventory_assignments.create']);
        $returner = $this->makeUserWithPermissions($college, ['inventory_asset_returns.view', 'inventory_asset_returns.create']);
        $asset = $this->makeAsset($college, ['name' => 'Laptop']);
        $student = $this->makeStudent($college, ['first_name' => 'Mira', 'last_name' => 'Patel']);
        $faculty = $this->makeFaculty($college, ['first_name' => 'Dev', 'last_name' => 'Mehta']);

        $this->asCollege($college, $assigner)
            ->post(route('inventory-assignments.store'), [
                'item_id' => $asset->id,
                'assigned_to_type' => 'student',
                'assigned_to_id' => $student->id,
                'purpose' => 'Semester project',
                'assigned_on' => '2026-09-20',
            ])
            ->assertSessionHasNoErrors();

        $assignment = $this->withTenant($college, fn () => InventoryAssignment::query()->firstOrFail());

        // Return it.
        $this->asCollege($college, $returner)
            ->post(route('inventory-asset-returns.store'), [
                'assignment_id' => $assignment->id,
                'returned_on' => '2026-09-26',
                'return_notes' => 'All keys intact',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('inventory-asset-returns.index'));

        $this->withTenant($college, function () use ($college, $assignment, $returner, $asset, $student, $faculty): void {
            // Same row — updated in place, never deleted.
            $returned = InventoryAssignment::query()->whereKey($assignment->id)->firstOrFail();
            $this->assertTrue($returned->isReturned());
            $this->assertSame('2026-09-26', $returned->returned_on->toDateString());
            $this->assertSame($returner->id, $returned->returned_by);
            $this->assertSame('All keys intact', $returned->return_notes);
            $this->assertSame($student->id, $returned->assigned_to_id, 'The original custodian is preserved.');
            $this->assertSame('Semester project', $returned->purpose, 'The original purpose is preserved.');
            $this->assertSame($returner->id, $returned->updated_by);

            // Custody, not consumption: stock and ledger untouched.
            $this->assertSame('1.00', $asset->fresh()->quantity);
            $this->assertSame(0, InventoryStockMovement::query()->count());
        });

        // The returned asset can be assigned again — as a NEW row.
        $this->asCollege($college, $assigner)
            ->post(route('inventory-assignments.store'), [
                'item_id' => $asset->id,
                'assigned_to_type' => 'faculty',
                'assigned_to_id' => $faculty->id,
                'purpose' => 'Lab duty',
                'assigned_on' => '2026-09-27',
            ])
            ->assertSessionHasNoErrors();

        $this->withTenant($college, function () use ($assignment, $faculty): void {
            $rows = InventoryAssignment::query()->orderBy('id')->get();
            $this->assertCount(2, $rows, 'The history keeps both rows.');

            $first = $rows->first();
            $second = $rows->last();
            $this->assertSame(InventoryAssignment::STATUS_RETURNED, $first->status);
            $this->assertSame(InventoryAssignment::STATUS_ACTIVE, $second->status);
            $this->assertNotSame($first->getKey(), $second->getKey(), 'Re-assignment is a new row.');
            $this->assertSame($faculty->id, $second->assigned_to_id);
        });
    }

    public function test_an_asset_that_is_already_returned_cannot_be_returned_again(): void
    {
        $college = $this->makeCollege('IRT2');
        $user = $this->makeUserWithPermissions($college, ['inventory_asset_returns.view', 'inventory_asset_returns.create']);
        $asset = $this->makeAsset($college);
        $assignment = $this->makeInventoryAssignment($college, $asset, [
            'status' => InventoryAssignment::STATUS_RETURNED,
            'returned_on' => '2026-09-01',
            'return_notes' => 'Already back',
        ]);

        $this->asCollege($college, $user)
            ->post(route('inventory-asset-returns.store'), [
                'assignment_id' => $assignment->id,
                'returned_on' => '2026-09-26',
                'return_notes' => 'Second attempt',
            ])
            ->assertSessionHasErrors('assignment_id');

        $this->withTenant($college, function () use ($assignment): void {
            $row = InventoryAssignment::query()->whereKey($assignment->id)->firstOrFail();
            $this->assertSame('2026-09-01', $row->returned_on->toDateString(), 'The original return is untouched.');
            $this->assertSame('Already back', $row->return_notes);
        });
    }

    public function test_the_listing_shows_only_active_assignments_and_respects_the_return_create_permission(): void
    {
        $college = $this->makeCollege('IRT3');
        $asset = $this->makeAsset($college);
        $this->makeInventoryAssignment($college, $asset, ['status' => InventoryAssignment::STATUS_RETURNED, 'purpose' => 'Old lending']);
        $active = $this->makeInventoryAssignment($college, $asset, ['status' => InventoryAssignment::STATUS_ACTIVE, 'purpose' => 'Out now']);

        // View only: the screen renders but the return form does not.
        $viewer = $this->makeUserWithPermissions($college, ['inventory_asset_returns.view']);
        $response = $this->asCollege($college, $viewer)->get(route('inventory-asset-returns.index'))->assertOk();
        $response->assertSee('Out now');
        $response->assertDontSee('Old lending', false);
        $response->assertDontSee('Return asset', false);

        // With the create permission the per-row return form appears.
        $returner = $this->makeUserWithPermissions($college, ['inventory_asset_returns.view', 'inventory_asset_returns.create']);
        $this->asCollege($college, $returner)
            ->get(route('inventory-asset-returns.index'))
            ->assertOk()
            ->assertSee('Return asset')
            ->assertSee((string) $active->id);
    }

    public function test_returning_requires_the_own_create_permission(): void
    {
        $college = $this->makeCollege('IRT4');
        $viewer = $this->makeUserWithPermissions($college, ['inventory_asset_returns.view']);
        $assignment = $this->makeInventoryAssignment($college, $this->makeAsset($college));

        $this->asCollege($college, $viewer)
            ->post(route('inventory-asset-returns.store'), [
                'assignment_id' => $assignment->id,
                'returned_on' => '2026-09-26',
            ])
            ->assertForbidden();

        $this->withTenant($college, fn () => $this->assertTrue(InventoryAssignment::query()->firstOrFail()->isActive()));
    }

    public function test_an_assignment_of_another_college_cannot_be_returned(): void
    {
        $college = $this->makeCollege('IRT5');
        $other = $this->makeCollege('IRT5X');
        $user = $this->makeUserWithPermissions($college, ['inventory_asset_returns.view', 'inventory_asset_returns.create']);
        $foreignAssignment = $this->makeInventoryAssignment($other, $this->makeAsset($other));

        $this->asCollege($college, $user)
            ->post(route('inventory-asset-returns.store'), [
                'assignment_id' => $foreignAssignment->id,
                'returned_on' => '2026-09-26',
            ])
            ->assertSessionHasErrors('assignment_id');

        $this->withTenant($other, fn () => $this->assertTrue(InventoryAssignment::query()->firstOrFail()->isActive(), 'The foreign row stays active.'));
    }

    public function test_the_database_rejects_two_active_assignments_for_one_asset(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite' && DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The partial unique index guard is defined for SQLite and PostgreSQL.');
        }

        $college = $this->makeCollege('IRT6');
        $other = $this->makeCollege('IRT6X');
        $asset = $this->makeAsset($college);
        $student = $this->makeStudent($college);
        $foreignStudent = $this->makeStudent($other);

        // First active row: written through the service path's own shape.
        InventoryAssignment::create([
            'college_id' => $college->id,
            'item_id' => $asset->id,
            'assigned_to_type' => 'student',
            'assigned_to_id' => $student->id,
            'purpose' => 'First',
            'assigned_on' => '2026-09-20',
            'status' => InventoryAssignment::STATUS_ACTIVE,
        ]);

        $this->withForeignKeysOn(function () use ($college, $asset, $foreignStudent): void {
            try {
                // A second active row for the SAME asset from a raw write — the
                // row-locked service check cannot catch this, the index must.
                InventoryAssignment::withoutGlobalScopes()->create([
                    'college_id' => $college->id,
                    'item_id' => $asset->id,
                    'assigned_to_type' => 'student',
                    'assigned_to_id' => $foreignStudent->id,
                    'purpose' => 'Rogue second active',
                    'assigned_on' => '2026-09-21',
                    'status' => InventoryAssignment::STATUS_ACTIVE,
                ]);

                $this->fail('The partial unique index must allow at most one active assignment per asset.');
            } catch (QueryException $exception) {
                $this->assertStringContainsStringIgnoringCase('unique', $exception->getMessage());
            }
        });

        $this->withTenant($college, fn () => $this->assertSame(1, InventoryAssignment::query()->count()));
    }

    public function test_the_database_rejects_an_assignment_pointing_at_another_colleges_asset(): void
    {
        $this->withForeignKeysOn(function (): void {
            $college = $this->makeCollege('IRT7');
            $other = $this->makeCollege('IRT7X');
            $foreignAsset = $this->makeAsset($other);
            $student = $this->makeStudent($college);

            try {
                InventoryAssignment::withoutGlobalScopes()->create([
                    // Claims the active college but points at another
                    // college's asset: the composite key has no such parent row.
                    'college_id' => $college->id,
                    'item_id' => $foreignAsset->id,
                    'assigned_to_type' => 'student',
                    'assigned_to_id' => $student->id,
                    'purpose' => 'Cross tenant',
                    'assigned_on' => '2026-09-20',
                    'status' => InventoryAssignment::STATUS_ACTIVE,
                ]);

                $this->fail('The composite foreign key must reject an asset of another college.');
            } catch (QueryException $exception) {
                $this->assertStringContainsStringIgnoringCase('foreign key', $exception->getMessage());
            }

            $this->withTenant($college, fn () => $this->assertSame(0, InventoryAssignment::query()->count()));
        });
    }
}
