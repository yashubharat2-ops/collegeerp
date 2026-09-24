<?php

namespace Tests\Feature\Inventory;

use App\Models\AuditLog;
use App\Models\InventoryCategory;
use Tests\TestCase;

/**
 * Inventory / Asset Management — Item Categories.
 *
 * Covers CRUD, the per-college active-code uniqueness rule, tenant isolation,
 * RBAC, search/filter, deterministic pagination and audit logging.
 */
class InventoryCategoryTest extends TestCase
{
    use InventoryTestHelpers;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Stationery',
            'code' => 'STN',
            'status' => InventoryCategory::STATUS_ACTIVE,
            'description' => 'Paper, pens and files',
        ], $overrides);
    }

    public function test_a_category_can_be_created_and_listed(): void
    {
        $college = $this->makeCollege('ICAT1');
        $user = $this->makeUserWithPermissions($college, ['inventory_categories.view', 'inventory_categories.create']);

        $this->asCollege($college, $user)
            ->post(route('inventory-categories.store'), $this->payload())
            ->assertRedirect(route('inventory-categories.index'));

        $category = $this->withTenant($college, fn () => InventoryCategory::query()->where('code', 'STN')->firstOrFail());

        $this->assertSame($college->id, $category->college_id);
        $this->assertSame($user->id, $category->created_by);
        $this->assertSame($user->id, $category->updated_by);
        $this->assertSame(InventoryCategory::STATUS_ACTIVE, $category->status);

        $this->asCollege($college, $user)
            ->get(route('inventory-categories.index'))
            ->assertOk()
            ->assertSee('Stationery')
            ->assertSee('STN');
    }

    public function test_the_college_id_and_audit_columns_cannot_be_forged(): void
    {
        $college = $this->makeCollege('ICAT2');
        $other = $this->makeCollege('ICAT2X');
        $user = $this->makeUserWithPermissions($college, ['inventory_categories.view', 'inventory_categories.create']);

        $this->asCollege($college, $user)
            ->post(route('inventory-categories.store'), $this->payload([
                'college_id' => $other->id,
                'created_by' => 9999,
                'updated_by' => 9999,
            ]))
            ->assertRedirect();

        $category = $this->withTenant($college, fn () => InventoryCategory::query()->where('code', 'STN')->firstOrFail());

        $this->assertSame($college->id, $category->college_id, 'college_id must come from the tenant context, never the payload.');
        $this->assertSame($user->id, $category->created_by);
        $this->assertSame($user->id, $category->updated_by);
        $this->assertNull($this->withTenant($other, fn () => InventoryCategory::query()->where('code', 'STN')->first()));
    }

    public function test_codes_are_stored_upper_cased_and_compared_case_insensitively(): void
    {
        $college = $this->makeCollege('ICAT3');
        $user = $this->makeUserWithPermissions($college, ['inventory_categories.view', 'inventory_categories.create']);

        $this->asCollege($college, $user)
            ->post(route('inventory-categories.store'), $this->payload(['code' => ' fur ']))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $this->withTenant($college, fn () => InventoryCategory::query()->where('code', 'FUR')->count()));

        $this->asCollege($college, $user)
            ->post(route('inventory-categories.store'), $this->payload(['name' => 'Furniture again', 'code' => 'Fur']))
            ->assertSessionHasErrors('code');
    }

    public function test_a_duplicate_active_code_is_rejected_within_a_college(): void
    {
        $college = $this->makeCollege('ICAT4');
        $user = $this->makeUserWithPermissions($college, ['inventory_categories.create']);
        $this->makeInventoryCategory($college, ['code' => 'DUP']);

        $this->asCollege($college, $user)
            ->post(route('inventory-categories.store'), $this->payload(['code' => 'DUP']))
            ->assertSessionHasErrors('code');

        $this->assertSame(1, $this->withTenant($college, fn () => InventoryCategory::query()->where('code', 'DUP')->count()));
    }

    public function test_the_same_code_may_be_used_by_two_colleges(): void
    {
        $college = $this->makeCollege('ICAT5');
        $other = $this->makeCollege('ICAT5X');
        $user = $this->makeUserWithPermissions($college, ['inventory_categories.create']);
        $this->makeInventoryCategory($other, ['code' => 'SHARED']);

        $this->asCollege($college, $user)
            ->post(route('inventory-categories.store'), $this->payload(['code' => 'SHARED']))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $this->withTenant($college, fn () => InventoryCategory::query()->where('code', 'SHARED')->count()));
        $this->assertSame(1, $this->withTenant($other, fn () => InventoryCategory::query()->where('code', 'SHARED')->count()));
    }

    public function test_a_code_freed_by_a_soft_deleted_category_can_be_reused(): void
    {
        $college = $this->makeCollege('ICAT6');
        $user = $this->makeUserWithPermissions($college, ['inventory_categories.create', 'inventory_categories.delete']);
        $category = $this->makeInventoryCategory($college, ['code' => 'REUSE']);

        $this->asCollege($college, $user)->delete(route('inventory-categories.destroy', $category))->assertRedirect();

        $this->assertSoftDeleted('inventory_categories', ['id' => $category->id]);

        $this->asCollege($college, $user)
            ->post(route('inventory-categories.store'), $this->payload(['code' => 'REUSE']))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, $this->withTenant($college, fn () => InventoryCategory::withTrashed()->where('code', 'REUSE')->count()));
    }

    public function test_validation_rejects_incomplete_payloads(): void
    {
        $college = $this->makeCollege('ICAT7');
        $user = $this->makeUserWithPermissions($college, ['inventory_categories.create']);

        $this->asCollege($college, $user)
            ->post(route('inventory-categories.store'), [])
            ->assertSessionHasErrors(['name', 'code', 'status']);

        $this->asCollege($college, $user)
            ->post(route('inventory-categories.store'), $this->payload(['status' => 'archived']))
            ->assertSessionHasErrors('status');

        $this->asCollege($college, $user)
            ->post(route('inventory-categories.store'), $this->payload(['code' => str_repeat('X', 51)]))
            ->assertSessionHasErrors('code');
    }

    public function test_a_category_can_be_updated_and_deleted(): void
    {
        $college = $this->makeCollege('ICAT8');
        $user = $this->makeUserWithPermissions($college, ['inventory_categories.view', 'inventory_categories.update', 'inventory_categories.delete']);
        $category = $this->makeInventoryCategory($college, ['name' => 'Old Name', 'code' => 'EDIT']);

        $this->asCollege($college, $user)
            ->get(route('inventory-categories.edit', $category))
            ->assertOk()
            ->assertSee('Old Name');

        $this->asCollege($college, $user)
            ->put(route('inventory-categories.update', $category), $this->payload([
                'name' => 'New Name',
                'code' => 'EDIT',
                'status' => InventoryCategory::STATUS_INACTIVE,
            ]))
            ->assertRedirect(route('inventory-categories.index'));

        $category->refresh();
        $this->assertSame('New Name', $category->name);
        $this->assertSame(InventoryCategory::STATUS_INACTIVE, $category->status);
        $this->assertSame($user->id, $category->updated_by);
        $this->assertSame($college->id, $category->college_id);

        $this->asCollege($college, $user)
            ->delete(route('inventory-categories.destroy', $category))
            ->assertRedirect(route('inventory-categories.index'));

        $this->assertSoftDeleted('inventory_categories', ['id' => $category->id]);
    }

    public function test_updating_keeps_the_code_unique_among_other_active_categories(): void
    {
        $college = $this->makeCollege('ICAT9');
        $user = $this->makeUserWithPermissions($college, ['inventory_categories.update']);
        $this->makeInventoryCategory($college, ['code' => 'TAKEN']);
        $category = $this->makeInventoryCategory($college, ['code' => 'MINE']);

        $this->asCollege($college, $user)
            ->put(route('inventory-categories.update', $category), $this->payload(['code' => 'MINE']))
            ->assertSessionHasNoErrors();

        $this->asCollege($college, $user)
            ->put(route('inventory-categories.update', $category), $this->payload(['code' => 'TAKEN']))
            ->assertSessionHasErrors('code');
    }

    public function test_a_category_that_classifies_items_cannot_be_deleted(): void
    {
        $college = $this->makeCollege('ICAT10');
        $user = $this->makeUserWithPermissions($college, ['inventory_categories.delete']);
        $category = $this->makeInventoryCategory($college, ['code' => 'INUSE']);
        $this->makeInventoryItem($college, ['category_id' => $category->id]);

        $this->asCollege($college, $user)
            ->from(route('inventory-categories.index'))
            ->delete(route('inventory-categories.destroy', $category))
            ->assertRedirect(route('inventory-categories.index'))
            ->assertSessionHasErrors('category');

        $this->assertDatabaseHas('inventory_categories', ['id' => $category->id, 'deleted_at' => null]);
    }

    public function test_categories_are_isolated_per_college(): void
    {
        $college = $this->makeCollege('ICAT11');
        $other = $this->makeCollege('ICAT11X');
        $user = $this->makeUserWithPermissions($college, ['inventory_categories.view', 'inventory_categories.update', 'inventory_categories.delete']);
        $this->makeInventoryCategory($college, ['name' => 'Mine Only', 'code' => 'MINE']);
        $theirs = $this->makeInventoryCategory($other, ['name' => 'Theirs Only', 'code' => 'THEIRS']);

        $this->asCollege($college, $user)
            ->get(route('inventory-categories.index'))
            ->assertOk()
            ->assertSee('Mine Only')
            ->assertDontSee('Theirs Only');

        $this->asCollege($college, $user)->get(route('inventory-categories.edit', $theirs))->assertNotFound();
        $this->asCollege($college, $user)->put(route('inventory-categories.update', $theirs), $this->payload())->assertNotFound();
        $this->asCollege($college, $user)->delete(route('inventory-categories.destroy', $theirs))->assertNotFound();

        $this->assertDatabaseHas('inventory_categories', ['id' => $theirs->id, 'name' => 'Theirs Only', 'deleted_at' => null]);
    }

    public function test_every_action_is_permission_gated(): void
    {
        $college = $this->makeCollege('ICAT12');
        $viewer = $this->makeUserWithPermissions($college, ['inventory_categories.view']);
        $nobody = $this->makeUserWithPermissions($college, ['dashboard.view']);
        $category = $this->makeInventoryCategory($college);

        $this->get(route('inventory-categories.index'))->assertRedirect(route('login'));

        $this->asCollege($college, $nobody)->get(route('inventory-categories.index'))->assertForbidden();
        $this->asCollege($college, $viewer)->get(route('inventory-categories.index'))->assertOk()->assertDontSee('Add item category');
        $this->asCollege($college, $viewer)->get(route('inventory-categories.create'))->assertForbidden();
        $this->asCollege($college, $viewer)->post(route('inventory-categories.store'), $this->payload())->assertForbidden();
        $this->asCollege($college, $viewer)->get(route('inventory-categories.edit', $category))->assertForbidden();
        $this->asCollege($college, $viewer)->put(route('inventory-categories.update', $category), $this->payload())->assertForbidden();
        $this->asCollege($college, $viewer)->delete(route('inventory-categories.destroy', $category))->assertForbidden();

        $this->assertDatabaseHas('inventory_categories', ['id' => $category->id, 'deleted_at' => null]);
    }

    public function test_the_index_filters_and_orders_categories_deterministically(): void
    {
        $college = $this->makeCollege('ICAT13');
        $user = $this->makeUserWithPermissions($college, ['inventory_categories.view']);

        $first = $this->makeInventoryCategory($college, ['name' => 'Same Name', 'code' => 'AAA', 'status' => InventoryCategory::STATUS_ACTIVE]);
        $second = $this->makeInventoryCategory($college, ['name' => 'Same Name', 'code' => 'BBB', 'status' => InventoryCategory::STATUS_INACTIVE]);
        $this->makeInventoryCategory($college, ['name' => 'Zebra', 'code' => 'ZZZ', 'status' => InventoryCategory::STATUS_ACTIVE]);

        $this->asCollege($college, $user)
            ->get(route('inventory-categories.index'))
            ->assertOk()
            ->assertSeeInOrder(['AAA', 'BBB', 'ZZZ']);

        $this->assertLessThan($second->id, $first->id);

        $this->asCollege($college, $user)
            ->get(route('inventory-categories.index', ['search' => 'zebra', 'status' => InventoryCategory::STATUS_ACTIVE]))
            ->assertOk()
            ->assertSee('ZZZ')
            ->assertDontSee('AAA');

        for ($i = 1; $i <= 16; $i++) {
            $this->makeInventoryCategory($college, [
                'name' => sprintf('Paged %02d', $i),
                'code' => sprintf('PG%02d', $i),
            ]);
        }

        $this->asCollege($college, $user)
            ->get(route('inventory-categories.index', ['page' => 2, 'search' => 'Paged']))
            ->assertOk()
            ->assertSee('Paged 16')
            ->assertDontSee('Paged 01');
    }

    public function test_category_mutations_are_audited(): void
    {
        $college = $this->makeCollege('ICAT14');
        $user = $this->makeUserWithPermissions($college, ['inventory_categories.create', 'inventory_categories.update', 'inventory_categories.delete']);

        $this->asCollege($college, $user)->post(route('inventory-categories.store'), $this->payload(['code' => 'AUD']))->assertRedirect();

        $category = $this->withTenant($college, fn () => InventoryCategory::query()->where('code', 'AUD')->firstOrFail());

        $this->asCollege($college, $user)->put(route('inventory-categories.update', $category), $this->payload([
            'name' => 'Audited',
            'code' => 'AUD',
        ]))->assertRedirect();

        $this->asCollege($college, $user)->delete(route('inventory-categories.destroy', $category))->assertRedirect();

        $actions = AuditLog::query()
            ->where('subject_type', InventoryCategory::class)
            ->where('subject_id', $category->id)
            ->orderBy('id')
            ->pluck('action')
            ->all();

        $this->assertSame([
            'inventory_categories.created',
            'inventory_categories.updated',
            'inventory_categories.deleted',
        ], $actions);

        $created = AuditLog::query()->where('action', 'inventory_categories.created')->where('subject_id', $category->id)->firstOrFail();
        $this->assertSame($college->id, $created->college_id);
        $this->assertSame($user->id, $created->user_id);
        $this->assertSame('AUD', $created->new_values['code']);
    }
}
