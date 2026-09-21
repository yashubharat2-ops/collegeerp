<?php

namespace Tests\Feature\Results;

use App\Models\AuditLog;
use App\Models\College;
use App\Models\GradeScale;
use App\Models\GradeScaleItem;
use Tests\TestCase;

/**
 * Grade / Pass-Fail configuration (Examinations Phase 3).
 *
 * Covers CRUD, RBAC, tenant isolation, structural validation of the grade
 * bands, deterministic ordering and audit logging.
 *
 * The bands used below are TEST DATA chosen by the test; nothing in the
 * application hard-codes a grading system.
 */
class GradeScaleManagementTest extends TestCase
{
    use ResultTestHelpers;

    private const MANAGE = [
        'grade_scales.view',
        'grade_scales.create',
        'grade_scales.update',
        'grade_scales.delete',
    ];

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Ten Point Scale',
            'code' => 'GS-TEN',
            'status' => 'active',
            'description' => 'Ten point grading scale',
            'items' => $this->defaultGradeBands(),
        ], $overrides);
    }

    // ---------------------------------------------------------------- index

    public function test_grade_scale_index_lists_scales_of_the_active_college_only(): void
    {
        $college = $this->makeCollege('GSIDX1');
        $other = $this->makeCollege('GSIDX2');
        $user = $this->makeUserWithPermissions($college, ['grade_scales.view']);

        $mine = $this->makeGradeScale($college, null, ['name' => 'Own Scale', 'code' => 'GS-OWN']);
        $foreign = $this->makeGradeScale($other, null, ['name' => 'Foreign Scale', 'code' => 'GS-FOR']);

        $response = $this->asCollege($college, $user)->get(route('grade-scales.index'))->assertOk();

        $response->assertSee('Own Scale')->assertDontSee('Foreign Scale');

        $this->assertDatabaseHas('grade_scales', ['id' => $foreign->id, 'college_id' => $other->id]);
        $this->assertSame($college->id, (int) $mine->college_id);
    }

    public function test_grade_scale_index_requires_view_permission(): void
    {
        $college = $this->makeCollege('GSRBAC1');
        $stranger = $this->makeUserWithPermissions($college, ['students.view']);

        $this->asCollege($college, $stranger)->get(route('grade-scales.index'))->assertForbidden();
    }

    public function test_super_admin_can_view_the_grade_scale_index(): void
    {
        $college = $this->makeCollege('GSSUP1');
        $this->makeGradeScale($college, null, ['name' => 'Super Visible', 'code' => 'GS-SUP']);

        $this->asCollege($college, $this->makeSuperAdmin($college))
            ->get(route('grade-scales.index'))
            ->assertOk()
            ->assertSee('Super Visible');
    }

    // --------------------------------------------------------------- create

    public function test_grade_scale_can_be_created_with_its_grade_bands(): void
    {
        $college = $this->makeCollege('GSCRE1');
        $user = $this->makeUserWithPermissions($college, self::MANAGE);

        $response = $this->asCollege($college, $user)
            ->post(route('grade-scales.store'), $this->payload())
            ->assertRedirect(route('grade-scales.index'));

        $response->assertSessionHas('success');

        $scale = GradeScale::query()->where('code', 'GS-TEN')->firstOrFail();

        $this->assertSame($college->id, (int) $scale->college_id, 'college_id must come from the tenant context.');
        $this->assertSame(4, $scale->items()->count());
        $this->assertDatabaseHas('grade_scale_items', [
            'grade_scale_id' => $scale->id,
            'grade' => 'A',
            'min_percentage' => 80,
            'max_percentage' => 100,
        ]);
    }

    public function test_grade_scale_create_page_is_reachable_with_create_permission(): void
    {
        $college = $this->makeCollege('GSCRE2');
        $user = $this->makeUserWithPermissions($college, ['grade_scales.create']);

        $this->asCollege($college, $user)->get(route('grade-scales.create'))->assertOk();
    }

    public function test_grade_scale_creation_requires_create_permission(): void
    {
        $college = $this->makeCollege('GSCRE3');
        $viewer = $this->makeUserWithPermissions($college, ['grade_scales.view']);

        $this->asCollege($college, $viewer)
            ->post(route('grade-scales.store'), $this->payload())
            ->assertForbidden();

        $this->assertDatabaseMissing('grade_scales', ['code' => 'GS-TEN']);
    }

    // -------------------------------------------------------- code / tenant

    public function test_grade_scale_code_must_be_unique_within_the_college(): void
    {
        $college = $this->makeCollege('GSUNQ1');
        $this->makeGradeScale($college, null, ['code' => 'GS-DUP']);
        $user = $this->makeUserWithPermissions($college, self::MANAGE);

        $this->asCollege($college, $user)
            ->post(route('grade-scales.store'), $this->payload(['code' => 'GS-DUP']))
            ->assertSessionHasErrors('code');

        $this->assertSame(1, GradeScale::query()->where('code', 'GS-DUP')->count());
    }

    public function test_grade_scale_code_may_be_reused_in_a_different_college(): void
    {
        $college = $this->makeCollege('GSUNQ2');
        $other = $this->makeCollege('GSUNQ3');
        $this->makeGradeScale($other, null, ['code' => 'GS-SHARED']);

        $user = $this->makeUserWithPermissions($college, self::MANAGE);

        $this->asCollege($college, $user)
            ->post(route('grade-scales.store'), $this->payload(['code' => 'GS-SHARED']))
            ->assertRedirect(route('grade-scales.index'));

        // Both colleges own one scale with the same code, each invisible to the other.
        $this->assertSame(1, $this->withTenant($college, fn () => GradeScale::query()->where('code', 'GS-SHARED')->count()));
        $this->assertSame(1, $this->withTenant($other, fn () => GradeScale::query()->where('code', 'GS-SHARED')->count()));
        $this->assertSame(2, \Illuminate\Support\Facades\DB::table('grade_scales')->where('code', 'GS-SHARED')->count());
    }

    public function test_browser_supplied_college_id_is_ignored_on_create(): void
    {
        $college = $this->makeCollege('GSTNT1');
        $other = $this->makeCollege('GSTNT2');
        $user = $this->makeUserWithPermissions($college, self::MANAGE);

        $this->asCollege($college, $user)
            ->post(route('grade-scales.store'), $this->payload(['college_id' => $other->id]))
            ->assertRedirect(route('grade-scales.index'));

        $scale = GradeScale::query()->where('code', 'GS-TEN')->firstOrFail();
        $this->assertSame($college->id, (int) $scale->college_id, 'A forged college_id must never be honoured.');
    }

    // ------------------------------------------------------ band validation

    public function test_grade_bands_reject_a_minimum_above_the_maximum(): void
    {
        $college = $this->makeCollege('GSRNG1');
        $user = $this->makeUserWithPermissions($college, self::MANAGE);

        $response = $this->asCollege($college, $user)->post(route('grade-scales.store'), $this->payload([
            'items' => [
                ['grade' => 'A', 'min_percentage' => 90, 'max_percentage' => 50, 'sort_order' => 1, 'status' => 'active'],
            ],
        ]));

        $response->assertSessionHasErrors('items.0.max_percentage');
        $this->assertDatabaseMissing('grade_scales', ['code' => 'GS-TEN']);
    }

    public function test_grade_bands_reject_percentages_outside_zero_and_one_hundred(): void
    {
        $college = $this->makeCollege('GSRNG2');
        $user = $this->makeUserWithPermissions($college, self::MANAGE);

        $response = $this->asCollege($college, $user)->post(route('grade-scales.store'), $this->payload([
            'items' => [
                ['grade' => 'A', 'min_percentage' => -5, 'max_percentage' => 120, 'sort_order' => 1, 'status' => 'active'],
            ],
        ]));

        $response->assertSessionHasErrors(['items.0.min_percentage', 'items.0.max_percentage']);
    }

    public function test_grade_bands_reject_overlapping_ranges(): void
    {
        $college = $this->makeCollege('GSOVL1');
        $user = $this->makeUserWithPermissions($college, self::MANAGE);

        $response = $this->asCollege($college, $user)->post(route('grade-scales.store'), $this->payload([
            'items' => [
                ['grade' => 'A', 'min_percentage' => 70, 'max_percentage' => 100, 'sort_order' => 1, 'status' => 'active'],
                ['grade' => 'B', 'min_percentage' => 60, 'max_percentage' => 85, 'sort_order' => 2, 'status' => 'active'],
            ],
        ]));

        $response->assertSessionHasErrors('items');
        $this->assertDatabaseMissing('grade_scales', ['code' => 'GS-TEN']);
    }

    public function test_grade_bands_reject_duplicate_grades(): void
    {
        $college = $this->makeCollege('GSDUP1');
        $user = $this->makeUserWithPermissions($college, self::MANAGE);

        $response = $this->asCollege($college, $user)->post(route('grade-scales.store'), $this->payload([
            'items' => [
                ['grade' => 'A', 'min_percentage' => 0, 'max_percentage' => 50, 'sort_order' => 1, 'status' => 'active'],
                ['grade' => 'A', 'min_percentage' => 50.01, 'max_percentage' => 100, 'sort_order' => 2, 'status' => 'active'],
            ],
        ]));

        $response->assertSessionHasErrors('items.1.grade');
    }

    public function test_grade_scale_requires_at_least_one_band(): void
    {
        $college = $this->makeCollege('GSEMP1');
        $user = $this->makeUserWithPermissions($college, self::MANAGE);

        $this->asCollege($college, $user)
            ->post(route('grade-scales.store'), $this->payload(['items' => []]))
            ->assertSessionHasErrors('items');
    }

    public function test_adjacent_non_overlapping_bands_are_accepted(): void
    {
        $college = $this->makeCollege('GSADJ1');
        $user = $this->makeUserWithPermissions($college, self::MANAGE);

        $this->asCollege($college, $user)->post(route('grade-scales.store'), $this->payload([
            'items' => [
                ['grade' => 'P', 'min_percentage' => 0, 'max_percentage' => 40, 'sort_order' => 1, 'status' => 'active'],
                ['grade' => 'Q', 'min_percentage' => 40, 'max_percentage' => 60, 'sort_order' => 2, 'status' => 'active'],
                ['grade' => 'R', 'min_percentage' => 60, 'max_percentage' => 100, 'sort_order' => 3, 'status' => 'active'],
            ],
        ]))->assertRedirect(route('grade-scales.index'));
    }

    // --------------------------------------------------------------- update

    public function test_grade_scale_can_be_updated_together_with_its_bands(): void
    {
        $college = $this->makeCollege('GSUPD1');
        $user = $this->makeUserWithPermissions($college, self::MANAGE);
        $scale = $this->makeGradeScale($college);

        $this->asCollege($college, $user)->put(route('grade-scales.update', $scale), [
            'name' => 'Renamed Scale',
            'code' => $scale->code,
            'status' => 'inactive',
            'description' => 'Updated',
            'items' => [
                ['grade' => 'X', 'min_percentage' => 0, 'max_percentage' => 49.99, 'sort_order' => 1, 'status' => 'active'],
                ['grade' => 'Y', 'min_percentage' => 50, 'max_percentage' => 100, 'sort_order' => 2, 'status' => 'active'],
            ],
        ])->assertRedirect(route('grade-scales.index'));

        $scale->refresh();

        $this->assertSame('Renamed Scale', $scale->name);
        $this->assertSame('inactive', $scale->status);
        $this->assertSame(2, $scale->items()->count());
        $this->assertSame(['X', 'Y'], $scale->items()->pluck('grade')->all());
    }

    public function test_grade_scale_update_keeps_its_own_code(): void
    {
        $college = $this->makeCollege('GSUPD2');
        $user = $this->makeUserWithPermissions($college, self::MANAGE);
        $scale = $this->makeGradeScale($college, null, ['code' => 'GS-KEEP']);

        $this->asCollege($college, $user)->put(route('grade-scales.update', $scale), [
            'name' => 'Same Code',
            'code' => 'GS-KEEP',
            'status' => 'active',
        ])->assertRedirect(route('grade-scales.index'));

        $this->assertSame('Same Code', $scale->refresh()->name);
    }

    public function test_grade_scale_update_rejects_a_code_belonging_to_another_scale(): void
    {
        $college = $this->makeCollege('GSUPD3');
        $user = $this->makeUserWithPermissions($college, self::MANAGE);
        $scale = $this->makeGradeScale($college, null, ['code' => 'GS-A']);
        $this->makeGradeScale($college, null, ['code' => 'GS-B']);

        $this->asCollege($college, $user)
            ->put(route('grade-scales.update', $scale), ['name' => 'X', 'code' => 'GS-B', 'status' => 'active'])
            ->assertSessionHasErrors('code');
    }

    public function test_grade_scale_cannot_be_updated_without_update_permission(): void
    {
        $college = $this->makeCollege('GSUPD4');
        $viewer = $this->makeUserWithPermissions($college, ['grade_scales.view']);
        $scale = $this->makeGradeScale($college, null, ['name' => 'Untouched']);

        $this->asCollege($college, $viewer)
            ->put(route('grade-scales.update', $scale), ['name' => 'Hacked', 'code' => $scale->code, 'status' => 'active'])
            ->assertForbidden();

        $this->assertSame('Untouched', $scale->refresh()->name);
    }

    public function test_grade_scale_item_from_another_scale_cannot_be_updated_through_this_scale(): void
    {
        $college = $this->makeCollege('GSUPD5');
        $user = $this->makeUserWithPermissions($college, self::MANAGE);

        $mine = $this->makeGradeScale($college, null, ['code' => 'GS-MINE']);
        $foreign = $this->makeGradeScale($college, null, ['code' => 'GS-OTHER']);
        $foreignItem = $this->withTenant($college, fn () => $foreign->items()->firstOrFail());

        $this->asCollege($college, $user)->put(route('grade-scales.update', $mine), [
            'name' => 'Capturing',
            'code' => 'GS-MINE',
            'status' => 'active',
            'items' => [
                [
                    'id' => $foreignItem->id,
                    'grade' => 'Z',
                    'min_percentage' => 0,
                    'max_percentage' => 100,
                    'sort_order' => 1,
                    'status' => 'active',
                ],
            ],
        ])->assertSessionHasErrors('items.0.id');

        $this->assertSame($foreign->id, (int) $foreignItem->refresh()->grade_scale_id);
    }

    // --------------------------------------------------------------- delete

    public function test_grade_scale_can_be_soft_deleted_with_its_bands(): void
    {
        $college = $this->makeCollege('GSDEL1');
        $user = $this->makeUserWithPermissions($college, self::MANAGE);
        $scale = $this->makeGradeScale($college);
        $itemIds = $scale->items()->pluck('id')->all();

        $this->asCollege($college, $user)
            ->delete(route('grade-scales.destroy', $scale))
            ->assertRedirect(route('grade-scales.index'));

        $this->assertSoftDeleted('grade_scales', ['id' => $scale->id]);

        foreach ($itemIds as $id) {
            $this->assertDatabaseMissing('grade_scale_items', ['id' => $id]);
        }
    }

    public function test_grade_scale_delete_requires_delete_permission(): void
    {
        $college = $this->makeCollege('GSDEL2');
        $viewer = $this->makeUserWithPermissions($college, ['grade_scales.view']);
        $scale = $this->makeGradeScale($college);

        $this->asCollege($college, $viewer)->delete(route('grade-scales.destroy', $scale))->assertForbidden();
        $this->assertDatabaseHas('grade_scales', ['id' => $scale->id, 'deleted_at' => null]);
    }

    public function test_soft_deleted_grade_scale_is_no_longer_listed(): void
    {
        $college = $this->makeCollege('GSDEL3');
        $user = $this->makeUserWithPermissions($college, self::MANAGE);
        $scale = $this->makeGradeScale($college, null, ['name' => 'Vanishing Scale', 'code' => 'GS-VANISH']);

        $this->asCollege($college, $user)->delete(route('grade-scales.destroy', $scale));

        // The confirmation flash echoes the name, so assert on the code.
        $this->asCollege($college, $user)
            ->get(route('grade-scales.index'))
            ->assertOk()
            ->assertDontSee('GS-VANISH');

        $this->assertSame(0, $this->withTenant($college, fn () => GradeScale::query()->where('code', 'GS-VANISH')->count()));
    }

    // ---------------------------------------------------- tenant isolation

    public function test_grade_scale_from_another_college_cannot_be_updated(): void
    {
        $college = $this->makeCollege('GSXUP1');
        $other = $this->makeCollege('GSXUP2');
        $user = $this->makeUserWithPermissions($college, self::MANAGE);
        $foreign = $this->makeGradeScale($other, null, ['name' => 'Other College Scale']);

        $this->asCollege($college, $user)
            ->put(route('grade-scales.update', $foreign), ['name' => 'Hijacked', 'code' => $foreign->code, 'status' => 'active'])
            ->assertNotFound();

        $this->assertSame('Other College Scale', $foreign->refresh()->name);
    }

    public function test_grade_scale_from_another_college_cannot_be_deleted(): void
    {
        $college = $this->makeCollege('GSXDE1');
        $other = $this->makeCollege('GSXDE2');
        $user = $this->makeUserWithPermissions($college, self::MANAGE);
        $foreign = $this->makeGradeScale($other);

        $this->asCollege($college, $user)->delete(route('grade-scales.destroy', $foreign))->assertNotFound();
        $this->assertDatabaseHas('grade_scales', ['id' => $foreign->id, 'deleted_at' => null]);
    }

    // ------------------------------------------------------------- ordering

    public function test_grade_bands_are_returned_in_deterministic_order(): void
    {
        $college = $this->makeCollege('GSORD1');

        $scale = $this->makeGradeScale($college, [
            ['grade' => 'Third', 'min_percentage' => 70, 'max_percentage' => 100, 'sort_order' => 3],
            ['grade' => 'First', 'min_percentage' => 0, 'max_percentage' => 39.99, 'sort_order' => 1],
            ['grade' => 'Second', 'min_percentage' => 40, 'max_percentage' => 69.99, 'sort_order' => 2],
        ]);

        $this->withTenant($college, function () use ($scale): void {
            $this->assertSame(['First', 'Second', 'Third'], $scale->items()->pluck('grade')->all());
            $this->assertSame([1, 2, 3], $scale->items()->pluck('sort_order')->all());
        });
    }

    public function test_configuration_is_not_usable_when_bands_overlap(): void
    {
        $college = $this->makeCollege('GSUSR1');
        $scale = $this->makeGradeScale($college);

        // Break the configuration directly, the way a bad manual edit would.
        $this->withTenant($college, fn () => $scale->items()->orderBy('sort_order')->firstOrFail()->update(['max_percentage' => 100]));

        $this->withTenant($college, function () use ($scale): void {
            $this->assertFalse(
                app(\App\Services\Examinations\GradeScaleService::class)->configurationIsUsable($scale->refresh()),
                'An overlapping configuration must be rejected by the engine too.'
            );
        });
    }

    public function test_configuration_is_not_usable_when_all_bands_are_inactive(): void
    {
        $college = $this->makeCollege('GSUSR2');
        $scale = $this->makeGradeScale($college);

        $this->withTenant($college, fn () => $scale->items()->update(['status' => GradeScaleItem::STATUS_INACTIVE]));

        $this->withTenant($college, function () use ($scale): void {
            $this->assertFalse(
                app(\App\Services\Examinations\GradeScaleService::class)->configurationIsUsable($scale->refresh())
            );
        });
    }

    // ----------------------------------------------------------------- audit

    public function test_grade_scale_lifecycle_is_audit_logged(): void
    {
        $college = $this->makeCollege('GSAUD1');
        $user = $this->makeUserWithPermissions($college, self::MANAGE);

        $this->asCollege($college, $user)->post(route('grade-scales.store'), $this->payload(['code' => 'GS-AUD']));
        $scale = GradeScale::query()->where('code', 'GS-AUD')->firstOrFail();

        $this->asCollege($college, $user)->put(route('grade-scales.update', $scale), [
            'name' => 'Audited Scale',
            'code' => 'GS-AUD',
            'status' => 'active',
        ]);

        $this->asCollege($college, $user)->delete(route('grade-scales.destroy', $scale));

        $base = ['college_id' => $college->id, 'user_id' => $user->id, 'subject_type' => $scale->getMorphClass(), 'subject_id' => $scale->id];

        $this->assertDatabaseHas('audit_logs', $base + ['action' => 'grade_scales.created']);
        $this->assertDatabaseHas('audit_logs', $base + ['action' => 'grade_scales.updated']);
        $this->assertDatabaseHas('audit_logs', $base + ['action' => 'grade_scales.deleted']);

        $created = AuditLog::query()->where($base + ['action' => 'grade_scales.created'])->firstOrFail();
        $this->assertSame('GS-AUD', $created->new_values['code']);

        $updated = AuditLog::query()->where($base + ['action' => 'grade_scales.updated'])->firstOrFail();
        $this->assertSame('Ten Point Scale', $updated->old_values['name']);
        $this->assertSame('Audited Scale', $updated->new_values['name']);
    }

    public function test_no_grade_scale_is_created_when_validation_fails(): void
    {
        $college = $this->makeCollege('GSFAL1');
        $user = $this->makeUserWithPermissions($college, self::MANAGE);

        $this->asCollege($college, $user)->post(route('grade-scales.store'), $this->payload([
            'items' => [
                ['grade' => 'A', 'min_percentage' => 80, 'max_percentage' => 100, 'sort_order' => 1, 'status' => 'active'],
                ['grade' => 'B', 'min_percentage' => 70, 'max_percentage' => 90, 'sort_order' => 2, 'status' => 'active'],
            ],
        ]))->assertSessionHasErrors('items');

        $this->assertSame(0, GradeScale::query()->where('code', 'GS-TEN')->count());
        $this->assertSame(0, GradeScaleItem::query()->where('grade', 'A')->count());
    }

    public function test_grade_scales_are_scoped_per_college(): void
    {
        $college = $this->makeCollege('GSSCP1');
        $other = $this->makeCollege('GSSCP2');

        $mine = $this->makeGradeScale($college);
        $foreign = $this->makeGradeScale($other);

        $this->withTenant($college, function () use ($mine, $foreign): void {
            $this->assertSame(1, GradeScale::query()->count());
            $this->assertSame($mine->id, GradeScale::query()->firstOrFail()->id);
            $this->assertNotSame($foreign->id, $mine->id);
        });

        $this->withTenant($other, function () use ($foreign): void {
            $this->assertSame(1, GradeScale::query()->count());
            $this->assertSame($foreign->id, GradeScale::query()->firstOrFail()->id);
        });
    }

    public function test_grade_scale_belongs_to_the_college_that_created_it(): void
    {
        $college = $this->makeCollege('GSOWN1');
        $scale = $this->makeGradeScale($college);

        $this->assertSame($college->id, (int) $scale->college_id);
        $this->assertSame($college->id, College::find($college->id)?->id);
        $this->assertSame($college->id, (int) $this->withTenant($college, fn () => $scale->items()->firstOrFail())->college_id);
    }
}
