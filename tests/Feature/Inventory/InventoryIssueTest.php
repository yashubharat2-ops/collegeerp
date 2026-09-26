<?php

namespace Tests\Feature\Inventory;

use App\Models\InventoryIssue;
use App\Models\InventoryItem;
use App\Models\InventoryStockMovement;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Inventory / Asset Management — Phase 3, Item Issue / Allocation.
 *
 * A recorded issue reduces the consumable's on-hand quantity through the
 * EXISTING Phase 2 stock ledger (a `stock_out` movement whose reference is
 * the issue number) and keeps the allocation behind it. Issues are
 * append-only: there is no update and no delete route, and the stock guard
 * never allows a negative balance. Covers the ledger linkage, the auto
 * number, the RBAC on the own permission family, tenant isolation and the
 * route surface.
 */
class InventoryIssueTest extends TestCase
{
    use InventoryTestHelpers;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function issuePayload(InventoryItem $item, int $recipientId, array $overrides = []): array
    {
        return array_merge([
            'item_id' => $item->id,
            'quantity' => '2',
            'issued_to_type' => 'student',
            'issued_to_id' => $recipientId,
            'purpose' => 'Lab requisition',
            'reference' => ' req-7 ',
            'movement_date' => '2026-09-26',
            'notes' => null,
        ], $overrides);
    }

    public function test_the_phase_three_issue_routes_exist_and_are_append_only(): void
    {
        $this->assertTrue(Schema::hasTable('inventory_issues'));
        $this->assertFalse(Schema::hasTable('assets'));

        $this->assertTrue(Route::has('inventory-issues.index'));
        $this->assertTrue(Route::has('inventory-issues.create'));
        $this->assertTrue(Route::has('inventory-issues.store'));
        // Append-only: no update / delete / restore surface.
        $this->assertFalse(Route::has('inventory-issues.update'));
        $this->assertFalse(Route::has('inventory-issues.edit'));
        $this->assertFalse(Route::has('inventory-issues.delete'));
        $this->assertFalse(Route::has('inventory-issues.restore'));
    }

    public function test_recording_an_issue_reduces_stock_through_the_ledger_and_keeps_the_allocation(): void
    {
        $college = $this->makeCollege('IPF1');
        $user = $this->makeUserWithPermissions($college, ['inventory_issues.view', 'inventory_issues.create']);
        $item = $this->makeIssuableConsumable($college, ['name' => 'A4 Ream', 'unit' => 'ream', 'quantity' => '10.00']);
        $student = $this->makeStudent($college, ['first_name' => 'Asha', 'last_name' => 'Kulkarni']);

        $this->asCollege($college, $user)
            ->post(route('inventory-issues.store'), $this->issuePayload($item, $student->id))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('inventory-issues.index', ['item_id' => $item->id]));

        $this->withTenant($college, function () use ($college, $item, $student, $user): void {
            $this->assertSame('8.00', $item->fresh()->quantity, 'Stock is reduced by the issue.');

            // The allocation row, with the server-generated number.
            $issue = InventoryIssue::query()->firstOrFail();
            $this->assertSame('ISS-000001', $issue->number);
            $this->assertSame($item->id, $issue->item_id);
            $this->assertSame('2.00', $issue->quantity);
            $this->assertSame('student', $issue->issued_to_type);
            $this->assertSame($student->id, $issue->issued_to_id);
            $this->assertSame('Asha Kulkarni', $issue->recipientName(), 'The recipient resolves through the morph map.');
            $this->assertSame('Lab requisition', $issue->purpose);
            $this->assertSame('REQ-7', $issue->reference, 'References are stored upper-cased.');
            $this->assertSame('2026-09-26', $issue->movement_date->toDateString());
            $this->assertSame($user->id, $issue->created_by);
            $this->assertSame($college->id, $issue->college_id);

            // The ledger row behind it: stock out, reference is the issue number,
            // balance snapshot matches the new on-hand quantity.
            $movement = InventoryStockMovement::query()->firstOrFail();
            $this->assertSame(InventoryStockMovement::TYPE_STOCK_OUT, $movement->type);
            $this->assertSame(InventoryStockMovement::DIRECTION_OUT, $movement->direction);
            $this->assertSame('2.00', $movement->quantity);
            $this->assertSame('8.00', $movement->balance_after);
            $this->assertSame('ISS-000001', $movement->reference, 'The movement carries the issue number.');
        });

        // The screen shows the issue for the college.
        $this->asCollege($college, $user)
            ->get(route('inventory-issues.index'))
            ->assertOk()
            ->assertSee('ISS-000001')
            ->assertSee('A4 Ream');
    }

    public function test_issue_numbers_increment_per_college(): void
    {
        $college = $this->makeCollege('IPF2');
        $user = $this->makeUserWithPermissions($college, ['inventory_issues.view', 'inventory_issues.create']);
        $item = $this->makeIssuableConsumable($college, ['quantity' => '100.00']);
        $student = $this->makeStudent($college);

        foreach (['ISS-000001', 'ISS-000002'] as $expected) {
            $this->asCollege($college, $user)
                ->post(route('inventory-issues.store'), $this->issuePayload($item, $student->id, ['quantity' => '1']))
                ->assertSessionHasNoErrors();
        }

        $this->withTenant($college, function () {
            $numbers = InventoryIssue::query()->orderBy('id')->pluck('number')->all();
            $this->assertSame(['ISS-000001', 'ISS-000002'], $numbers, 'Numbers increment from the per-college maximum.');
        });
    }

    public function test_an_asset_cannot_be_issued_and_is_pointed_at_assignment(): void
    {
        $college = $this->makeCollege('IPF3');
        $user = $this->makeUserWithPermissions($college, ['inventory_issues.create']);
        $asset = $this->makeAsset($college);
        $student = $this->makeStudent($college);

        $this->asCollege($college, $user)
            ->post(route('inventory-issues.store'), $this->issuePayload($asset, $student->id))
            ->assertSessionHasErrors('item_id');

        $this->withTenant($college, function () use ($asset): void {
            $this->assertSame(0, InventoryIssue::query()->count());
            $this->assertSame('1.00', $asset->fresh()->quantity, 'Stock is untouched when the issue is refused.');
            $this->assertSame(0, InventoryStockMovement::query()->count());
        });
    }

    public function test_an_inactive_item_cannot_be_issued(): void
    {
        $college = $this->makeCollege('IPF4');
        $user = $this->makeUserWithPermissions($college, ['inventory_issues.create']);
        $item = $this->makeIssuableConsumable($college, ['status' => InventoryItem::STATUS_INACTIVE]);
        $student = $this->makeStudent($college);

        $this->asCollege($college, $user)
            ->post(route('inventory-issues.store'), $this->issuePayload($item, $student->id))
            ->assertSessionHasErrors('item_id');

        $this->withTenant($college, fn () => $this->assertSame(0, InventoryIssue::query()->count()));
    }

    public function test_an_issue_cannot_drive_stock_negative(): void
    {
        $college = $this->makeCollege('IPF5');
        $user = $this->makeUserWithPermissions($college, ['inventory_issues.create']);
        $item = $this->makeIssuableConsumable($college, ['quantity' => '1.00']);
        $student = $this->makeStudent($college);

        $this->asCollege($college, $user)
            ->post(route('inventory-issues.store'), $this->issuePayload($item, $student->id, ['quantity' => '5']))
            ->assertSessionHasErrors('quantity');

        $this->withTenant($college, function () use ($item): void {
            $this->assertSame(0, InventoryIssue::query()->count(), 'Nothing is recorded when the guard fires.');
            $this->assertSame(0, InventoryStockMovement::query()->count());
            $this->assertSame('1.00', $item->fresh()->quantity);
        });
    }

    public function test_the_recipient_must_be_a_student_or_staff_of_the_same_college(): void
    {
        $college = $this->makeCollege('IPF6');
        $other = $this->makeCollege('IPF6X');
        $user = $this->makeUserWithPermissions($college, ['inventory_issues.create']);
        $item = $this->makeIssuableConsumable($college);
        $foreignStudent = $this->makeStudent($other);
        $faculty = $this->makeFaculty($college);

        // Another college's student is refused.
        $this->asCollege($college, $user)
            ->post(route('inventory-issues.store'), $this->issuePayload($item, $foreignStudent->id))
            ->assertSessionHasErrors('issued_to_id');

        $this->withTenant($college, fn () => $this->assertSame(0, InventoryIssue::query()->count()));

        // A staff member of the same college is accepted (the faculties table,
        // which also backs the HR Employee alias).
        $this->asCollege($college, $user)
            ->post(route('inventory-issues.store'), $this->issuePayload($item, $faculty->id, ['issued_to_type' => 'faculty']))
            ->assertSessionHasNoErrors();

        $this->withTenant($college, function () use ($faculty): void {
            $issue = InventoryIssue::query()->firstOrFail();
            $this->assertSame('faculty', $issue->issued_to_type);
            $this->assertSame($faculty->id, $issue->issued_to_id);
            $this->assertSame($faculty->full_name, $issue->recipientName());
        });
    }

    public function test_a_foreign_colleges_item_cannot_be_issued(): void
    {
        $college = $this->makeCollege('IPF7');
        $other = $this->makeCollege('IPF7X');
        $user = $this->makeUserWithPermissions($college, ['inventory_issues.create']);
        $foreignItem = $this->makeIssuableConsumable($other);
        $student = $this->makeStudent($college);

        $this->asCollege($college, $user)
            ->post(route('inventory-issues.store'), $this->issuePayload($foreignItem, $student->id))
            ->assertSessionHasErrors('item_id');

        $this->withTenant($college, fn () => $this->assertSame(0, InventoryIssue::query()->count()));
    }

    public function test_viewing_requires_the_own_view_permission(): void
    {
        $college = $this->makeCollege('IPF8');
        $item = $this->makeIssuableConsumable($college);
        $issue = $this->makeInventoryIssue($college, $item, ['number' => 'ISS-880001']);

        $bystander = $this->makeBystanderUser($college);
        $this->asCollege($college, $bystander)
            ->get(route('inventory-issues.index'))
            ->assertForbidden();

        $viewer = $this->makeUserWithPermissions($college, ['inventory_issues.view']);
        $this->asCollege($college, $viewer)
            ->get(route('inventory-issues.index'))
            ->assertOk()
            ->assertSee('ISS-880001');

        // create without view: the create screen is allowed, the list is not.
        $writer = $this->makeUserWithPermissions($college, ['inventory_issues.create']);
        $this->asCollege($college, $writer)->get(route('inventory-issues.index'))->assertForbidden();
        $this->asCollege($college, $writer)->get(route('inventory-issues.create'))->assertOk();
    }

    public function test_recording_requires_the_create_permission(): void
    {
        $college = $this->makeCollege('IPF9');
        $item = $this->makeIssuableConsumable($college);
        $student = $this->makeStudent($college);

        $viewer = $this->makeUserWithPermissions($college, ['inventory_issues.view']);
        $this->asCollege($college, $viewer)
            ->post(route('inventory-issues.store'), $this->issuePayload($item, $student->id))
            ->assertForbidden();

        $this->withTenant($college, fn () => $this->assertSame(0, InventoryIssue::query()->count()));
    }

    public function test_issues_are_tenant_isolated_in_the_listing(): void
    {
        $college = $this->makeCollege('IPF10');
        $other = $this->makeCollege('IPF10X');
        $viewer = $this->makeUserWithPermissions($college, ['inventory_issues.view']);
        $this->makeInventoryIssue($college, $this->makeIssuableConsumable($college), ['number' => 'ISS-100001']);
        $this->makeInventoryIssue($other, $this->makeIssuableConsumable($other), ['number' => 'ISS-100002']);

        $this->asCollege($college, $viewer)
            ->get(route('inventory-issues.index'))
            ->assertOk()
            ->assertSee('ISS-100001')
            ->assertDontSee('ISS-100002', false);
    }
}
