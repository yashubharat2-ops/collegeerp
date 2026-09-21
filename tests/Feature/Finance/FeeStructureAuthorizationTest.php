<?php

namespace Tests\Feature\Finance;

use App\Models\FeeStructure;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Finance / Fees — Fee Structure RBAC.
 *
 * Each action is gated on its own permission slug (fee_structures.view /
 * create / update / delete) through the policy; a user holding one permission
 * never gains another. Super Admins are authorised because the seeder grants
 * them the same slugs — not through a bypass branch.
 */
class FeeStructureAuthorizationTest extends TestCase
{
    use FeeStructureTestHelpers;

    public function test_index_requires_the_view_permission(): void
    {
        $college = $this->makeCollege('FSRB01');
        $stranger = $this->makeUserWithPermissions($college, ['students.view']);

        $this->asCollege($college, $stranger)->get(route('fee-structures.index'))->assertForbidden();
    }

    public function test_create_page_and_store_require_the_create_permission(): void
    {
        $college = $this->makeCollege('FSRB02');
        $ctx = $this->makeFinanceContext($college, 'FSRB02');
        $viewer = $this->makeUserWithPermissions($college, ['fee_structures.view']);

        $this->asCollege($college, $viewer)->get(route('fee-structures.create'))->assertForbidden();

        $this->asCollege($college, $viewer)
            ->post(route('fee-structures.store'), $this->feeStructurePayload($ctx))
            ->assertForbidden();

        $this->assertSame(0, DB::table('fee_structures')->count());
    }

    public function test_edit_and_update_require_the_update_permission(): void
    {
        $college = $this->makeCollege('FSRB03');
        $ctx = $this->makeFinanceContext($college, 'FSRB03');
        $viewer = $this->makeUserWithPermissions($college, ['fee_structures.view']);
        $structure = $this->makeFeeStructure($college, $ctx, ['name' => 'Read Only Plan', 'code' => 'FS-RO']);

        $this->asCollege($college, $viewer)->get(route('fee-structures.edit', $structure))->assertForbidden();

        $this->asCollege($college, $viewer)
            ->put(route('fee-structures.update', $structure), ['name' => 'Renamed'])
            ->assertForbidden();

        $this->assertDatabaseHas('fee_structures', ['id' => $structure->id, 'name' => 'Read Only Plan']);
    }

    public function test_delete_requires_the_delete_permission(): void
    {
        $college = $this->makeCollege('FSRB04');
        $ctx = $this->makeFinanceContext($college, 'FSRB04');
        $manager = $this->makeUserWithPermissions($college, ['fee_structures.view', 'fee_structures.update']);
        $structure = $this->makeFeeStructure($college, $ctx, ['code' => 'FS-NODEL']);

        $this->asCollege($college, $manager)
            ->delete(route('fee-structures.destroy', $structure))
            ->assertForbidden();

        $this->assertDatabaseHas('fee_structures', ['id' => $structure->id, 'deleted_at' => null]);
        $this->assertSame(2, DB::table('fee_structure_items')->where('fee_structure_id', $structure->id)->count());
    }

    public function test_a_user_without_any_fee_permission_cannot_reach_any_action(): void
    {
        $college = $this->makeCollege('FSRB05');
        $ctx = $this->makeFinanceContext($college, 'FSRB05');
        $stranger = $this->makeUserWithPermissions($college, ['students.view']);
        $structure = $this->makeFeeStructure($college, $ctx, ['code' => 'FS-LOCKED']);

        $this->asCollege($college, $stranger)->get(route('fee-structures.index'))->assertForbidden();
        $this->asCollege($college, $stranger)->get(route('fee-structures.create'))->assertForbidden();
        $this->asCollege($college, $stranger)->get(route('fee-structures.edit', $structure))->assertForbidden();
        $this->asCollege($college, $stranger)->put(route('fee-structures.update', $structure), ['name' => 'Nope'])->assertForbidden();
        $this->asCollege($college, $stranger)->delete(route('fee-structures.destroy', $structure))->assertForbidden();

        $this->assertSame(0, DB::table('fee_structures')->where('code', 'FS-UG-2026')->count());
        $this->assertDatabaseHas('fee_structures', ['id' => $structure->id, 'name' => $structure->name]);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('fee-structures.index'))->assertRedirect(route('login'));
    }

    public function test_super_admin_can_manage_fee_structures(): void
    {
        $college = $this->makeCollege('FSRB06');
        $ctx = $this->makeFinanceContext($college, 'FSRB06');
        $super = $this->makeSuperAdmin($college);

        $this->asCollege($college, $super)->get(route('fee-structures.index'))->assertOk();
        $this->asCollege($college, $super)->get(route('fee-structures.create'))->assertOk();

        $this->asCollege($college, $super)
            ->post(route('fee-structures.store'), $this->feeStructurePayload($ctx, ['code' => 'FS-SUPER']))
            ->assertRedirect(route('fee-structures.index'));

        $structure = FeeStructure::query()->where('code', 'FS-SUPER')->firstOrFail();

        $this->assertSame($college->id, (int) $structure->college_id);

        $this->asCollege($college, $super)
            ->put(route('fee-structures.update', $structure), ['name' => 'Super Admin Plan'])
            ->assertRedirect(route('fee-structures.index'));

        $this->asCollege($college, $super)
            ->delete(route('fee-structures.destroy', $structure))
            ->assertRedirect(route('fee-structures.index'));

        $this->assertSoftDeleted('fee_structures', ['id' => $structure->id]);
    }

    public function test_one_permission_never_implies_another(): void
    {
        $college = $this->makeCollege('FSRB07');
        $ctx = $this->makeFinanceContext($college, 'FSRB07');
        $structure = $this->makeFeeStructure($college, $ctx, ['code' => 'FS-SLUG']);

        // fee_structures.view only.
        $viewer = $this->makeUserWithPermissions($college, ['fee_structures.view']);
        $this->asCollege($college, $viewer)->get(route('fee-structures.index'))->assertOk();
        $this->asCollege($college, $viewer)->get(route('fee-structures.create'))->assertForbidden();
        $this->asCollege($college, $viewer)->get(route('fee-structures.edit', $structure))->assertForbidden();
        $this->asCollege($college, $viewer)->delete(route('fee-structures.destroy', $structure))->assertForbidden();

        // fee_structures.create only.
        $creator = $this->makeUserWithPermissions($college, ['fee_structures.create']);
        $this->asCollege($college, $creator)->get(route('fee-structures.create'))->assertOk();
        $this->asCollege($college, $creator)->get(route('fee-structures.index'))->assertForbidden();
        $this->asCollege($college, $creator)->delete(route('fee-structures.destroy', $structure))->assertForbidden();

        // fee_structures.update only.
        $updater = $this->makeUserWithPermissions($college, ['fee_structures.update']);
        $this->asCollege($college, $updater)->get(route('fee-structures.edit', $structure))->assertOk();
        $this->asCollege($college, $updater)->get(route('fee-structures.index'))->assertForbidden();
        $this->asCollege($college, $updater)->delete(route('fee-structures.destroy', $structure))->assertForbidden();

        // fee_structures.delete only.
        $deleter = $this->makeUserWithPermissions($college, ['fee_structures.delete']);
        $this->asCollege($college, $deleter)->get(route('fee-structures.index'))->assertForbidden();
        $this->asCollege($college, $deleter)->delete(route('fee-structures.destroy', $structure))->assertRedirect(route('fee-structures.index'));

        $this->assertSoftDeleted('fee_structures', ['id' => $structure->id]);
    }
}
