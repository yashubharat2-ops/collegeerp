<?php

namespace Tests\Feature\Finance;

use App\Models\AuditLog;
use App\Models\FeeCategory;
use App\Models\FeeStructureItem;
use Tests\TestCase;

/**
 * Finance / Fees — Fee Categories (master data, validation, tenancy, RBAC).
 *
 * Fee categories classify fee heads and hold no amounts at all, so the tests
 * focus on the invariants that matter: unique active code per college, strict
 * college scoping, permission gating, audit logging and the optional (and
 * backwards compatible) link from a fee structure component.
 */
class FeeCategoryManagementTest extends TestCase
{
    use FeeStructureTestHelpers;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Tuition',
            'code' => 'FC-TUITION',
            'status' => FeeCategory::STATUS_ACTIVE,
            'description' => 'Core tuition heads',
        ], $overrides);
    }

    public function test_a_category_can_be_created_and_listed(): void
    {
        $college = $this->makeCollege('FCAT1');
        $user = $this->makeUserWithPermissions($college, ['fee_categories.view', 'fee_categories.create']);

        $this->asCollege($college, $user)
            ->post(route('fee-categories.store'), $this->payload())
            ->assertRedirect(route('fee-categories.index'));

        $category = $this->withTenant($college, fn () => FeeCategory::query()->where('code', 'FC-TUITION')->firstOrFail());

        $this->assertSame($college->id, $category->college_id);
        $this->assertSame($user->id, $category->created_by);
        $this->assertSame(FeeCategory::STATUS_ACTIVE, $category->status);

        $this->asCollege($college, $user)
            ->get(route('fee-categories.index'))
            ->assertOk()
            ->assertSee('Tuition')
            ->assertSee('FC-TUITION');
    }

    public function test_the_college_id_and_audit_columns_cannot_be_forged(): void
    {
        $college = $this->makeCollege('FCAT2');
        $other = $this->makeCollege('FCAT2X');
        $user = $this->makeUserWithPermissions($college, ['fee_categories.view', 'fee_categories.create']);

        $this->asCollege($college, $user)
            ->post(route('fee-categories.store'), $this->payload([
                'college_id' => $other->id,
                'created_by' => 9999,
                'updated_by' => 9999,
            ]))
            ->assertRedirect();

        $category = $this->withTenant($college, fn () => FeeCategory::query()->where('code', 'FC-TUITION')->firstOrFail());

        $this->assertSame($college->id, $category->college_id, 'college_id must come from the tenant context, never the payload.');
        $this->assertSame($user->id, $category->created_by);
        $this->assertSame($user->id, $category->updated_by);
    }

    public function test_a_duplicate_active_code_is_rejected_within_a_college(): void
    {
        $college = $this->makeCollege('FCAT3');
        $user = $this->makeUserWithPermissions($college, ['fee_categories.view', 'fee_categories.create']);
        $this->makeFeeCategory($college, ['code' => 'FC-DUP']);

        $this->asCollege($college, $user)
            ->post(route('fee-categories.store'), $this->payload(['code' => 'FC-DUP']))
            ->assertSessionHasErrors('code');

        $this->assertSame(1, $this->withTenant($college, fn () => FeeCategory::query()->where('code', 'FC-DUP')->count()));
    }

    public function test_the_same_code_may_be_used_by_two_colleges(): void
    {
        $college = $this->makeCollege('FCAT4');
        $other = $this->makeCollege('FCAT4X');
        $user = $this->makeUserWithPermissions($college, ['fee_categories.view', 'fee_categories.create']);
        $this->makeFeeCategory($other, ['code' => 'FC-SHARED']);
        $this->makeFeeCategory($college, ['code' => 'FC-SHARED']);

        // The second college's row is its own; a third insert in the first
        // college is still a duplicate.
        $this->asCollege($college, $user)
            ->post(route('fee-categories.store'), $this->payload(['code' => 'FC-SHARED']))
            ->assertSessionHasErrors('code');
    }

    public function test_a_code_freed_by_a_soft_deleted_category_can_be_reused(): void
    {
        $college = $this->makeCollege('FCAT5');
        $user = $this->makeUserWithPermissions($college, ['fee_categories.view', 'fee_categories.create', 'fee_categories.delete']);
        $category = $this->makeFeeCategory($college, ['code' => 'FC-REUSE']);

        $this->asCollege($college, $user)->delete(route('fee-categories.destroy', $category))->assertRedirect();

        $this->assertSoftDeleted('fee_categories', ['id' => $category->id]);

        $this->asCollege($college, $user)
            ->post(route('fee-categories.store'), $this->payload(['code' => 'FC-REUSE']))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, $this->withTenant($college, fn () => FeeCategory::withTrashed()->where('code', 'FC-REUSE')->count()));
    }

    public function test_validation_rejects_incomplete_payloads(): void
    {
        $college = $this->makeCollege('FCAT6');
        $user = $this->makeUserWithPermissions($college, ['fee_categories.view', 'fee_categories.create']);

        $this->asCollege($college, $user)
            ->post(route('fee-categories.store'), [])
            ->assertSessionHasErrors(['name', 'code', 'status']);

        $this->asCollege($college, $user)
            ->post(route('fee-categories.store'), $this->payload(['status' => 'archived']))
            ->assertSessionHasErrors('status');
    }

    public function test_a_category_can_be_updated_and_deleted(): void
    {
        $college = $this->makeCollege('FCAT7');
        $user = $this->makeUserWithPermissions($college, ['fee_categories.view', 'fee_categories.update', 'fee_categories.delete']);
        $category = $this->makeFeeCategory($college, ['name' => 'Old Name', 'code' => 'FC-EDIT']);

        $this->asCollege($college, $user)
            ->put(route('fee-categories.update', $category), $this->payload(['name' => 'New Name', 'code' => 'FC-EDIT']))
            ->assertRedirect(route('fee-categories.index'));

        $this->assertSame('New Name', $category->fresh()->name);

        $this->asCollege($college, $user)->delete(route('fee-categories.destroy', $category))->assertRedirect(route('fee-categories.index'));

        $this->assertSoftDeleted('fee_categories', ['id' => $category->id]);
        $this->assertSame(0, $this->withTenant($college, fn () => FeeCategory::query()->count()));
    }

    public function test_categories_of_another_college_can_never_be_reached(): void
    {
        $college = $this->makeCollege('FCAT8');
        $other = $this->makeCollege('FCAT8X');
        $user = $this->makeUserWithPermissions($college, ['fee_categories.view', 'fee_categories.update', 'fee_categories.delete']);
        $foreign = $this->makeFeeCategory($other, ['name' => 'Foreign Category', 'code' => 'FC-FOR']);

        $this->asCollege($college, $user)->get(route('fee-categories.index'))->assertOk()->assertDontSee('Foreign Category');

        $this->asCollege($college, $user)->get(route('fee-categories.edit', $foreign))->assertNotFound();
        $this->asCollege($college, $user)->put(route('fee-categories.update', $foreign), $this->payload())->assertNotFound();
        $this->asCollege($college, $user)->delete(route('fee-categories.destroy', $foreign))->assertNotFound();

        $this->assertNull($foreign->fresh()->deleted_at);
    }

    public function test_the_module_is_permission_gated(): void
    {
        $college = $this->makeCollege('FCAT9');
        $stranger = $this->makeUserWithPermissions($college, ['students.view']);
        $category = $this->makeFeeCategory($college);

        $this->asCollege($college, $stranger)->get(route('fee-categories.index'))->assertForbidden();
        $this->asCollege($college, $stranger)->get(route('fee-categories.create'))->assertForbidden();
        $this->asCollege($college, $stranger)->post(route('fee-categories.store'), $this->payload())->assertForbidden();
        $this->asCollege($college, $stranger)->put(route('fee-categories.update', $category), $this->payload())->assertForbidden();
        $this->asCollege($college, $stranger)->delete(route('fee-categories.destroy', $category))->assertForbidden();

        // A view-only user may look but not change anything.
        $viewer = $this->makeUserWithPermissions($college, ['fee_categories.view']);
        $this->asCollege($college, $viewer)->get(route('fee-categories.index'))->assertOk();
        $this->asCollege($college, $viewer)->post(route('fee-categories.store'), $this->payload())->assertForbidden();
    }

    public function test_deleting_a_category_does_not_break_linked_fee_components(): void
    {
        $college = $this->makeCollege('FCAT10');
        $user = $this->makeUserWithPermissions($college, ['fee_categories.view', 'fee_categories.delete']);
        $ctx = $this->makeFinanceContext($college, 'FCAT10');
        $category = $this->makeFeeCategory($college, ['code' => 'FC-LINK']);
        $structure = $this->makeFeeStructure($college, $ctx, [], [
            ['name' => 'Tuition Fee', 'amount' => 25000, 'fee_category_id' => $category->id],
        ]);

        $item = $this->withTenant($college, fn () => FeeStructureItem::query()->firstOrFail());
        $this->assertSame($category->id, $item->fee_category_id);

        $this->asCollege($college, $user)->delete(route('fee-categories.destroy', $category))->assertRedirect();

        // The fee head survives the classication being removed.
        $item->refresh();
        $this->assertNotNull($item->fresh(), 'The fee component must survive its category being deleted.');
        $this->assertSame($structure->id, $item->fee_structure_id);
    }

    public function test_a_fee_component_can_only_reference_a_category_of_its_own_college(): void
    {
        $college = $this->makeCollege('FCAT11');
        $other = $this->makeCollege('FCAT11X');
        $user = $this->makeUserWithPermissions($college, ['fee_categories.view', 'fee_structures.view', 'fee_structures.create']);
        $ctx = $this->makeFinanceContext($college, 'FCAT11');
        $foreignCategory = $this->makeFeeCategory($other, ['code' => 'FC-FOR']);

        $payload = $this->feeStructurePayload($ctx, [
            'items' => [
                ['name' => 'Tuition Fee', 'amount' => 1000, 'sort_order' => 1, 'status' => 'active', 'fee_category_id' => $foreignCategory->id],
            ],
        ]);

        $this->asCollege($college, $user)
            ->post(route('fee-structures.store'), $payload)
            ->assertSessionHasErrors('items.0.fee_category_id');
    }

    public function test_custom_events_are_audited(): void
    {
        $college = $this->makeCollege('FCAT12');
        $user = $this->makeUserWithPermissions($college, ['fee_categories.view', 'fee_categories.create', 'fee_categories.update', 'fee_categories.delete']);

        $this->asCollege($college, $user)->post(route('fee-categories.store'), $this->payload())->assertRedirect();
        $category = $this->withTenant($college, fn () => FeeCategory::query()->firstOrFail());

        $this->asCollege($college, $user)->put(route('fee-categories.update', $category), $this->payload(['name' => 'Renamed']))->assertRedirect();
        $this->asCollege($college, $user)->delete(route('fee-categories.destroy', $category))->assertRedirect();

        $actions = $this->withTenant($college, fn () => AuditLog::query()->pluck('action')->all());

        $this->assertContains('fee_categories.created', $actions);
        $this->assertContains('fee_categories.updated', $actions);
        $this->assertContains('fee_categories.deleted', $actions);

        $created = $this->withTenant($college, fn () => AuditLog::query()->where('action', 'fee_categories.created')->firstOrFail());
        $this->assertSame($user->id, $created->user_id);
    }
}
