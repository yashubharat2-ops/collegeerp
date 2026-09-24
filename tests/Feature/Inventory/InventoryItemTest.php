<?php

namespace Tests\Feature\Inventory;

use App\Models\AuditLog;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Inventory / Asset Management — Items / Assets.
 *
 * Consumables and assets are one master. Tests cover CRUD, per-college code
 * and serial uniqueness, tenant-safe category validation, isolation and RBAC.
 */
class InventoryItemTest extends TestCase
{
    use InventoryTestHelpers;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(int $categoryId, array $overrides = []): array
    {
        return array_merge([
            'name' => 'A4 Ream',
            'code' => 'A4-001',
            'category_id' => $categoryId,
            'item_type' => InventoryItem::TYPE_CONSUMABLE,
            'brand' => 'Classmate',
            'model' => null,
            'serial_number' => null,
            'unit' => 'ream',
            'quantity' => '12',
            'description' => 'Office paper',
            'status' => InventoryItem::STATUS_ACTIVE,
        ], $overrides);
    }

    public function test_consumables_and_assets_share_one_master(): void
    {
        $this->assertFalse(Schema::hasTable('assets'));
        $this->assertFalse(class_exists(\App\Models\Asset::class));
        $this->assertTrue(Schema::hasTable('inventory_items'));

        $college = $this->makeCollege('IITM1');
        $user = $this->makeUserWithPermissions($college, ['inventory_items.view', 'inventory_items.create']);
        $category = $this->makeInventoryCategory($college);

        $this->asCollege($college, $user)
            ->post(route('inventory-items.store'), $this->payload($category->id, [
                'name' => 'Printer Paper',
                'code' => 'PAPER',
                'item_type' => InventoryItem::TYPE_CONSUMABLE,
            ]))
            ->assertRedirect(route('inventory-items.index'));

        $this->asCollege($college, $user)
            ->post(route('inventory-items.store'), $this->payload($category->id, [
                'name' => 'Lab Projector',
                'code' => 'PROJ',
                'item_type' => InventoryItem::TYPE_ASSET,
                'serial_number' => 'SN-100',
                'unit' => 'nos',
                'quantity' => '1',
            ]))
            ->assertRedirect(route('inventory-items.index'));

        $rows = $this->withTenant($college, fn () => InventoryItem::query()->orderBy('code')->get());

        $this->assertCount(2, $rows);
        $this->assertSame([InventoryItem::TYPE_CONSUMABLE, InventoryItem::TYPE_ASSET], $rows->pluck('item_type')->all());
        $this->assertSame('SN-100', $rows->firstWhere('code', 'PROJ')->serial_number);

        $this->asCollege($college, $user)
            ->get(route('inventory-items.index'))
            ->assertOk()
            ->assertSee('Printer Paper')
            ->assertSee('Lab Projector')
            ->assertSee('Consumable')
            ->assertSee('Asset');
    }

    public function test_the_college_id_and_audit_columns_cannot_be_forged(): void
    {
        $college = $this->makeCollege('IITM2');
        $other = $this->makeCollege('IITM2X');
        $user = $this->makeUserWithPermissions($college, ['inventory_items.create']);
        $category = $this->makeInventoryCategory($college);

        $this->asCollege($college, $user)
            ->post(route('inventory-items.store'), $this->payload($category->id, [
                'college_id' => $other->id,
                'created_by' => 9999,
                'updated_by' => 9999,
            ]))
            ->assertRedirect();

        $item = $this->withTenant($college, fn () => InventoryItem::query()->where('code', 'A4-001')->firstOrFail());

        $this->assertSame($college->id, $item->college_id);
        $this->assertSame($user->id, $item->created_by);
        $this->assertSame($user->id, $item->updated_by);
        $this->assertSame('12.00', $item->quantity);
    }

    public function test_codes_are_unique_per_college_and_reusable_after_soft_delete(): void
    {
        $college = $this->makeCollege('IITM3');
        $other = $this->makeCollege('IITM3X');
        $user = $this->makeUserWithPermissions($college, ['inventory_items.create', 'inventory_items.delete']);
        $category = $this->makeInventoryCategory($college);
        $this->makeInventoryItem($other, ['code' => 'SHARED']);

        $this->asCollege($college, $user)
            ->post(route('inventory-items.store'), $this->payload($category->id, ['code' => ' shared ']))
            ->assertSessionHasNoErrors();

        $this->asCollege($college, $user)
            ->post(route('inventory-items.store'), $this->payload($category->id, ['name' => 'Again', 'code' => 'SHARED']))
            ->assertSessionHasErrors('code');

        $item = $this->withTenant($college, fn () => InventoryItem::query()->where('code', 'SHARED')->firstOrFail());
        $this->asCollege($college, $user)->delete(route('inventory-items.destroy', $item))->assertRedirect();
        $this->assertSoftDeleted('inventory_items', ['id' => $item->id]);

        $this->asCollege($college, $user)
            ->post(route('inventory-items.store'), $this->payload($category->id, ['code' => 'SHARED']))
            ->assertSessionHasNoErrors();
    }

    public function test_a_serial_number_is_unique_per_college_only_when_provided(): void
    {
        $college = $this->makeCollege('IITM4');
        $other = $this->makeCollege('IITM4X');
        $user = $this->makeUserWithPermissions($college, ['inventory_items.create', 'inventory_items.update', 'inventory_items.delete']);
        $category = $this->makeInventoryCategory($college);
        $this->makeInventoryItem($other, ['serial_number' => 'SN-OTHER']);

        $this->asCollege($college, $user)
            ->post(route('inventory-items.store'), $this->payload($category->id, [
                'code' => 'ONE',
                'serial_number' => ' sn-1 ',
            ]))
            ->assertSessionHasNoErrors();

        $this->asCollege($college, $user)
            ->post(route('inventory-items.store'), $this->payload($category->id, [
                'name' => 'No serial A',
                'code' => 'NOS-A',
                'serial_number' => '',
            ]))
            ->assertSessionHasNoErrors();

        $this->asCollege($college, $user)
            ->post(route('inventory-items.store'), $this->payload($category->id, [
                'name' => 'No serial B',
                'code' => 'NOS-B',
            ]))
            ->assertSessionHasNoErrors();

        // Another college already uses SN-OTHER; this college may reuse it.
        $this->asCollege($college, $user)
            ->post(route('inventory-items.store'), $this->payload($category->id, [
                'name' => 'Shared serial',
                'code' => 'SHR',
                'serial_number' => 'SN-OTHER',
            ]))
            ->assertSessionHasNoErrors();

        $this->asCollege($college, $user)
            ->post(route('inventory-items.store'), $this->payload($category->id, [
                'name' => 'Duplicate serial',
                'code' => 'DUP',
                'serial_number' => 'sn-1',
            ]))
            ->assertSessionHasErrors('serial_number');

        $item = $this->withTenant($college, fn () => InventoryItem::query()->where('code', 'ONE')->firstOrFail());
        $this->assertSame('SN-1', $item->serial_number);

        $this->asCollege($college, $user)->delete(route('inventory-items.destroy', $item))->assertRedirect();

        $this->asCollege($college, $user)
            ->post(route('inventory-items.store'), $this->payload($category->id, [
                'name' => 'Reused serial',
                'code' => 'REUSE',
                'serial_number' => 'SN-1',
            ]))
            ->assertSessionHasNoErrors();

        $nulls = $this->withTenant($college, fn () => InventoryItem::query()->whereNull('serial_number')->count());
        $this->assertSame(2, $nulls);
    }

    public function test_the_category_must_belong_to_the_active_college(): void
    {
        $college = $this->makeCollege('IITM5');
        $other = $this->makeCollege('IITM5X');
        $user = $this->makeUserWithPermissions($college, ['inventory_items.create', 'inventory_items.update']);
        $mine = $this->makeInventoryCategory($college, ['name' => 'Mine', 'status' => InventoryCategory::STATUS_INACTIVE]);
        $theirs = $this->makeInventoryCategory($other, ['name' => 'Theirs']);
        $archived = $this->makeInventoryCategory($college, ['code' => 'GONE']);
        $archived->delete();

        // Inactive is still this college's category, so it is a valid classification.
        $this->asCollege($college, $user)
            ->post(route('inventory-items.store'), $this->payload($mine->id, ['code' => 'OK']))
            ->assertSessionHasNoErrors();

        $this->asCollege($college, $user)
            ->post(route('inventory-items.store'), $this->payload($theirs->id, ['code' => 'BAD']))
            ->assertSessionHasErrors('category_id');

        $this->asCollege($college, $user)
            ->post(route('inventory-items.store'), $this->payload($archived->id, ['code' => 'ARCH']))
            ->assertSessionHasErrors('category_id');

        $item = $this->withTenant($college, fn () => InventoryItem::query()->where('code', 'OK')->firstOrFail());

        $this->asCollege($college, $user)
            ->put(route('inventory-items.update', $item), $this->payload($theirs->id, ['code' => 'OK']))
            ->assertSessionHasErrors('category_id');

        $this->assertSame($mine->id, $item->fresh()->category_id);
        $this->assertSame(0, $this->withTenant($other, fn () => InventoryItem::query()->count()));
    }

    public function test_an_item_can_be_updated_and_deleted(): void
    {
        $college = $this->makeCollege('IITM6');
        $user = $this->makeUserWithPermissions($college, ['inventory_items.view', 'inventory_items.update', 'inventory_items.delete']);
        $category = $this->makeInventoryCategory($college);
        $otherCategory = $this->makeInventoryCategory($college, ['name' => 'Furniture']);
        $item = $this->makeInventoryItem($college, ['category_id' => $category->id, 'name' => 'Old Chair', 'code' => 'CHAIR']);

        $this->asCollege($college, $user)
            ->get(route('inventory-items.edit', $item))
            ->assertOk()
            ->assertSee('Old Chair');

        $this->asCollege($college, $user)
            ->put(route('inventory-items.update', $item), $this->payload($otherCategory->id, [
                'name' => 'New Chair',
                'code' => 'CHAIR',
                'item_type' => InventoryItem::TYPE_ASSET,
                'status' => InventoryItem::STATUS_INACTIVE,
                'quantity' => '2.5',
                'unit' => 'nos',
            ]))
            ->assertRedirect(route('inventory-items.index'));

        $item->refresh();
        $this->assertSame('New Chair', $item->name);
        $this->assertSame(InventoryItem::TYPE_ASSET, $item->item_type);
        $this->assertSame(InventoryItem::STATUS_INACTIVE, $item->status);
        $this->assertSame($otherCategory->id, $item->category_id);
        $this->assertSame('2.50', $item->quantity);
        $this->assertSame($user->id, $item->updated_by);
        $this->assertSame($college->id, $item->college_id);

        $this->asCollege($college, $user)->delete(route('inventory-items.destroy', $item))->assertRedirect();
        $this->assertSoftDeleted('inventory_items', ['id' => $item->id]);
    }

    public function test_validation_rejects_incomplete_or_invalid_payloads(): void
    {
        $college = $this->makeCollege('IITM7');
        $user = $this->makeUserWithPermissions($college, ['inventory_items.create']);
        $category = $this->makeInventoryCategory($college);

        $this->asCollege($college, $user)
            ->post(route('inventory-items.store'), [])
            ->assertSessionHasErrors(['name', 'code', 'category_id', 'item_type', 'unit', 'quantity', 'status']);

        $this->asCollege($college, $user)
            ->post(route('inventory-items.store'), $this->payload($category->id, ['item_type' => 'vehicle', 'quantity' => '-1', 'status' => 'lost']))
            ->assertSessionHasErrors(['item_type', 'quantity', 'status']);
    }

    public function test_items_are_isolated_per_college(): void
    {
        $college = $this->makeCollege('IITM8');
        $other = $this->makeCollege('IITM8X');
        $user = $this->makeUserWithPermissions($college, ['inventory_items.view', 'inventory_items.update', 'inventory_items.delete']);
        $mine = $this->makeInventoryItem($college, ['name' => 'Mine Only', 'code' => 'MINE']);
        $theirs = $this->makeInventoryItem($other, ['name' => 'Theirs Only', 'code' => 'THEIRS']);

        $this->asCollege($college, $user)
            ->get(route('inventory-items.index'))
            ->assertOk()
            ->assertSee('Mine Only')
            ->assertDontSee('Theirs Only');

        $this->asCollege($college, $user)->get(route('inventory-items.edit', $theirs))->assertNotFound();
        $this->asCollege($college, $user)->put(route('inventory-items.update', $theirs), $this->payload($mine->category_id))->assertNotFound();
        $this->asCollege($college, $user)->delete(route('inventory-items.destroy', $theirs))->assertNotFound();

        $this->assertDatabaseHas('inventory_items', ['id' => $theirs->id, 'name' => 'Theirs Only', 'deleted_at' => null]);
    }

    public function test_every_action_is_permission_gated(): void
    {
        $college = $this->makeCollege('IITM9');
        $viewer = $this->makeUserWithPermissions($college, ['inventory_items.view']);
        $nobody = $this->makeUserWithPermissions($college, ['dashboard.view']);
        $item = $this->makeInventoryItem($college);

        $this->get(route('inventory-items.index'))->assertRedirect(route('login'));
        $this->asCollege($college, $nobody)->get(route('inventory-items.index'))->assertForbidden();
        $this->asCollege($college, $viewer)->get(route('inventory-items.index'))->assertOk()->assertDontSee('Add item / asset');
        $this->asCollege($college, $viewer)->get(route('inventory-items.create'))->assertForbidden();
        $this->asCollege($college, $viewer)->post(route('inventory-items.store'), $this->payload($item->category_id))->assertForbidden();
        $this->asCollege($college, $viewer)->put(route('inventory-items.update', $item), $this->payload($item->category_id))->assertForbidden();
        $this->asCollege($college, $viewer)->delete(route('inventory-items.destroy', $item))->assertForbidden();
    }

    public function test_the_index_filters_and_orders_items_deterministically(): void
    {
        $college = $this->makeCollege('IITM10');
        $user = $this->makeUserWithPermissions($college, ['inventory_items.view']);
        $stationery = $this->makeInventoryCategory($college, ['name' => 'Stationery']);
        $lab = $this->makeInventoryCategory($college, ['name' => 'Lab']);

        $this->makeInventoryItem($college, [
            'category_id' => $stationery->id,
            'name' => 'Same Name',
            'code' => 'AAA',
            'item_type' => InventoryItem::TYPE_CONSUMABLE,
            'brand' => 'Classmate',
            'status' => InventoryItem::STATUS_ACTIVE,
        ]);
        $this->makeInventoryItem($college, [
            'category_id' => $lab->id,
            'name' => 'Same Name',
            'code' => 'BBB',
            'item_type' => InventoryItem::TYPE_ASSET,
            'serial_number' => 'SN-LAB',
            'status' => InventoryItem::STATUS_INACTIVE,
        ]);

        $this->asCollege($college, $user)
            ->get(route('inventory-items.index'))
            ->assertOk()
            ->assertSeeInOrder(['AAA', 'BBB']);

        $this->asCollege($college, $user)
            ->get(route('inventory-items.index', [
                'search' => 'sn-lab',
                'item_type' => InventoryItem::TYPE_ASSET,
                'status' => InventoryItem::STATUS_INACTIVE,
                'category_id' => $lab->id,
            ]))
            ->assertOk()
            ->assertSee('BBB')
            ->assertDontSee('AAA');
    }

    public function test_item_mutations_are_audited(): void
    {
        $college = $this->makeCollege('IITM11');
        $user = $this->makeUserWithPermissions($college, ['inventory_items.create', 'inventory_items.update', 'inventory_items.delete']);
        $category = $this->makeInventoryCategory($college);

        $this->asCollege($college, $user)
            ->post(route('inventory-items.store'), $this->payload($category->id, ['code' => 'AUD']))
            ->assertRedirect();

        $item = $this->withTenant($college, fn () => InventoryItem::query()->where('code', 'AUD')->firstOrFail());

        $this->asCollege($college, $user)
            ->put(route('inventory-items.update', $item), $this->payload($category->id, ['name' => 'Audited', 'code' => 'AUD']))
            ->assertRedirect();

        $this->asCollege($college, $user)->delete(route('inventory-items.destroy', $item))->assertRedirect();

        $actions = AuditLog::query()
            ->where('subject_type', InventoryItem::class)
            ->where('subject_id', $item->id)
            ->orderBy('id')
            ->pluck('action')
            ->all();

        $this->assertSame(['inventory_items.created', 'inventory_items.updated', 'inventory_items.deleted'], $actions);

        $created = AuditLog::query()->where('action', 'inventory_items.created')->where('subject_id', $item->id)->firstOrFail();
        $this->assertSame($college->id, $created->college_id);
        $this->assertSame($user->id, $created->user_id);
        $this->assertSame($category->id, $created->new_values['category_id']);
    }
}
