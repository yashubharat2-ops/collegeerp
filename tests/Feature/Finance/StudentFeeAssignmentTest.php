<?php

namespace Tests\Feature\Finance;

use App\Models\AuditLog;
use App\Models\FeeStructureItem;
use App\Models\StudentEnrollment;
use App\Models\StudentFeeAssignment;
use Tests\TestCase;

/**
 * Finance / Fees — Student Fee Assignment.
 *
 * The assignment is the bridge between an academic record (StudentEnrollment)
 * and a fee plan (FeeStructure). These tests pin the contextual rules that make
 * that bridge safe: same college, same academic year (and program where the
 * enrollment carries one), no duplicate payable assignment, a server-computed
 * amount snapshot that later fee-structure edits never rewrite, and no way to
 * re-price an existing assignment.
 */
class StudentFeeAssignmentTest extends TestCase
{
    use FeeStructureTestHelpers;

    public function test_a_fee_structure_can_be_assigned_to_an_enrollment(): void
    {
        $college = $this->makeCollege('FAST1');
        $user = $this->makeUserWithPermissions($college, ['student_fee_assignments.view', 'student_fee_assignments.create']);
        $ctx = $this->makeFinanceContext($college, 'FAST1');
        $structure = $this->makeFeeStructure($college, $ctx);
        $fixture = $this->makeFinanceEnrollment($college, $ctx, 'FAST1');

        $this->asCollege($college, $user)
            ->post(route('student-fee-assignments.store'), [
                'student_enrollment_id' => $fixture['enrollment']->id,
                'fee_structure_id' => $structure->id,
                'assigned_at' => '2026-08-10',
                'status' => StudentFeeAssignment::STATUS_ACTIVE,
                'remarks' => 'First year plan',
            ])
            ->assertRedirect(route('student-fee-assignments.index'));

        $assignment = $this->withTenant($college, fn () => StudentFeeAssignment::query()->firstOrFail());

        $this->assertSame($college->id, $assignment->college_id);
        $this->assertSame($fixture['enrollment']->id, $assignment->student_enrollment_id);
        $this->assertSame($structure->id, $assignment->fee_structure_id);
        // Amount comes from the plan's active components, computed server-side.
        $this->assertSame(26500.0, (float) $assignment->assigned_amount);
        $this->assertSame($user->id, $assignment->created_by);
    }

    public function test_the_assigned_amount_is_computed_server_side_and_ignores_the_payload(): void
    {
        $college = $this->makeCollege('FAST2');
        $user = $this->makeUserWithPermissions($college, ['student_fee_assignments.view', 'student_fee_assignments.create']);
        $ctx = $this->makeFinanceContext($college, 'FAST2');
        $structure = $this->makeFeeStructure($college, $ctx);
        $fixture = $this->makeFinanceEnrollment($college, $ctx, 'FAST2');

        $this->asCollege($college, $user)
            ->post(route('student-fee-assignments.store'), [
                'student_enrollment_id' => $fixture['enrollment']->id,
                'fee_structure_id' => $structure->id,
                'assigned_amount' => 1,
                'assigned_at' => '2026-08-10',
                'status' => StudentFeeAssignment::STATUS_ACTIVE,
            ])
            ->assertRedirect();

        $assignment = $this->withTenant($college, fn () => StudentFeeAssignment::query()->firstOrFail());

        $this->assertSame(26500.0, (float) $assignment->assigned_amount, 'A browser-supplied amount must never be trusted.');
    }

    public function test_the_assignment_keeps_its_amount_when_the_fee_structure_changes_later(): void
    {
        $college = $this->makeCollege('FAST3');
        $user = $this->makeUserWithPermissions($college, ['student_fee_assignments.view', 'student_fee_assignments.create', 'fee_structures.view', 'fee_structures.update']);
        $ctx = $this->makeFinanceContext($college, 'FAST3');
        $structure = $this->makeFeeStructure($college, $ctx);
        $fixture = $this->makeFinanceEnrollment($college, $ctx, 'FAST3');

        $assignment = $this->assignFeeStructure($college, $user, $fixture['enrollment'], $structure);

        // The plan is re-priced afterwards (new fee head + a bigger tuition fee).
        $this->asCollege($college, $user)
            ->put(route('fee-structures.update', $structure), $this->feeStructurePayload($ctx, [
                'name' => $structure->name,
                'code' => $structure->code,
                'items' => [
                    ['name' => 'Tuition Fee', 'amount' => 50000, 'sort_order' => 1, 'status' => 'active'],
                    ['name' => 'Lab Fee', 'amount' => 5000, 'sort_order' => 2, 'status' => 'active'],
                ],
            ]))
            ->assertRedirect();

        $this->assertSame(
            26500.0,
            (float) $assignment->fresh()->assigned_amount,
            'Editing a fee structure must never re-price an existing assignment.'
        );
    }

    public function test_the_same_structure_cannot_be_assigned_twice_to_the_same_enrollment(): void
    {
        $college = $this->makeCollege('FAST4');
        $user = $this->makeUserWithPermissions($college, ['student_fee_assignments.view', 'student_fee_assignments.create']);
        $ctx = $this->makeFinanceContext($college, 'FAST4');
        $structure = $this->makeFeeStructure($college, $ctx);
        $fixture = $this->makeFinanceEnrollment($college, $ctx, 'FAST4');

        $this->assignFeeStructure($college, $user, $fixture['enrollment'], $structure);

        $this->asCollege($college, $user)
            ->post(route('student-fee-assignments.store'), [
                'student_enrollment_id' => $fixture['enrollment']->id,
                'fee_structure_id' => $structure->id,
                'assigned_at' => '2026-08-11',
                'status' => StudentFeeAssignment::STATUS_ACTIVE,
            ])
            ->assertSessionHasErrors('fee_structure_id');

        $this->assertSame(1, $this->withTenant($college, fn () => StudentFeeAssignment::query()->count()));
    }

    public function test_a_cancelled_assignment_no_longer_blocks_re_assignment(): void
    {
        $college = $this->makeCollege('FAST5');
        $user = $this->makeUserWithPermissions($college, ['student_fee_assignments.view', 'student_fee_assignments.create', 'student_fee_assignments.update']);
        $ctx = $this->makeFinanceContext($college, 'FAST5');
        $structure = $this->makeFeeStructure($college, $ctx);
        $fixture = $this->makeFinanceEnrollment($college, $ctx, 'FAST5');

        $assignment = $this->assignFeeStructure($college, $user, $fixture['enrollment'], $structure);

        $this->asCollege($college, $user)
            ->put(route('student-fee-assignments.update', $assignment), [
                'assigned_at' => '2026-08-10',
                'status' => StudentFeeAssignment::STATUS_CANCELLED,
            ])
            ->assertRedirect();

        $this->assertSame(StudentFeeAssignment::STATUS_CANCELLED, $assignment->fresh()->status);

        $replacement = $this->assignFeeStructure($college, $user, $fixture['enrollment'], $structure, ['assigned_at' => '2026-08-12']);

        $this->assertNotSame($assignment->id, $replacement->id);
        $this->assertSame(2, $this->withTenant($college, fn () => StudentFeeAssignment::withTrashed()->count()));
    }

    public function test_a_structure_from_another_academic_year_or_program_is_rejected(): void
    {
        $college = $this->makeCollege('FAST6');
        $user = $this->makeUserWithPermissions($college, ['student_fee_assignments.view', 'student_fee_assignments.create']);
        $ctx = $this->makeFinanceContext($college, 'FAST6');
        $otherCtx = $this->makeFinanceContext($college, 'FAST6B');
        $fixture = $this->makeFinanceEnrollment($college, $ctx, 'FAST6');

        $wrongYear = $this->makeFeeStructure($college, $otherCtx);
        $wrongProgram = $this->makeFeeStructure($college, ['year' => $ctx['year'], 'term' => $ctx['term'], 'prog' => $otherCtx['prog']]);

        foreach ([$wrongYear, $wrongProgram] as $structure) {
            $this->asCollege($college, $user)
                ->post(route('student-fee-assignments.store'), [
                    'student_enrollment_id' => $fixture['enrollment']->id,
                    'fee_structure_id' => $structure->id,
                    'assigned_at' => '2026-08-10',
                    'status' => StudentFeeAssignment::STATUS_ACTIVE,
                ])
                ->assertSessionHasErrors('fee_structure_id');
        }

        $this->assertSame(0, $this->withTenant($college, fn () => StudentFeeAssignment::query()->count()));
    }

    public function test_an_enrollment_or_structure_of_another_college_is_rejected(): void
    {
        $college = $this->makeCollege('FAST7');
        $other = $this->makeCollege('FAST7X');
        $user = $this->makeUserWithPermissions($college, ['student_fee_assignments.view', 'student_fee_assignments.create']);
        $ctx = $this->makeFinanceContext($college, 'FAST7');
        $foreignCtx = $this->makeFinanceContext($other, 'FAST7X');
        $structure = $this->makeFeeStructure($college, $ctx);
        $foreignStructure = $this->makeFeeStructure($other, $foreignCtx);
        $fixture = $this->makeFinanceEnrollment($college, $ctx, 'FAST7');
        $foreignFixture = $this->makeFinanceEnrollment($other, $foreignCtx, 'FAST7X');

        $this->asCollege($college, $user)
            ->post(route('student-fee-assignments.store'), [
                'student_enrollment_id' => $foreignFixture['enrollment']->id,
                'fee_structure_id' => $structure->id,
                'assigned_at' => '2026-08-10',
                'status' => StudentFeeAssignment::STATUS_ACTIVE,
            ])
            ->assertSessionHasErrors('student_enrollment_id');

        $this->asCollege($college, $user)
            ->post(route('student-fee-assignments.store'), [
                'student_enrollment_id' => $fixture['enrollment']->id,
                'fee_structure_id' => $foreignStructure->id,
                'assigned_at' => '2026-08-10',
                'status' => StudentFeeAssignment::STATUS_ACTIVE,
            ])
            ->assertSessionHasErrors('fee_structure_id');

        $this->assertSame(0, $this->withTenant($college, fn () => StudentFeeAssignment::query()->count()));
        $this->assertSame(0, $this->withTenant($other, fn () => StudentFeeAssignment::query()->count()));
    }

    public function test_a_cancelled_or_withdrawn_enrollment_cannot_be_charged(): void
    {
        $college = $this->makeCollege('FAST8');
        $user = $this->makeUserWithPermissions($college, ['student_fee_assignments.view', 'student_fee_assignments.create']);
        $ctx = $this->makeFinanceContext($college, 'FAST8');
        $structure = $this->makeFeeStructure($college, $ctx);
        $cancelled = $this->makeFinanceEnrollment($college, $ctx, 'FAST8A', ['status' => 'cancelled']);
        $withdrawn = $this->makeFinanceEnrollment($college, $ctx, 'FAST8B', ['status' => 'withdrawn']);

        foreach ([$cancelled, $withdrawn] as $fixture) {
            $this->asCollege($college, $user)
                ->post(route('student-fee-assignments.store'), [
                    'student_enrollment_id' => $fixture['enrollment']->id,
                    'fee_structure_id' => $structure->id,
                    'assigned_at' => '2026-08-10',
                    'status' => StudentFeeAssignment::STATUS_ACTIVE,
                ])
                ->assertSessionHasErrors('student_enrollment_id');
        }

        $this->assertSame(0, $this->withTenant($college, fn () => StudentFeeAssignment::query()->count()));
    }

    public function test_the_assignment_cannot_be_re_priced_through_an_update(): void
    {
        $college = $this->makeCollege('FAST9');
        $user = $this->makeUserWithPermissions($college, ['student_fee_assignments.view', 'student_fee_assignments.update']);
        $ctx = $this->makeFinanceContext($college, 'FAST9');
        $structure = $this->makeFeeStructure($college, $ctx);
        $other = $this->makeFeeStructure($college, $ctx, ['code' => 'FS-OTHER']);
        $fixture = $this->makeFinanceEnrollment($college, $ctx, 'FAST9');
        $assignment = $this->assignFeeStructure($college, $user, $fixture['enrollment'], $structure);

        $this->asCollege($college, $user)
            ->put(route('student-fee-assignments.update', $assignment), [
                'assigned_at' => '2026-08-20',
                'status' => StudentFeeAssignment::STATUS_ACTIVE,
                'remarks' => 'Reviewed',
                'fee_structure_id' => $other->id,
                'assigned_amount' => 5,
            ])
            ->assertRedirect();

        $assignment->refresh();
        $this->assertSame($structure->id, $assignment->fee_structure_id, 'The plan of an existing assignment is immutable.');
        $this->assertSame(26500.0, (float) $assignment->assigned_amount);
        $this->assertSame('Reviewed', $assignment->remarks);
    }

    public function test_cross_tenant_ids_cannot_be_edited_or_deleted(): void
    {
        $college = $this->makeCollege('FAST10');
        $other = $this->makeCollege('FAST10X');
        $user = $this->makeUserWithPermissions($college, ['student_fee_assignments.view', 'student_fee_assignments.update', 'student_fee_assignments.delete']);
        $foreignCtx = $this->makeFinanceContext($other, 'FAST10X');
        $foreignStructure = $this->makeFeeStructure($other, $foreignCtx);
        $foreignFixture = $this->makeFinanceEnrollment($other, $foreignCtx, 'FAST10X');
        $foreign = $this->assignFeeStructure($other, $user, $foreignFixture['enrollment'], $foreignStructure);

        $this->asCollege($college, $user)->get(route('student-fee-assignments.index'))->assertOk();
        $this->asCollege($college, $user)->get(route('student-fee-assignments.edit', $foreign))->assertNotFound();
        $this->asCollege($college, $user)
            ->put(route('student-fee-assignments.update', $foreign), ['assigned_at' => '2026-08-12', 'status' => 'cancelled'])
            ->assertNotFound();
        $this->asCollege($college, $user)->delete(route('student-fee-assignments.destroy', $foreign))->assertNotFound();

        $this->assertNull($foreign->fresh()->deleted_at);
        $this->assertSame(StudentFeeAssignment::STATUS_ACTIVE, $foreign->fresh()->status);
    }

    public function test_the_module_requires_its_permissions(): void
    {
        $college = $this->makeCollege('FAST11');
        $stranger = $this->makeUserWithPermissions($college, ['students.view']);
        $ctx = $this->makeFinanceContext($college, 'FAST11');
        $structure = $this->makeFeeStructure($college, $ctx);
        $fixture = $this->makeFinanceEnrollment($college, $ctx, 'FAST11');

        $this->asCollege($college, $stranger)->get(route('student-fee-assignments.index'))->assertForbidden();
        $this->asCollege($college, $stranger)
            ->post(route('student-fee-assignments.store'), [
                'student_enrollment_id' => $fixture['enrollment']->id,
                'fee_structure_id' => $structure->id,
                'assigned_at' => '2026-08-10',
                'status' => StudentFeeAssignment::STATUS_ACTIVE,
            ])
            ->assertForbidden();
    }

    public function test_an_assignment_is_deleted_softly_and_audited(): void
    {
        $college = $this->makeCollege('FAST12');
        $user = $this->makeUserWithPermissions($college, ['student_fee_assignments.view', 'student_fee_assignments.create', 'student_fee_assignments.delete']);
        $ctx = $this->makeFinanceContext($college, 'FAST12');
        $structure = $this->makeFeeStructure($college, $ctx);
        $fixture = $this->makeFinanceEnrollment($college, $ctx, 'FAST12');
        $assignment = $this->assignFeeStructure($college, $user, $fixture['enrollment'], $structure);

        $this->asCollege($college, $user)->delete(route('student-fee-assignments.destroy', $assignment))->assertRedirect();

        $this->assertSoftDeleted('student_fee_assignments', ['id' => $assignment->id]);

        $actions = $this->withTenant($college, fn () => AuditLog::query()->pluck('action')->all());
        $this->assertContains('student_fee_assignments.created', $actions);
        $this->assertContains('student_fee_assignments.deleted', $actions);
    }

    public function test_a_structure_without_active_components_cannot_be_assigned(): void
    {
        $college = $this->makeCollege('FAST13');
        $user = $this->makeUserWithPermissions($college, ['student_fee_assignments.view', 'student_fee_assignments.create']);
        $ctx = $this->makeFinanceContext($college, 'FAST13');
        $structure = $this->makeFeeStructure($college, $ctx, [], [
            ['name' => 'Inactive Head', 'amount' => 1000, 'status' => FeeStructureItem::STATUS_INACTIVE],
        ]);
        $fixture = $this->makeFinanceEnrollment($college, $ctx, 'FAST13');

        $this->asCollege($college, $user)
            ->post(route('student-fee-assignments.store'), [
                'student_enrollment_id' => $fixture['enrollment']->id,
                'fee_structure_id' => $structure->id,
                'assigned_at' => '2026-08-10',
                'status' => StudentFeeAssignment::STATUS_ACTIVE,
            ])
            ->assertSessionHasErrors('fee_structure_id');

        $this->assertSame(0, $this->withTenant($college, fn () => StudentFeeAssignment::query()->count()));
    }
}
