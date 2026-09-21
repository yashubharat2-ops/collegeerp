<?php

namespace Tests\Feature\Finance;

use App\Models\College;
use App\Models\FeeStructure;
use App\Models\FeeStructureItem;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Finance / Fees — Fee Structure tenant isolation.
 *
 * Tenancy is the security boundary of the whole product: a fee structure (and
 * each of its fee heads) belongs to exactly one college, every foreign key is
 * validated contextually against the ACTIVE college, and a browser-supplied
 * college_id never reaches the database.
 */
class FeeStructureTenancyTest extends TestCase
{
    use FeeStructureTestHelpers;

    private const MANAGE = [
        'fee_structures.view',
        'fee_structures.create',
        'fee_structures.update',
        'fee_structures.delete',
    ];

    public function test_structures_and_their_fee_components_are_scoped_per_college(): void
    {
        $college = $this->makeCollege('FSTN01');
        $other = $this->makeCollege('FSTN02');

        $mine = $this->makeFeeStructure($college, $this->makeFinanceContext($college, 'FSTN01'));
        $foreign = $this->makeFeeStructure($other, $this->makeFinanceContext($other, 'FSTN02'));

        $this->withTenant($college, function () use ($mine, $foreign): void {
            $this->assertSame(1, FeeStructure::query()->count());
            $this->assertSame($mine->id, FeeStructure::query()->firstOrFail()->id);
            $this->assertNotSame($foreign->id, $mine->id);
            $this->assertSame(2, FeeStructureItem::query()->count());
        });

        $this->withTenant($other, function () use ($foreign): void {
            $this->assertSame(1, FeeStructure::query()->count());
            $this->assertSame($foreign->id, FeeStructure::query()->firstOrFail()->id);
        });

        // The rows physically exist for both colleges.
        $this->assertSame(2, DB::table('fee_structures')->count());
        $this->assertSame(4, DB::table('fee_structure_items')->count());
    }

    public function test_a_foreign_structure_cannot_be_edited_updated_or_deleted(): void
    {
        $college = $this->makeCollege('FSTN03');
        $other = $this->makeCollege('FSTN04');
        $user = $this->makeUserWithPermissions($college, self::MANAGE);

        $foreign = $this->makeFeeStructure($other, $this->makeFinanceContext($other, 'FSTN04'), [
            'name' => 'Foreign Plan',
            'code' => 'FS-FOREIGN',
        ]);

        $this->asCollege($college, $user)->get(route('fee-structures.edit', $foreign))->assertNotFound();

        $this->asCollege($college, $user)
            ->put(route('fee-structures.update', $foreign), ['name' => 'Hijacked Plan'])
            ->assertNotFound();

        $this->asCollege($college, $user)
            ->delete(route('fee-structures.destroy', $foreign))
            ->assertNotFound();

        $this->assertDatabaseHas('fee_structures', [
            'id' => $foreign->id,
            'college_id' => $other->id,
            'name' => 'Foreign Plan',
            'deleted_at' => null,
        ]);
    }

    public function test_cross_college_academic_masters_are_rejected(): void
    {
        $college = $this->makeCollege('FSTN05');
        $other = $this->makeCollege('FSTN06');
        $user = $this->makeUserWithPermissions($college, self::MANAGE);
        $ctx = $this->makeFinanceContext($college, 'FSTN05');
        $foreignCtx = $this->makeFinanceContext($other, 'FSTN06');

        $this->asCollege($college, $user)
            ->post(route('fee-structures.store'), $this->feeStructurePayload($ctx, [
                'academic_year_id' => $foreignCtx['year']->id,
            ]))
            ->assertSessionHasErrors('academic_year_id');

        $this->asCollege($college, $user)
            ->post(route('fee-structures.store'), $this->feeStructurePayload($ctx, [
                'program_id' => $foreignCtx['prog']->id,
            ]))
            ->assertSessionHasErrors('program_id');

        $this->asCollege($college, $user)
            ->post(route('fee-structures.store'), $this->feeStructurePayload($ctx, [
                'academic_term_id' => $foreignCtx['term']->id,
            ]))
            ->assertSessionHasErrors('academic_term_id');

        $this->assertSame(0, DB::table('fee_structures')->where('code', 'FS-UG-2026')->count());
    }

    public function test_term_must_belong_to_the_selected_academic_year(): void
    {
        $college = $this->makeCollege('FSTN07');
        $user = $this->makeUserWithPermissions($college, self::MANAGE);
        $ctx = $this->makeFinanceContext($college, 'FSTN07');

        $nextYear = \App\Models\AcademicYear::create([
            'college_id' => $college->id,
            'name' => '2027 Next',
            'code' => 'AY-NEXT',
            'starts_on' => '2027-08-01',
            'ends_on' => '2028-05-31',
            'status' => 'active',
        ]);

        $nextTerm = \App\Models\AcademicTerm::create([
            'college_id' => $college->id,
            'academic_year_id' => $nextYear->id,
            'name' => 'Term Next',
            'code' => 'T-NEXT',
            'type' => 'term',
            'sequence' => 1,
            'status' => 'active',
        ]);

        $this->asCollege($college, $user)
            ->post(route('fee-structures.store'), $this->feeStructurePayload($ctx, [
                'academic_term_id' => $nextTerm->id,
            ]))
            ->assertSessionHasErrors('academic_term_id');

        $this->assertSame(0, DB::table('fee_structures')->count());
    }

    public function test_fee_components_of_another_structure_cannot_be_attached(): void
    {
        $college = $this->makeCollege('FSTN08');
        $user = $this->makeUserWithPermissions($college, self::MANAGE);
        $ctx = $this->makeFinanceContext($college, 'FSTN08');

        $mine = $this->makeFeeStructure($college, $ctx, ['code' => 'FS-MINE']);
        $theirs = $this->makeFeeStructure($college, $ctx, ['code' => 'FS-THEIRS']);

        $foreignItem = $this->withTenant($college, fn () => $theirs->items()->firstOrFail());

        $this->asCollege($college, $user)
            ->put(route('fee-structures.update', $mine), [
                'items' => [
                    ['id' => $foreignItem->id, 'name' => 'Stolen Fee', 'amount' => 1, 'sort_order' => 1, 'status' => 'active'],
                ],
            ])
            ->assertSessionHasErrors('items.0.id');

        $this->assertDatabaseHas('fee_structure_items', [
            'id' => $foreignItem->id,
            'fee_structure_id' => $theirs->id,
            'name' => 'Tuition Fee',
        ]);
    }

    public function test_fee_components_of_another_college_cannot_be_attached(): void
    {
        $college = $this->makeCollege('FSTN09');
        $other = $this->makeCollege('FSTN10');
        $user = $this->makeUserWithPermissions($college, self::MANAGE);
        $ctx = $this->makeFinanceContext($college, 'FSTN09');

        $mine = $this->makeFeeStructure($college, $ctx, ['code' => 'FS-LOCAL']);
        $foreign = $this->makeFeeStructure($other, $this->makeFinanceContext($other, 'FSTN10'));

        $foreignItem = $this->withTenant($other, fn () => $foreign->items()->firstOrFail());

        $this->asCollege($college, $user)
            ->put(route('fee-structures.update', $mine), [
                'items' => [
                    ['id' => $foreignItem->id, 'name' => 'Stolen Fee', 'amount' => 1, 'sort_order' => 1, 'status' => 'active'],
                ],
            ])
            ->assertSessionHasErrors('items.0.id');

        $this->assertDatabaseHas('fee_structure_items', [
            'id' => $foreignItem->id,
            'college_id' => $other->id,
            'name' => 'Tuition Fee',
        ]);
    }

    public function test_update_cannot_move_a_structure_into_another_college(): void
    {
        $college = $this->makeCollege('FSTN11');
        $other = $this->makeCollege('FSTN12');
        $user = $this->makeUserWithPermissions($college, self::MANAGE);
        $ctx = $this->makeFinanceContext($college, 'FSTN11');
        $foreignCtx = $this->makeFinanceContext($other, 'FSTN12');

        $structure = $this->makeFeeStructure($college, $ctx, ['code' => 'FS-STAY']);

        $this->asCollege($college, $user)
            ->put(route('fee-structures.update', $structure), [
                'name' => 'Still Mine',
                'college_id' => $other->id,
                'academic_year_id' => $foreignCtx['year']->id,
                'program_id' => $foreignCtx['prog']->id,
            ])
            ->assertSessionHasErrors(['academic_year_id', 'program_id']);

        $this->assertDatabaseHas('fee_structures', [
            'id' => $structure->id,
            'college_id' => $college->id,
            'academic_year_id' => $ctx['year']->id,
            'program_id' => $ctx['prog']->id,
        ]);
    }

    public function test_permissions_granted_in_another_college_do_not_authorise_here(): void
    {
        $college = $this->makeCollege('FSTN13');
        $other = $this->makeCollege('FSTN14');
        $ctxOther = $this->makeFinanceContext($other, 'FSTN14');

        // The user holds the fee permissions in $college and is merely a member
        // of $other, where they hold none.
        $user = $this->makeUserWithPermissions($college, self::MANAGE);
        $user->colleges()->syncWithoutDetaching([$other->id => ['is_default' => false]]);

        $this->asCollege($other, $user)->get(route('fee-structures.index'))->assertForbidden();

        $this->asCollege($other, $user)
            ->post(route('fee-structures.store'), $this->feeStructurePayload($ctxOther))
            ->assertForbidden();

        $this->assertSame(0, DB::table('fee_structures')->count());

        // The same user is still authorised inside the college that granted the permission.
        $this->asCollege($college, $user)->get(route('fee-structures.index'))->assertOk();
    }

    public function test_college_membership_is_required_even_for_a_permitted_user(): void
    {
        $college = $this->makeCollege('FSTN15');
        $outsider = $this->makeCollege('FSTN16');
        $user = $this->makeUserWithPermissions($college, self::MANAGE);

        // A raw session value for another college must never become the tenant.
        $this->asCollege($outsider, $user)->get(route('fee-structures.index'))->assertForbidden();
    }
}
