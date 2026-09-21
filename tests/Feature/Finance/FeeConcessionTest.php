<?php

namespace Tests\Feature\Finance;

use App\Models\AuditLog;
use App\Models\FeeConcession;
use App\Models\StudentFeeAssignment;
use Tests\TestCase;

/**
 * Finance / Fees — Fee Discounts / Concessions.
 *
 * A concession is money the college chooses not to collect, so the rules that
 * matter are: the amount is always computed server-side from the assignment
 * snapshot, a percentage stays inside 0–100, the total of applicable concessions
 * can never exceed the applicable fee, approval is a separate permission whose
 * metadata is written only by the approve action, and history is preserved.
 */
class FeeConcessionTest extends TestCase
{
    use FeeStructureTestHelpers;

    /**
     * @return array{0: \App\Models\College, 1: \App\Models\User, 2: StudentFeeAssignment}
     */
    private function concessionFixture(string $code, array $extra = []): array
    {
        $college = $this->makeCollege($code);
        $user = $this->makeUserWithPermissions($college, array_merge([
            'fee_concessions.view',
            'fee_concessions.create',
            'fee_concessions.update',
            'fee_concessions.delete',
            'fee_concessions.approve',
        ], $extra));
        $ctx = $this->makeFinanceContext($college, $code);
        $structure = $this->makeFeeStructure($college, $ctx);
        $fixture = $this->makeFinanceEnrollment($college, $ctx, $code);
        $assignment = $this->assignFeeStructure($college, $user, $fixture['enrollment'], $structure);

        return [$college, $user, $assignment];
    }

    public function test_a_fixed_concession_is_recorded_with_the_submitted_money_value(): void
    {
        [$college, $user, $assignment] = $this->concessionFixture('FCON1');

        $this->asCollege($college, $user)
            ->post(route('fee-concessions.store'), [
                'student_fee_assignment_id' => $assignment->id,
                'type' => FeeConcession::TYPE_FIXED,
                'value' => 1500,
                'reason' => 'Sibling discount',
            ])
            ->assertRedirect(route('fee-concessions.index'));

        $concession = $this->withTenant($college, fn () => FeeConcession::query()->firstOrFail());

        $this->assertSame(FeeConcession::TYPE_FIXED, $concession->type);
        $this->assertSame(1500.0, (float) $concession->value);
        $this->assertSame(1500.0, (float) $concession->amount);
        $this->assertSame(FeeConcession::STATUS_PENDING, $concession->status);
        $this->assertNull($concession->approved_by);
        $this->assertNull($concession->approved_at);
        $this->assertSame($user->id, $concession->created_by);

        // Pending concessions already reduce the payable amount.
        $this->assertSame(1500.0, (float) $this->ledgerOf($college, $assignment)['concession']);
    }

    public function test_a_percentage_concession_amount_is_computed_server_side(): void
    {
        [$college, $user, $assignment] = $this->concessionFixture('FCON2');

        $this->asCollege($college, $user)
            ->post(route('fee-concessions.store'), [
                'student_fee_assignment_id' => $assignment->id,
                'type' => FeeConcession::TYPE_PERCENTAGE,
                'value' => 10,
                // A forged amount is ignored: the browser never computes money.
                'amount' => 1,
            ])
            ->assertRedirect();

        $concession = $this->withTenant($college, fn () => FeeConcession::query()->firstOrFail());

        // 10% of 26,500 = 2,650.
        $this->assertSame(2650.0, (float) $concession->amount);
    }

    public function test_invalid_values_are_rejected(): void
    {
        [$college, $user, $assignment] = $this->concessionFixture('FCON3');

        foreach ([
            ['type' => FeeConcession::TYPE_PERCENTAGE, 'value' => 101],
            ['type' => FeeConcession::TYPE_PERCENTAGE, 'value' => -5],
            ['type' => FeeConcession::TYPE_FIXED, 'value' => -1],
        ] as $invalid) {
            $this->asCollege($college, $user)
                ->post(route('fee-concessions.store'), array_merge([
                    'student_fee_assignment_id' => $assignment->id,
                ], $invalid))
                ->assertSessionHasErrors('value');
        }

        $this->assertSame(0, $this->withTenant($college, fn () => FeeConcession::query()->count()));
    }

    public function test_a_concession_can_never_exceed_the_applicable_fee(): void
    {
        [$college, $user, $assignment] = $this->concessionFixture('FCON4');

        // More than the whole plan.
        $this->asCollege($college, $user)
            ->post(route('fee-concessions.store'), [
                'student_fee_assignment_id' => $assignment->id,
                'type' => FeeConcession::TYPE_FIXED,
                'value' => 30000,
            ])
            ->assertSessionHasErrors('value');

        // The first one is fine…
        $this->asCollege($college, $user)
            ->post(route('fee-concessions.store'), [
                'student_fee_assignment_id' => $assignment->id,
                'type' => FeeConcession::TYPE_FIXED,
                'value' => 20000,
            ])
            ->assertSessionHasNoErrors();

        // …but the second one tips the total over the applicable fee.
        $this->asCollege($college, $user)
            ->post(route('fee-concessions.store'), [
                'student_fee_assignment_id' => $assignment->id,
                'type' => FeeConcession::TYPE_FIXED,
                'value' => 7000,
            ])
            ->assertSessionHasErrors('value');

        $this->assertSame(1, $this->withTenant($college, fn () => FeeConcession::query()->count()));

        // A rejected concession frees the head-room again.
        $concession = $this->withTenant($college, fn () => FeeConcession::query()->firstOrFail());
        $this->withTenant($college, fn () => app(\App\Domain\Finance\Services\FeeConcessionService::class)->update($concession, [
            'status' => FeeConcession::STATUS_CANCELLED,
        ], $user));

        $this->asCollege($college, $user)
            ->post(route('fee-concessions.store'), [
                'student_fee_assignment_id' => $assignment->id,
                'type' => FeeConcession::TYPE_FIXED,
                'value' => 25000,
            ])
            ->assertSessionHasNoErrors();
    }

    public function test_approval_is_server_controlled_and_audited(): void
    {
        [$college, $user, $assignment] = $this->concessionFixture('FCON5');
        $concession = $this->withTenant($college, fn () => app(\App\Domain\Finance\Services\FeeConcessionService::class)->create($assignment, [
            'type' => FeeConcession::TYPE_FIXED,
            'value' => 2000,
        ], $user));

        // The create/update payload can never grant approval.
        $this->asCollege($college, $user)
            ->put(route('fee-concessions.update', $concession), ['status' => FeeConcession::STATUS_APPROVED, 'reason' => 'Please approve'])
            ->assertRedirect();

        $this->assertSame(FeeConcession::STATUS_PENDING, $concession->fresh()->status);
        $this->assertNull($concession->fresh()->approved_by);

        $this->asCollege($college, $user)->post(route('fee-concessions.approve', $concession))->assertRedirect();

        $concession->refresh();
        $this->assertSame(FeeConcession::STATUS_APPROVED, $concession->status);
        $this->assertSame($user->id, $concession->approved_by);
        $this->assertNotNull($concession->approved_at);
        $this->assertTrue($concession->isApproved());

        $actions = $this->withTenant($college, fn () => AuditLog::query()->pluck('action')->all());
        $this->assertContains('fee_concessions.created', $actions);
        $this->assertContains('fee_concessions.approved', $actions);
    }

    public function test_changing_the_amount_sends_an_approved_concession_back_to_pending(): void
    {
        [$college, $user, $assignment] = $this->concessionFixture('FCON6');
        $concession = $this->withTenant($college, fn () => app(\App\Domain\Finance\Services\FeeConcessionService::class)->create($assignment, [
            'type' => FeeConcession::TYPE_FIXED,
            'value' => 2000,
        ], $user));
        $this->withTenant($college, fn () => app(\App\Domain\Finance\Services\FeeConcessionService::class)->approve($concession, $user));

        $this->asCollege($college, $user)
            ->put(route('fee-concessions.update', $concession), ['type' => FeeConcession::TYPE_FIXED, 'value' => 3000])
            ->assertRedirect();

        $concession->refresh();
        $this->assertSame(3000.0, (float) $concession->amount);
        $this->assertSame(FeeConcession::STATUS_PENDING, $concession->status, 'An approval applies to one specific amount.');
        $this->assertNull($concession->approved_by);
        $this->assertNull($concession->approved_at);
    }

    public function test_a_rejected_or_cancelled_concession_cannot_be_approved(): void
    {
        [$college, $user, $assignment] = $this->concessionFixture('FCON7');
        $concession = $this->withTenant($college, fn () => app(\App\Domain\Finance\Services\FeeConcessionService::class)->create($assignment, [
            'type' => FeeConcession::TYPE_FIXED,
            'value' => 500,
            'status' => FeeConcession::STATUS_REJECTED,
        ], $user));

        $this->asCollege($college, $user)->post(route('fee-concessions.approve', $concession))->assertSessionHasErrors('status');
        $this->assertSame(FeeConcession::STATUS_REJECTED, $concession->fresh()->status);
    }

    public function test_approval_needs_the_approve_permission(): void
    {
        [$college, $user, $assignment] = $this->concessionFixture('FCON8');
        $concession = $this->withTenant($college, fn () => app(\App\Domain\Finance\Services\FeeConcessionService::class)->create($assignment, [
            'type' => FeeConcession::TYPE_FIXED,
            'value' => 500,
        ], $user));

        $clerk = $this->makeUserWithPermissions($college, ['fee_concessions.view', 'fee_concessions.update']);

        $this->asCollege($college, $clerk)->get(route('fee-concessions.index'))->assertOk();
        $this->asCollege($college, $clerk)->post(route('fee-concessions.approve', $concession))->assertForbidden();

        $stranger = $this->makeUserWithPermissions($college, ['students.view']);
        $this->asCollege($college, $stranger)->get(route('fee-concessions.index'))->assertForbidden();
        $this->asCollege($college, $stranger)
            ->post(route('fee-concessions.store'), [
                'student_fee_assignment_id' => $assignment->id,
                'type' => FeeConcession::TYPE_FIXED,
                'value' => 100,
            ])
            ->assertForbidden();
    }

    public function test_a_concession_is_deleted_softly_and_keeps_the_history(): void
    {
        [$college, $user, $assignment] = $this->concessionFixture('FCON9');
        $concession = $this->withTenant($college, fn () => app(\App\Domain\Finance\Services\FeeConcessionService::class)->create($assignment, [
            'type' => FeeConcession::TYPE_FIXED,
            'value' => 1000,
        ], $user));

        $this->asCollege($college, $user)->delete(route('fee-concessions.destroy', $concession))->assertRedirect(route('fee-concessions.index'));

        $this->assertSoftDeleted('fee_concessions', ['id' => $concession->id]);
        $this->assertSame(0.0, (float) $this->ledgerOf($college, $assignment)['concession']);
    }

    public function test_another_colleges_concession_can_never_be_reached(): void
    {
        [$college, $user, $assignment] = $this->concessionFixture('FCON10');
        $other = $this->makeCollege('FCON10X');
        $otherCtx = $this->makeFinanceContext($other, 'FCON10X');
        $otherStructure = $this->makeFeeStructure($other, $otherCtx);
        $otherFixture = $this->makeFinanceEnrollment($other, $otherCtx, 'FCON10X');
        $foreignAssignment = $this->assignFeeStructure($other, $user, $otherFixture['enrollment'], $otherStructure);
        $foreign = $this->withTenant($other, fn () => app(\App\Domain\Finance\Services\FeeConcessionService::class)->create($foreignAssignment, [
            'type' => FeeConcession::TYPE_FIXED,
            'value' => 900,
        ], $user));

        $own = $this->withTenant($college, fn () => app(\App\Domain\Finance\Services\FeeConcessionService::class)->create($assignment, [
            'type' => FeeConcession::TYPE_FIXED,
            'value' => 100,
        ], $user));

        $this->asCollege($college, $user)
            ->get(route('fee-concessions.index'))
            ->assertOk()
            ->assertSee(number_format(100, 2));

        $this->asCollege($college, $user)->get(route('fee-concessions.edit', $foreign))->assertNotFound();
        $this->asCollege($college, $user)->put(route('fee-concessions.update', $foreign), ['value' => 5])->assertNotFound();
        $this->asCollege($college, $user)->post(route('fee-concessions.approve', $foreign))->assertNotFound();
        $this->asCollege($college, $user)->delete(route('fee-concessions.destroy', $foreign))->assertNotFound();

        $this->assertSame(FeeConcession::STATUS_PENDING, $foreign->fresh()->status);
        $this->assertNull($foreign->fresh()->deleted_at);
        $this->assertSame(100.0, (float) $own->fresh()->amount);

        // A concession can never be attached to a foreign assignment id.
        $this->asCollege($college, $user)
            ->post(route('fee-concessions.store'), [
                'student_fee_assignment_id' => $foreignAssignment->id,
                'type' => FeeConcession::TYPE_FIXED,
                'value' => 50,
            ])
            ->assertSessionHasErrors('student_fee_assignment_id');
    }

    public function test_a_cancelled_assignment_cannot_receive_a_concession(): void
    {
        [$college, $user, $assignment] = $this->concessionFixture('FCON11', ['student_fee_assignments.update']);

        $this->asCollege($college, $user)
            ->put(route('student-fee-assignments.update', $assignment), ['status' => StudentFeeAssignment::STATUS_CANCELLED])
            ->assertRedirect();

        $this->asCollege($college, $user)
            ->post(route('fee-concessions.store'), [
                'student_fee_assignment_id' => $assignment->id,
                'type' => FeeConcession::TYPE_FIXED,
                'value' => 50,
            ])
            ->assertSessionHasErrors('student_fee_assignment_id');
    }
}
