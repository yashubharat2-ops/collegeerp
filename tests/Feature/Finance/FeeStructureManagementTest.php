<?php

namespace Tests\Feature\Finance;

use App\Models\AuditLog;
use App\Models\FeeStructure;
use App\Models\FeeStructureItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Finance / Fees — Fee Structure foundation (CRUD, validation, audit).
 *
 * Covers structure and fee-head persistence, the money rules (amounts are never
 * negative), duplicate-code prevention within college/year/program, fee-head
 * syncing, deterministic ordering and audit logging.
 *
 * The fee heads used below are TEST DATA chosen by the test; nothing in the
 * application hard-codes a fee policy.
 */
class FeeStructureManagementTest extends TestCase
{
    use FeeStructureTestHelpers;

    private const MANAGE = [
        'fee_structures.view',
        'fee_structures.create',
        'fee_structures.update',
        'fee_structures.delete',
    ];

    // ---------------------------------------------------------------- index

    public function test_index_lists_only_the_structures_of_the_active_college(): void
    {
        $college = $this->makeCollege('FSIDX1');
        $other = $this->makeCollege('FSIDX2');
        $user = $this->makeUserWithPermissions($college, ['fee_structures.view']);

        $mine = $this->makeFeeStructure($college, $this->makeFinanceContext($college, 'FSIDX1'), [
            'name' => 'Own Fee Plan',
            'code' => 'FS-OWN',
        ]);

        $foreign = $this->makeFeeStructure($other, $this->makeFinanceContext($other, 'FSIDX2'), [
            'name' => 'Foreign Fee Plan',
            'code' => 'FS-FOR',
        ]);

        $this->asCollege($college, $user)
            ->get(route('fee-structures.index'))
            ->assertOk()
            ->assertSee('Own Fee Plan')
            ->assertDontSee('Foreign Fee Plan');

        $this->assertDatabaseHas('fee_structures', ['id' => $foreign->id, 'college_id' => $other->id]);
        $this->assertSame($college->id, (int) $mine->college_id);
    }

    public function test_index_shows_the_fee_components_and_their_total(): void
    {
        $college = $this->makeCollege('FSIDX3');
        $user = $this->makeUserWithPermissions($college, ['fee_structures.view']);
        $ctx = $this->makeFinanceContext($college, 'FSIDX3');

        $this->makeFeeStructure($college, $ctx, ['name' => 'Total Check Plan', 'code' => 'FS-TOT'], [
            ['name' => 'Tuition Fee', 'amount' => 25000, 'sort_order' => 1],
            ['name' => 'Lab Fee', 'amount' => 2500.5, 'sort_order' => 2],
            ['name' => 'Legacy Fee', 'amount' => 999, 'sort_order' => 3, 'status' => 'inactive'],
        ]);

        $this->asCollege($college, $user)
            ->get(route('fee-structures.index'))
            ->assertOk()
            ->assertSee('Tuition Fee')
            ->assertSee('Lab Fee')
            // Only ACTIVE components are charged: 25000 + 2500.50.
            ->assertSee('27,500.50');
    }

    public function test_index_filters_by_search_year_program_and_status(): void
    {
        $college = $this->makeCollege('FSIDX4');
        $user = $this->makeUserWithPermissions($college, ['fee_structures.view']);
        $ctx = $this->makeFinanceContext($college, 'FSIDX4');

        $this->makeFeeStructure($college, $ctx, ['name' => 'Matches Plan', 'code' => 'FS-MATCH']);
        $this->makeFeeStructure($college, $ctx, ['name' => 'Other Plan', 'code' => 'FS-OTHER', 'status' => 'inactive']);

        $this->asCollege($college, $user)
            ->get(route('fee-structures.index', ['search' => 'Matches']))
            ->assertOk()
            ->assertSee('Matches Plan')
            ->assertDontSee('Other Plan');

        $this->asCollege($college, $user)
            ->get(route('fee-structures.index', ['search' => 'FS-OTHER']))
            ->assertOk()
            ->assertSee('Other Plan')
            ->assertDontSee('Matches Plan');

        $this->asCollege($college, $user)
            ->get(route('fee-structures.index', ['status' => 'inactive']))
            ->assertOk()
            ->assertSee('Other Plan')
            ->assertDontSee('Matches Plan');

        $this->asCollege($college, $user)
            ->get(route('fee-structures.index', ['academic_year_id' => $ctx['year']->id, 'program_id' => $ctx['prog']->id]))
            ->assertOk()
            ->assertSee('Matches Plan');
    }

    // --------------------------------------------------------------- create

    public function test_fee_structure_can_be_created_with_its_fee_components(): void
    {
        $college = $this->makeCollege('FSCRE1');
        $user = $this->makeUserWithPermissions($college, self::MANAGE);
        $ctx = $this->makeFinanceContext($college, 'FSCRE1');

        $response = $this->asCollege($college, $user)
            ->post(route('fee-structures.store'), $this->feeStructurePayload($ctx))
            ->assertRedirect(route('fee-structures.index'));

        $response->assertSessionHas('success');

        $structure = $this->withTenant($college, fn () => FeeStructure::query()->where('code', 'FS-UG-2026')->firstOrFail());

        $this->assertSame($college->id, (int) $structure->college_id, 'college_id must come from the tenant context.');
        $this->assertSame($ctx['year']->id, (int) $structure->academic_year_id);
        $this->assertSame($ctx['prog']->id, (int) $structure->program_id);
        $this->assertSame($ctx['term']->id, (int) $structure->academic_term_id);
        $this->assertSame($user->id, (int) $structure->created_by);

        $items = $this->withTenant($college, fn () => $structure->items()->get());

        $this->assertCount(2, $items);
        $this->assertSame(['Tuition Fee', 'Library Fee'], $items->pluck('name')->all());
        $this->assertSame($college->id, (int) $items->first()->college_id);
        $this->assertSame(25000.0, (float) $items->first()->amount);
        // Totals are always computed inside the tenant context (college scope).
        $this->assertSame(26500.0, round($this->withTenant($college, fn () => $structure->totalAmount()), 2));
    }

    public function test_optional_term_may_be_omitted_for_a_whole_year_plan(): void
    {
        $college = $this->makeCollege('FSCRE2');
        $user = $this->makeUserWithPermissions($college, self::MANAGE);
        $ctx = $this->makeFinanceContext($college, 'FSCRE2');

        $this->asCollege($college, $user)
            ->post(route('fee-structures.store'), $this->feeStructurePayload($ctx, ['academic_term_id' => null]))
            ->assertRedirect(route('fee-structures.index'));

        $structure = $this->withTenant($college, fn () => FeeStructure::query()->where('code', 'FS-UG-2026')->firstOrFail());

        $this->assertNull($structure->academic_term_id);
    }

    public function test_browser_supplied_college_id_is_ignored_on_create(): void
    {
        $college = $this->makeCollege('FSTNT1');
        $other = $this->makeCollege('FSTNT2');
        $user = $this->makeUserWithPermissions($college, self::MANAGE);
        $ctx = $this->makeFinanceContext($college, 'FSTNT1');

        $this->asCollege($college, $user)
            ->post(route('fee-structures.store'), $this->feeStructurePayload($ctx, ['college_id' => $other->id]))
            ->assertRedirect(route('fee-structures.index'));

        $structure = $this->withTenant($college, fn () => FeeStructure::query()->where('code', 'FS-UG-2026')->firstOrFail());

        $this->assertSame($college->id, (int) $structure->college_id, 'A forged college_id must never be honoured.');
    }

    public function test_create_page_is_reachable_with_create_permission(): void
    {
        $college = $this->makeCollege('FSCRE3');
        $user = $this->makeUserWithPermissions($college, ['fee_structures.create']);

        $this->asCollege($college, $user)
            ->get(route('fee-structures.create'))
            ->assertOk()
            ->assertSee('Fee Components');
    }

    // --------------------------------------------------------- code / scope

    public function test_code_must_be_unique_within_college_year_and_program(): void
    {
        $college = $this->makeCollege('FSUNQ1');
        $user = $this->makeUserWithPermissions($college, self::MANAGE);
        $ctx = $this->makeFinanceContext($college, 'FSUNQ1');

        $this->makeFeeStructure($college, $ctx, ['code' => 'FS-DUP']);

        $this->asCollege($college, $user)
            ->post(route('fee-structures.store'), $this->feeStructurePayload($ctx, ['code' => 'FS-DUP']))
            ->assertSessionHasErrors('code');

        $this->assertSame(1, DB::table('fee_structures')->where('code', 'FS-DUP')->count());
    }

    public function test_same_code_may_be_used_for_another_program_year_or_college(): void
    {
        $college = $this->makeCollege('FSUNQ2');
        $otherCollege = $this->makeCollege('FSUNQ3');
        $user = $this->makeUserWithPermissions($college, self::MANAGE);
        $ctx = $this->makeFinanceContext($college, 'FSUNQ2');
        $otherCtx = $this->makeFinanceContext($otherCollege, 'FSUNQ3');

        $this->makeFeeStructure($college, $ctx, ['code' => 'FS-SHARED']);
        $this->makeFeeStructure($otherCollege, $otherCtx, ['code' => 'FS-SHARED']);

        // Another program of the same college reuses the code…
        $secondProgram = \App\Models\Program::create([
            'college_id' => $college->id,
            'name' => 'Second Program',
            'code' => 'P-SECOND',
            'status' => 'active',
        ]);

        $this->asCollege($college, $user)
            ->post(route('fee-structures.store'), $this->feeStructurePayload($ctx, [
                'code' => 'FS-SHARED',
                'program_id' => $secondProgram->id,
            ]))
            ->assertRedirect(route('fee-structures.index'));

        // …and so does another academic year.
        $secondYear = \App\Models\AcademicYear::create([
            'college_id' => $college->id,
            'name' => '2027 Second',
            'code' => 'AY-SECOND',
            'starts_on' => '2027-08-01',
            'ends_on' => '2028-05-31',
            'status' => 'active',
        ]);

        $this->asCollege($college, $user)
            ->post(route('fee-structures.store'), $this->feeStructurePayload($ctx, [
                'code' => 'FS-SHARED',
                'academic_year_id' => $secondYear->id,
                'academic_term_id' => null,
            ]))
            ->assertRedirect(route('fee-structures.index'));

        $this->assertSame(3, DB::table('fee_structures')->where('code', 'FS-SHARED')->count());
    }

    public function test_code_can_be_reused_after_a_structure_is_deleted(): void
    {
        $college = $this->makeCollege('FSUNQ4');
        $user = $this->makeUserWithPermissions($college, self::MANAGE);
        $ctx = $this->makeFinanceContext($college, 'FSUNQ4');

        $structure = $this->makeFeeStructure($college, $ctx, ['code' => 'FS-REUSE']);

        $this->asCollege($college, $user)->delete(route('fee-structures.destroy', $structure))->assertRedirect();

        $this->asCollege($college, $user)
            ->post(route('fee-structures.store'), $this->feeStructurePayload($ctx, ['code' => 'FS-REUSE']))
            ->assertRedirect(route('fee-structures.index'));

        $this->assertSame(1, DB::table('fee_structures')->where('code', 'FS-REUSE')->whereNull('deleted_at')->count());
        $this->assertSame(2, DB::table('fee_structures')->where('code', 'FS-REUSE')->count());
    }

    // ------------------------------------------------------------- amounts

    public function test_amount_must_not_be_negative(): void
    {
        $college = $this->makeCollege('FSAMT1');
        $user = $this->makeUserWithPermissions($college, self::MANAGE);
        $ctx = $this->makeFinanceContext($college, 'FSAMT1');

        $this->asCollege($college, $user)
            ->post(route('fee-structures.store'), $this->feeStructurePayload($ctx, [
                'items' => [
                    ['name' => 'Tuition Fee', 'amount' => -1, 'sort_order' => 1, 'status' => 'active'],
                ],
            ]))
            ->assertSessionHasErrors('items.0.amount');

        $this->assertSame(0, DB::table('fee_structures')->count());
        $this->assertSame(0, DB::table('fee_structure_items')->count());
    }

    public function test_zero_amount_is_allowed(): void
    {
        $college = $this->makeCollege('FSAMT2');
        $user = $this->makeUserWithPermissions($college, self::MANAGE);
        $ctx = $this->makeFinanceContext($college, 'FSAMT2');

        $this->asCollege($college, $user)
            ->post(route('fee-structures.store'), $this->feeStructurePayload($ctx, [
                'items' => [
                    ['name' => 'Sponsored Seat', 'amount' => 0, 'sort_order' => 1, 'status' => 'active'],
                ],
            ]))
            ->assertRedirect(route('fee-structures.index'));

        $this->assertDatabaseHas('fee_structure_items', ['name' => 'Sponsored Seat', 'amount' => 0]);
    }

    public function test_fee_head_model_refuses_a_negative_amount(): void
    {
        $college = $this->makeCollege('FSAMT3');
        $ctx = $this->makeFinanceContext($college, 'FSAMT3');
        $structure = $this->makeFeeStructure($college, $ctx, ['code' => 'FS-GUARD']);

        $this->expectException(ValidationException::class);

        FeeStructureItem::create([
            'college_id' => $college->id,
            'fee_structure_id' => $structure->id,
            'name' => 'Negative Fee',
            'amount' => -0.01,
            'sort_order' => 9,
            'status' => 'active',
        ]);
    }

    // ---------------------------------------------------------- components

    public function test_at_least_one_fee_component_is_required(): void
    {
        $college = $this->makeCollege('FSEMP1');
        $user = $this->makeUserWithPermissions($college, self::MANAGE);
        $ctx = $this->makeFinanceContext($college, 'FSEMP1');

        $this->asCollege($college, $user)
            ->post(route('fee-structures.store'), $this->feeStructurePayload($ctx, ['items' => []]))
            ->assertSessionHasErrors('items');

        $this->assertSame(0, DB::table('fee_structures')->count());
    }

    public function test_fee_component_names_must_be_unique_inside_one_structure(): void
    {
        $college = $this->makeCollege('FSDUP1');
        $user = $this->makeUserWithPermissions($college, self::MANAGE);
        $ctx = $this->makeFinanceContext($college, 'FSDUP1');

        $this->asCollege($college, $user)
            ->post(route('fee-structures.store'), $this->feeStructurePayload($ctx, [
                'items' => [
                    ['name' => 'Tuition Fee', 'amount' => 25000, 'sort_order' => 1, 'status' => 'active'],
                    ['name' => 'tuition fee', 'amount' => 100, 'sort_order' => 2, 'status' => 'active'],
                ],
            ]))
            ->assertSessionHasErrors('items.1.name');

        $this->assertSame(0, DB::table('fee_structures')->count());
    }

    public function test_fee_component_name_is_required_and_trimmed(): void
    {
        $college = $this->makeCollege('FSEMP2');
        $user = $this->makeUserWithPermissions($college, self::MANAGE);
        $ctx = $this->makeFinanceContext($college, 'FSEMP2');

        $this->asCollege($college, $user)
            ->post(route('fee-structures.store'), $this->feeStructurePayload($ctx, [
                'items' => [
                    ['name' => '   ', 'amount' => 100, 'sort_order' => 1, 'status' => 'active'],
                ],
            ]))
            ->assertSessionHasErrors('items.0.name');

        $this->asCollege($college, $user)
            ->post(route('fee-structures.store'), $this->feeStructurePayload($ctx, [
                'items' => [
                    ['name' => '  Sports Fee  ', 'amount' => 100, 'sort_order' => 1, 'status' => 'active'],
                ],
            ]))
            ->assertRedirect(route('fee-structures.index'));

        $this->assertDatabaseHas('fee_structure_items', ['name' => 'Sports Fee']);
    }

    // --------------------------------------------------------------- update

    public function test_structure_and_its_fee_components_can_be_updated(): void
    {
        $college = $this->makeCollege('FSUPD1');
        $user = $this->makeUserWithPermissions($college, self::MANAGE);
        $ctx = $this->makeFinanceContext($college, 'FSUPD1');

        $this->asCollege($college, $user)->post(route('fee-structures.store'), $this->feeStructurePayload($ctx));

        $structure = $this->withTenant($college, fn () => FeeStructure::query()->where('code', 'FS-UG-2026')->firstOrFail());
        [$tuition, $library] = $this->withTenant($college, fn () => $structure->items()->get()->all());

        $this->asCollege($college, $user)
            ->put(route('fee-structures.update', $structure), [
                'name' => 'Updated Fee Plan',
                'status' => 'inactive',
                'items' => [
                    // Updated in place…
                    ['id' => $tuition->id, 'name' => 'Tuition Fee', 'amount' => 27000, 'sort_order' => 1, 'status' => 'active'],
                    // …replaced…
                    ['id' => $library->id, 'name' => 'Library & Lab Fee', 'amount' => 2000, 'sort_order' => 2, 'status' => 'active'],
                    // …and added.
                    ['name' => 'Sports Fee', 'amount' => 800, 'sort_order' => 3, 'status' => 'active'],
                ],
            ])
            ->assertRedirect(route('fee-structures.index'));

        $structure = $this->withTenant($college, fn () => FeeStructure::query()->findOrFail($structure->id));

        $this->assertSame('Updated Fee Plan', $structure->name);
        $this->assertSame('inactive', $structure->status);
        $this->assertSame($user->id, (int) $structure->updated_by);

        $items = $this->withTenant($college, fn () => $structure->items()->get());

        $this->assertCount(3, $items);
        $this->assertSame(['Tuition Fee', 'Library & Lab Fee', 'Sports Fee'], $items->pluck('name')->all());
        $this->assertSame(27000.0, (float) $items->firstWhere('name', 'Tuition Fee')->amount);
        $this->assertSame(29800.0, round($this->withTenant($college, fn () => $structure->totalAmount()), 2));
    }

    public function test_update_ignores_a_code_of_another_structure(): void
    {
        $college = $this->makeCollege('FSUPD2');
        $user = $this->makeUserWithPermissions($college, self::MANAGE);
        $ctx = $this->makeFinanceContext($college, 'FSUPD2');

        $first = $this->makeFeeStructure($college, $ctx, ['code' => 'FS-FIRST']);
        $second = $this->makeFeeStructure($college, $ctx, ['code' => 'FS-SECOND']);

        $this->asCollege($college, $user)
            ->put(route('fee-structures.update', $second), ['code' => 'FS-FIRST'])
            ->assertSessionHasErrors('code');

        $this->assertSame('FS-SECOND', $this->withTenant($college, fn () => FeeStructure::query()->findOrFail($second->id))->code);
        $this->assertSame('FS-FIRST', $this->withTenant($college, fn () => FeeStructure::query()->findOrFail($first->id))->code);

        // Keeping its own code is not a duplicate.
        $this->asCollege($college, $user)
            ->put(route('fee-structures.update', $second), ['code' => 'FS-SECOND', 'name' => 'Second Plan Renamed'])
            ->assertRedirect(route('fee-structures.index'));

        $this->assertSame('Second Plan Renamed', $this->withTenant($college, fn () => FeeStructure::query()->findOrFail($second->id))->name);
    }

    public function test_edit_page_is_reachable_with_update_permission_and_shows_fee_components(): void
    {
        $college = $this->makeCollege('FSUPD3');
        $user = $this->makeUserWithPermissions($college, ['fee_structures.view', 'fee_structures.update']);
        $ctx = $this->makeFinanceContext($college, 'FSUPD3');

        $structure = $this->makeFeeStructure($college, $ctx, ['name' => 'Editable Plan', 'code' => 'FS-EDIT']);

        $this->asCollege($college, $user)
            ->get(route('fee-structures.edit', $structure))
            ->assertOk()
            ->assertSee('Editable Plan')
            ->assertSee('Tuition Fee');
    }

    // --------------------------------------------------------------- delete

    public function test_delete_soft_deletes_the_structure_and_removes_its_fee_components(): void
    {
        $college = $this->makeCollege('FSDEL1');
        $user = $this->makeUserWithPermissions($college, self::MANAGE);
        $ctx = $this->makeFinanceContext($college, 'FSDEL1');

        $structure = $this->makeFeeStructure($college, $ctx, ['name' => 'Doomed Plan', 'code' => 'FS-DEL']);

        $this->asCollege($college, $user)
            ->delete(route('fee-structures.destroy', $structure))
            ->assertRedirect(route('fee-structures.index'))
            ->assertSessionHas('success');

        $this->assertSoftDeleted('fee_structures', ['id' => $structure->id]);
        $this->assertSame(0, DB::table('fee_structure_items')->where('fee_structure_id', $structure->id)->count());
        $this->assertSame(0, DB::table('fee_structures')->where('code', 'FS-DEL')->whereNull('deleted_at')->count());

        $this->asCollege($college, $user)
            ->get(route('fee-structures.index'))
            ->assertOk()
            ->assertDontSee('Doomed Plan');
    }

    // ---------------------------------------------------------------- audit

    public function test_fee_structure_lifecycle_is_audit_logged(): void
    {
        $college = $this->makeCollege('FSAUD1');
        $user = $this->makeUserWithPermissions($college, self::MANAGE);
        $ctx = $this->makeFinanceContext($college, 'FSAUD1');

        $this->asCollege($college, $user)->post(route('fee-structures.store'), $this->feeStructurePayload($ctx));

        $structure = $this->withTenant($college, fn () => FeeStructure::query()->where('code', 'FS-UG-2026')->firstOrFail());

        $this->asCollege($college, $user)->put(route('fee-structures.update', $structure), ['name' => 'Audited Plan']);
        $this->asCollege($college, $user)->delete(route('fee-structures.destroy', $structure));

        $base = [
            'college_id' => $college->id,
            'user_id' => $user->id,
            'subject_type' => $structure->getMorphClass(),
            'subject_id' => $structure->id,
        ];

        $this->assertDatabaseHas('audit_logs', $base + ['action' => 'fee_structures.created']);
        $this->assertDatabaseHas('audit_logs', $base + ['action' => 'fee_structures.updated']);
        $this->assertDatabaseHas('audit_logs', $base + ['action' => 'fee_structures.deleted']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'fee_structure_items.created']);

        $created = AuditLog::query()->where($base + ['action' => 'fee_structures.created'])->firstOrFail();
        $this->assertSame('FS-UG-2026', $created->new_values['code']);
        $this->assertSame($ctx['year']->id, $created->new_values['academic_year_id']);

        $updated = AuditLog::query()->where($base + ['action' => 'fee_structures.updated'])->firstOrFail();
        $this->assertSame('Undergraduate Fee Plan', $updated->old_values['name']);
        $this->assertSame('Audited Plan', $updated->new_values['name']);

        // The deletion record keeps the fee heads that were removed with it.
        $deleted = AuditLog::query()->where($base + ['action' => 'fee_structures.deleted'])->firstOrFail();
        $this->assertCount(2, $deleted->old_values['items']);
        $this->assertSame('Tuition Fee', $deleted->old_values['items'][0]['name']);
    }

    public function test_no_structure_is_created_when_validation_fails(): void
    {
        $college = $this->makeCollege('FSFAL1');
        $user = $this->makeUserWithPermissions($college, self::MANAGE);
        $ctx = $this->makeFinanceContext($college, 'FSFAL1');

        $this->asCollege($college, $user)
            ->post(route('fee-structures.store'), $this->feeStructurePayload($ctx, [
                'name' => '',
                'items' => [
                    ['name' => 'Tuition Fee', 'amount' => 100, 'sort_order' => 1, 'status' => 'active'],
                ],
            ]))
            ->assertSessionHasErrors('name');

        $this->assertSame(0, DB::table('fee_structures')->count());
        $this->assertSame(0, DB::table('fee_structure_items')->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'like', 'fee_structure%')->count());
    }
}
