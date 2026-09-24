<?php

namespace Tests\Feature\Inventory;

use App\Models\AuditLog;
use App\Models\InventoryVendor;
use Tests\TestCase;

/**
 * Inventory / Asset Management — Vendors.
 *
 * Covers CRUD, per-college code uniqueness, optional contact fields, tenant
 * isolation, RBAC and audit logging. Purchase orders are not part of this phase.
 */
class InventoryVendorTest extends TestCase
{
    use InventoryTestHelpers;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Campus Supplies',
            'code' => 'VEN-01',
            'contact_person' => 'Ravi Shah',
            'phone' => '9876543210',
            'email' => 'ravi@supplies.test',
            'address' => '12 Market Road',
            'gst_number' => '27aabcu9603r1zm',
            'status' => InventoryVendor::STATUS_ACTIVE,
        ], $overrides);
    }

    public function test_a_vendor_can_be_created_and_listed(): void
    {
        $college = $this->makeCollege('IVEN1');
        $user = $this->makeUserWithPermissions($college, ['inventory_vendors.view', 'inventory_vendors.create']);

        $this->asCollege($college, $user)
            ->post(route('inventory-vendors.store'), $this->payload())
            ->assertRedirect(route('inventory-vendors.index'));

        $vendor = $this->withTenant($college, fn () => InventoryVendor::query()->where('code', 'VEN-01')->firstOrFail());

        $this->assertSame($college->id, $vendor->college_id);
        $this->assertSame($user->id, $vendor->created_by);
        $this->assertSame($user->id, $vendor->updated_by);
        $this->assertSame('27AABCU9603R1ZM', $vendor->gst_number);
        $this->assertSame('Ravi Shah', $vendor->contact_person);

        $this->asCollege($college, $user)
            ->get(route('inventory-vendors.index'))
            ->assertOk()
            ->assertSee('Campus Supplies')
            ->assertSee('VEN-01')
            ->assertSee('27AABCU9603R1ZM');
    }

    public function test_optional_contact_fields_may_be_omitted(): void
    {
        $college = $this->makeCollege('IVEN2');
        $user = $this->makeUserWithPermissions($college, ['inventory_vendors.create']);

        $this->asCollege($college, $user)
            ->post(route('inventory-vendors.store'), $this->payload([
                'contact_person' => '',
                'phone' => '',
                'email' => '',
                'address' => '',
                'gst_number' => '  ',
            ]))
            ->assertSessionHasNoErrors();

        $vendor = $this->withTenant($college, fn () => InventoryVendor::query()->where('code', 'VEN-01')->firstOrFail());

        $this->assertNull($vendor->contact_person);
        $this->assertNull($vendor->phone);
        $this->assertNull($vendor->email);
        $this->assertNull($vendor->address);
        $this->assertNull($vendor->gst_number);
    }

    public function test_the_college_id_and_audit_columns_cannot_be_forged(): void
    {
        $college = $this->makeCollege('IVEN3');
        $other = $this->makeCollege('IVEN3X');
        $user = $this->makeUserWithPermissions($college, ['inventory_vendors.create']);

        $this->asCollege($college, $user)
            ->post(route('inventory-vendors.store'), $this->payload([
                'college_id' => $other->id,
                'created_by' => 9999,
                'updated_by' => 9999,
            ]))
            ->assertRedirect();

        $vendor = $this->withTenant($college, fn () => InventoryVendor::query()->where('code', 'VEN-01')->firstOrFail());

        $this->assertSame($college->id, $vendor->college_id);
        $this->assertSame($user->id, $vendor->created_by);
        $this->assertSame($user->id, $vendor->updated_by);
    }

    public function test_vendor_codes_are_unique_per_college_and_reusable_after_soft_delete(): void
    {
        $college = $this->makeCollege('IVEN4');
        $other = $this->makeCollege('IVEN4X');
        $user = $this->makeUserWithPermissions($college, ['inventory_vendors.create', 'inventory_vendors.delete']);
        $this->makeInventoryVendor($other, ['code' => 'SHARED']);

        $this->asCollege($college, $user)
            ->post(route('inventory-vendors.store'), $this->payload(['code' => ' shared ']))
            ->assertSessionHasNoErrors();

        $this->asCollege($college, $user)
            ->post(route('inventory-vendors.store'), $this->payload(['name' => 'Again', 'code' => 'SHARED']))
            ->assertSessionHasErrors('code');

        // GST is not a uniqueness key.
        $this->asCollege($college, $user)
            ->post(route('inventory-vendors.store'), $this->payload([
                'name' => 'Second GST',
                'code' => 'GST-2',
                'gst_number' => '27AABCU9603R1ZM',
            ]))
            ->assertSessionHasNoErrors();

        $vendor = $this->withTenant($college, fn () => InventoryVendor::query()->where('code', 'SHARED')->firstOrFail());
        $this->asCollege($college, $user)->delete(route('inventory-vendors.destroy', $vendor))->assertRedirect();
        $this->assertSoftDeleted('inventory_vendors', ['id' => $vendor->id]);

        $this->asCollege($college, $user)
            ->post(route('inventory-vendors.store'), $this->payload(['code' => 'SHARED']))
            ->assertSessionHasNoErrors();
    }

    public function test_a_vendor_can_be_updated(): void
    {
        $college = $this->makeCollege('IVEN5');
        $user = $this->makeUserWithPermissions($college, ['inventory_vendors.view', 'inventory_vendors.update']);
        $vendor = $this->makeInventoryVendor($college, ['name' => 'Old Vendor', 'code' => 'EDIT']);

        $this->asCollege($college, $user)
            ->get(route('inventory-vendors.edit', $vendor))
            ->assertOk()
            ->assertSee('Old Vendor');

        $this->asCollege($college, $user)
            ->put(route('inventory-vendors.update', $vendor), $this->payload([
                'name' => 'New Vendor',
                'code' => 'EDIT',
                'status' => InventoryVendor::STATUS_INACTIVE,
                'email' => 'new@vendor.test',
            ]))
            ->assertRedirect(route('inventory-vendors.index'));

        $vendor->refresh();
        $this->assertSame('New Vendor', $vendor->name);
        $this->assertSame(InventoryVendor::STATUS_INACTIVE, $vendor->status);
        $this->assertSame('new@vendor.test', $vendor->email);
        $this->assertSame($user->id, $vendor->updated_by);
        $this->assertSame($college->id, $vendor->college_id);
    }

    public function test_validation_rejects_incomplete_or_invalid_payloads(): void
    {
        $college = $this->makeCollege('IVEN6');
        $user = $this->makeUserWithPermissions($college, ['inventory_vendors.create']);

        $this->asCollege($college, $user)
            ->post(route('inventory-vendors.store'), [])
            ->assertSessionHasErrors(['name', 'code', 'status']);

        $this->asCollege($college, $user)
            ->post(route('inventory-vendors.store'), $this->payload(['email' => 'not-an-email', 'status' => 'blocked']))
            ->assertSessionHasErrors(['email', 'status']);
    }

    public function test_vendors_are_isolated_per_college(): void
    {
        $college = $this->makeCollege('IVEN7');
        $other = $this->makeCollege('IVEN7X');
        $user = $this->makeUserWithPermissions($college, ['inventory_vendors.view', 'inventory_vendors.update', 'inventory_vendors.delete']);
        $this->makeInventoryVendor($college, ['name' => 'Mine Only', 'code' => 'MINE']);
        $theirs = $this->makeInventoryVendor($other, ['name' => 'Theirs Only', 'code' => 'THEIRS']);

        $this->asCollege($college, $user)
            ->get(route('inventory-vendors.index'))
            ->assertOk()
            ->assertSee('Mine Only')
            ->assertDontSee('Theirs Only');

        $this->asCollege($college, $user)->get(route('inventory-vendors.edit', $theirs))->assertNotFound();
        $this->asCollege($college, $user)->put(route('inventory-vendors.update', $theirs), $this->payload())->assertNotFound();
        $this->asCollege($college, $user)->delete(route('inventory-vendors.destroy', $theirs))->assertNotFound();

        $this->assertDatabaseHas('inventory_vendors', ['id' => $theirs->id, 'name' => 'Theirs Only', 'deleted_at' => null]);
    }

    public function test_every_action_is_permission_gated(): void
    {
        $college = $this->makeCollege('IVEN8');
        $viewer = $this->makeUserWithPermissions($college, ['inventory_vendors.view']);
        $nobody = $this->makeUserWithPermissions($college, ['dashboard.view']);
        $vendor = $this->makeInventoryVendor($college);

        $this->get(route('inventory-vendors.index'))->assertRedirect(route('login'));
        $this->asCollege($college, $nobody)->get(route('inventory-vendors.index'))->assertForbidden();
        $this->asCollege($college, $viewer)->get(route('inventory-vendors.index'))->assertOk()->assertDontSee('Add vendor');
        $this->asCollege($college, $viewer)->get(route('inventory-vendors.create'))->assertForbidden();
        $this->asCollege($college, $viewer)->post(route('inventory-vendors.store'), $this->payload())->assertForbidden();
        $this->asCollege($college, $viewer)->put(route('inventory-vendors.update', $vendor), $this->payload())->assertForbidden();
        $this->asCollege($college, $viewer)->delete(route('inventory-vendors.destroy', $vendor))->assertForbidden();
    }

    public function test_the_index_filters_and_orders_vendors_deterministically(): void
    {
        $college = $this->makeCollege('IVEN9');
        $user = $this->makeUserWithPermissions($college, ['inventory_vendors.view']);

        $this->makeInventoryVendor($college, [
            'name' => 'Same Name',
            'code' => 'AAA',
            'email' => 'alpha@vendor.test',
            'status' => InventoryVendor::STATUS_ACTIVE,
        ]);
        $this->makeInventoryVendor($college, [
            'name' => 'Same Name',
            'code' => 'BBB',
            'gst_number' => 'GSTBBB',
            'status' => InventoryVendor::STATUS_INACTIVE,
        ]);

        $this->asCollege($college, $user)
            ->get(route('inventory-vendors.index'))
            ->assertOk()
            ->assertSeeInOrder(['AAA', 'BBB']);

        $this->asCollege($college, $user)
            ->get(route('inventory-vendors.index', ['search' => 'gstbbb', 'status' => InventoryVendor::STATUS_INACTIVE]))
            ->assertOk()
            ->assertSee('BBB')
            ->assertDontSee('AAA');
    }

    public function test_vendor_mutations_are_audited(): void
    {
        $college = $this->makeCollege('IVEN10');
        $user = $this->makeUserWithPermissions($college, ['inventory_vendors.create', 'inventory_vendors.update', 'inventory_vendors.delete']);

        $this->asCollege($college, $user)->post(route('inventory-vendors.store'), $this->payload(['code' => 'AUD']))->assertRedirect();

        $vendor = $this->withTenant($college, fn () => InventoryVendor::query()->where('code', 'AUD')->firstOrFail());

        $this->asCollege($college, $user)->put(route('inventory-vendors.update', $vendor), $this->payload([
            'name' => 'Audited Vendor',
            'code' => 'AUD',
        ]))->assertRedirect();

        $this->asCollege($college, $user)->delete(route('inventory-vendors.destroy', $vendor))->assertRedirect();

        $actions = AuditLog::query()
            ->where('subject_type', InventoryVendor::class)
            ->where('subject_id', $vendor->id)
            ->orderBy('id')
            ->pluck('action')
            ->all();

        $this->assertSame(['inventory_vendors.created', 'inventory_vendors.updated', 'inventory_vendors.deleted'], $actions);

        $created = AuditLog::query()->where('action', 'inventory_vendors.created')->where('subject_id', $vendor->id)->firstOrFail();
        $this->assertSame($college->id, $created->college_id);
        $this->assertSame($user->id, $created->user_id);
        $this->assertSame('AUD', $created->new_values['code']);
    }
}
