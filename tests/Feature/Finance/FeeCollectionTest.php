<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Support\FeeLedger;
use App\Models\FeePayment;
use App\Models\StudentFeeAssignment;
use Tests\TestCase;

/**
 * Finance / Fees — Fee Collection.
 *
 * The collection register is the only place money enters the system, so these
 * tests concentrate on the monetary invariants: the amount is always positive and
 * never exceeds the live outstanding balance, payment numbers are generated
 * server-side, cancelled payments stop counting, edits cannot rewrite an amount,
 * and no other college's money is ever reachable.
 */
class FeeCollectionTest extends TestCase
{
    use FeeStructureTestHelpers;

    /**
     * @return array{0: \App\Models\College, 1: \App\Models\User, 2: StudentFeeAssignment}
     */
    private function assignmentFixture(string $code, float $tuition = 25000, float $library = 1500): array
    {
        $college = $this->makeCollege($code);
        $user = $this->makeUserWithPermissions($college, [
            'fee_collections.view',
            'fee_collections.create',
            'fee_collections.update',
            'fee_collections.delete',
            // Some cases cancel the assignment to check that a cancelled
            // assignment can no longer be collected against.
            'student_fee_assignments.update',
        ]);
        $ctx = $this->makeFinanceContext($college, $code);
        $structure = $this->makeFeeStructure($college, $ctx, [], [
            ['name' => 'Tuition Fee', 'amount' => $tuition, 'sort_order' => 1],
            ['name' => 'Library Fee', 'amount' => $library, 'sort_order' => 2],
        ]);
        $fixture = $this->makeFinanceEnrollment($college, $ctx, $code);
        $assignment = $this->assignFeeStructure($college, $user, $fixture['enrollment'], $structure);

        return [$college, $user, $assignment];
    }

    public function test_a_collection_is_recorded_with_a_server_generated_payment_number(): void
    {
        [$college, $user, $assignment] = $this->assignmentFixture('FCOL1');

        $this->asCollege($college, $user)
            ->post(route('fee-collections.store'), [
                'student_fee_assignment_id' => $assignment->id,
                'payment_date' => '2026-08-15',
                'payment_mode' => FeePayment::MODE_CASH,
                'amount' => 10000,
                'payment_number' => 'PAY-FORGED-0001',
                'collected_by' => 4242,
                'status' => FeePayment::STATUS_CANCELLED,
                'remarks' => 'First instalment',
            ])
            ->assertRedirect(route('fee-collections.index'));

        $payment = $this->withTenant($college, fn () => FeePayment::query()->firstOrFail());

        $this->assertNotSame('PAY-FORGED-0001', $payment->payment_number);
        $this->assertMatchesRegularExpression('/^PAY-\d{4}-\d{4}$/', $payment->payment_number);
        $this->assertSame(FeePayment::STATUS_COMPLETED, $payment->status);
        $this->assertSame($user->id, $payment->collected_by);
        $this->assertSame($college->id, $payment->college_id);
        $this->assertSame($assignment->student_enrollment_id, $payment->student_enrollment_id);
        $this->assertSame($assignment->fee_structure_id, $payment->fee_structure_id);
        $this->assertSame(10000.0, (float) $payment->amount);

        $ledger = $this->ledgerOf($college, $assignment);
        $this->assertSame(10000.0, (float) $ledger['paid']);
        $this->assertSame(16500.0, (float) $ledger['outstanding']);
        $this->assertSame(FeeLedger::STATUS_PARTIAL, $ledger['status']);
    }

    public function test_payment_numbers_are_unique_per_college(): void
    {
        [$college, $user, $assignment] = $this->assignmentFixture('FCOL2');

        $first = $this->collectFee($college, $user, $assignment, 5000, ['payment_date' => '2026-08-15']);
        $second = $this->collectFee($college, $user, $assignment, 5000, ['payment_date' => '2026-08-16']);

        $this->assertNotSame($first->payment_number, $second->payment_number);
        $this->assertSame(2, $this->withTenant($college, fn () => FeePayment::query()->count()));
    }

    public function test_an_amount_above_the_outstanding_balance_is_rejected(): void
    {
        [$college, $user, $assignment] = $this->assignmentFixture('FCOL3');

        $this->asCollege($college, $user)
            ->post(route('fee-collections.store'), [
                'student_fee_assignment_id' => $assignment->id,
                'payment_date' => '2026-08-15',
                'payment_mode' => FeePayment::MODE_CASH,
                'amount' => 26500.01,
            ])
            ->assertSessionHasErrors('amount');

        $this->assertSame(0, $this->withTenant($college, fn () => FeePayment::query()->count()));

        // Exactly the outstanding balance is accepted…
        $this->collectFee($college, $user, $assignment, 26500);
        $this->assertSame(FeeLedger::STATUS_PAID, $this->ledgerOf($college, $assignment)['status']);

        // …and anything beyond the paid-up plan is refused.
        $this->asCollege($college, $user)
            ->post(route('fee-collections.store'), [
                'student_fee_assignment_id' => $assignment->id,
                'payment_date' => '2026-08-16',
                'payment_mode' => FeePayment::MODE_CASH,
                'amount' => 1,
            ])
            ->assertSessionHasErrors('amount');
    }

    public function test_a_non_positive_amount_is_rejected(): void
    {
        [$college, $user, $assignment] = $this->assignmentFixture('FCOL4');

        foreach ([0, -500] as $amount) {
            $this->asCollege($college, $user)
                ->post(route('fee-collections.store'), [
                    'student_fee_assignment_id' => $assignment->id,
                    'payment_date' => '2026-08-15',
                    'payment_mode' => FeePayment::MODE_CASH,
                    'amount' => $amount,
                ])
                ->assertSessionHasErrors('amount');
        }

        $this->assertSame(0, $this->withTenant($college, fn () => FeePayment::query()->count()));
    }

    public function test_concessions_and_refunds_move_the_collection_ceiling(): void
    {
        [$college, $user, $assignment] = $this->assignmentFixture('FCOL5');

        $concession = $this->withTenant($college, fn () => app(\App\Domain\Finance\Services\FeeConcessionService::class)->create($assignment, [
            'type' => 'fixed',
            'value' => 6500,
            'reason' => 'Merit',
        ], $user));

        $ledger = $this->ledgerOf($college, $assignment);
        $this->assertSame(6500.0, (float) $ledger['concession']);
        $this->assertSame(20000.0, (float) $ledger['outstanding']);

        // The concession lowers what may be collected.
        $this->asCollege($college, $user)
            ->post(route('fee-collections.store'), [
                'student_fee_assignment_id' => $assignment->id,
                'payment_date' => '2026-08-15',
                'payment_mode' => FeePayment::MODE_CASH,
                'amount' => 20000.01,
            ])
            ->assertSessionHasErrors('amount');

        $payment = $this->collectFee($college, $user, $assignment, 20000);
        $this->assertSame(FeeLedger::STATUS_PAID, $this->ledgerOf($college, $assignment)['status']);

        // A refund re-opens exactly the refunded amount.
        $this->withTenant($college, fn () => app(\App\Domain\Finance\Services\FeeRefundService::class)->create($payment, [
            'refund_date' => '2026-08-20',
            'amount' => 5000,
            'reason' => 'Partial withdrawal',
        ], $user));

        $ledger = $this->ledgerOf($college, $assignment);
        $this->assertSame(5000.0, (float) $ledger['refunded']);
        $this->assertSame(15000.0, (float) $ledger['net_collected']);
        $this->assertSame(5000.0, (float) $ledger['outstanding']);
        $this->assertSame(FeeLedger::STATUS_PARTIAL, $ledger['status']);

        // …but never more than the refunded amount.
        $this->asCollege($college, $user)
            ->post(route('fee-collections.store'), [
                'student_fee_assignment_id' => $assignment->id,
                'payment_date' => '2026-08-21',
                'payment_mode' => FeePayment::MODE_CASH,
                'amount' => 5000.01,
            ])
            ->assertSessionHasErrors('amount');

        $this->assertNotNull($concession->id);
    }

    public function test_a_duplicate_reference_of_the_same_amount_and_date_is_rejected(): void
    {
        [$college, $user, $assignment] = $this->assignmentFixture('FCOL6');

        $payload = [
            'student_fee_assignment_id' => $assignment->id,
            'payment_date' => '2026-08-15',
            'payment_mode' => FeePayment::MODE_CHEQUE,
            'amount' => 5000,
            'reference_number' => 'CHQ-99120',
        ];

        $this->asCollege($college, $user)->post(route('fee-collections.store'), $payload)->assertRedirect();

        // The same bank reference, amount and date is a re-submitted form.
        $this->asCollege($college, $user)
            ->post(route('fee-collections.store'), $payload)
            ->assertSessionHasErrors('reference_number');

        $this->assertSame(1, $this->withTenant($college, fn () => FeePayment::query()->count()));

        // Another date (or reference, or mode) is a genuine second collection.
        $this->asCollege($college, $user)
            ->post(route('fee-collections.store'), array_merge($payload, ['payment_date' => '2026-08-16']))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, $this->withTenant($college, fn () => FeePayment::query()->count()));
    }

    public function test_a_cancelled_payment_stops_counting_towards_the_collected_amount(): void
    {
        [$college, $user, $assignment] = $this->assignmentFixture('FCOL7');
        $payment = $this->collectFee($college, $user, $assignment, 8000);

        $this->asCollege($college, $user)
            ->post(route('fee-collections.cancel', $payment), ['cancellation_reason' => 'Cheque bounced'])
            ->assertRedirect(route('fee-collections.index'));

        $payment->refresh();
        $this->assertSame(FeePayment::STATUS_CANCELLED, $payment->status);
        $this->assertSame($user->id, $payment->cancelled_by);
        $this->assertNotNull($payment->cancelled_at);
        $this->assertSame('Cheque bounced', $payment->cancellation_reason);
        // Reversal keeps the row (and the amount) for the audit trail.
        $this->assertSame(8000.0, (float) $payment->amount);

        $ledger = $this->ledgerOf($college, $assignment);
        $this->assertSame(0.0, (float) $ledger['paid']);
        $this->assertSame(26500.0, (float) $ledger['outstanding']);
        $this->assertSame(FeeLedger::STATUS_DUE, $ledger['status']);

        // A cancelled payment cannot be cancelled twice, nor edited.
        $this->asCollege($college, $user)->post(route('fee-collections.cancel', $payment))->assertSessionHasErrors('status');
        $this->asCollege($college, $user)
            ->put(route('fee-collections.update', $payment), ['remarks' => 'Nope'])
            ->assertSessionHasErrors('payment_date');
    }

    public function test_the_collected_amount_cannot_be_rewritten_by_an_update(): void
    {
        [$college, $user, $assignment] = $this->assignmentFixture('FCOL8');
        $payment = $this->collectFee($college, $user, $assignment, 4000);

        $this->asCollege($college, $user)
            ->put(route('fee-collections.update', $payment), [
                'payment_date' => '2026-09-01',
                'payment_mode' => FeePayment::MODE_UPI,
                'reference_number' => 'UPI-ABC',
                'remarks' => 'Corrected mode',
                'amount' => 99999,
            ])
            ->assertRedirect(route('fee-collections.index'));

        $payment->refresh();
        $this->assertSame(4000.0, (float) $payment->amount, 'A recorded amount is immutable.');
        $this->assertSame(FeePayment::MODE_UPI, $payment->payment_mode);
        $this->assertSame('Corrected mode', $payment->remarks);
        $this->assertSame('2026-09-01', $payment->payment_date->format('Y-m-d'));
    }

    public function test_a_payment_with_refunds_cannot_be_cancelled_or_deleted(): void
    {
        [$college, $user, $assignment] = $this->assignmentFixture('FCOL9');
        $payment = $this->collectFee($college, $user, $assignment, 6000);

        $this->withTenant($college, fn () => app(\App\Domain\Finance\Services\FeeRefundService::class)->create($payment, [
            'refund_date' => '2026-08-20',
            'amount' => 1000,
            'reason' => 'Adjustment',
        ], $user));

        $this->asCollege($college, $user)->post(route('fee-collections.cancel', $payment))->assertSessionHasErrors('status');
        $this->asCollege($college, $user)->delete(route('fee-collections.destroy', $payment))->assertSessionHasErrors('status');

        $this->assertSame(FeePayment::STATUS_COMPLETED, $payment->fresh()->status);
        $this->assertNull($payment->fresh()->deleted_at);
    }

    public function test_a_collection_can_only_be_made_against_a_payable_assignment_of_the_same_college(): void
    {
        [$college, $user, $assignment] = $this->assignmentFixture('FCOL10');
        $other = $this->makeCollege('FCOL10X');
        $otherCtx = $this->makeFinanceContext($other, 'FCOL10X');
        $otherStructure = $this->makeFeeStructure($other, $otherCtx);
        $otherFixture = $this->makeFinanceEnrollment($other, $otherCtx, 'FCOL10X');
        $foreign = $this->assignFeeStructure($other, $user, $otherFixture['enrollment'], $otherStructure);

        // Cross-tenant assignment id…
        $this->asCollege($college, $user)
            ->post(route('fee-collections.store'), [
                'student_fee_assignment_id' => $foreign->id,
                'payment_date' => '2026-08-15',
                'payment_mode' => FeePayment::MODE_CASH,
                'amount' => 100,
            ])
            ->assertSessionHasErrors('student_fee_assignment_id');

        // …and a cancelled assignment in the same college.
        $this->asCollege($college, $user)
            ->put(route('student-fee-assignments.update', $assignment), ['status' => StudentFeeAssignment::STATUS_CANCELLED])
            ->assertRedirect();

        $this->asCollege($college, $user)
            ->post(route('fee-collections.store'), [
                'student_fee_assignment_id' => $assignment->id,
                'payment_date' => '2026-08-15',
                'payment_mode' => FeePayment::MODE_CASH,
                'amount' => 100,
            ])
            ->assertSessionHasErrors('student_fee_assignment_id');

        $this->assertSame(0, $this->withTenant($college, fn () => FeePayment::query()->count()));
        $this->assertSame(0, $this->withTenant($other, fn () => FeePayment::query()->count()));
    }

    public function test_another_colleges_payments_can_never_be_reached(): void
    {
        [$college, $user, $assignment] = $this->assignmentFixture('FCOL11');
        $other = $this->makeCollege('FCOL11X');
        $otherCtx = $this->makeFinanceContext($other, 'FCOL11X');
        $otherStructure = $this->makeFeeStructure($other, $otherCtx);
        $otherFixture = $this->makeFinanceEnrollment($other, $otherCtx, 'FCOL11X');
        $otherAssignment = $this->assignFeeStructure($other, $user, $otherFixture['enrollment'], $otherStructure);
        $foreignPayment = $this->collectFee($other, $user, $otherAssignment, 500);
        // A second foreign collection: payment numbers are per college, so the
        // first one of each college is PAY-…-0001 and proves nothing.
        $foreignSecond = $this->collectFee($other, $user, $otherAssignment, 600);

        $ownPayment = $this->collectFee($college, $user, $assignment, 1000);

        $this->assertNotSame($ownPayment->payment_number, $foreignSecond->payment_number);

        $this->asCollege($college, $user)
            ->get(route('fee-collections.index'))
            ->assertOk()
            ->assertSee($ownPayment->payment_number)
            ->assertDontSee($foreignSecond->payment_number);

        $this->asCollege($college, $user)->get(route('fee-collections.edit', $foreignPayment))->assertNotFound();
        $this->asCollege($college, $user)
            ->put(route('fee-collections.update', $foreignPayment), ['remarks' => 'nope'])
            ->assertNotFound();
        $this->asCollege($college, $user)->post(route('fee-collections.cancel', $foreignPayment))->assertNotFound();
        $this->asCollege($college, $user)->delete(route('fee-collections.destroy', $foreignPayment))->assertNotFound();

        $this->assertSame(FeePayment::STATUS_COMPLETED, $foreignPayment->fresh()->status);
        $this->assertNull($foreignPayment->fresh()->deleted_at);
    }

    public function test_a_deleted_payment_stops_counting_towards_the_collected_amount(): void
    {
        [$college, $user, $assignment] = $this->assignmentFixture('FCOL12');
        $payment = $this->collectFee($college, $user, $assignment, 3000);

        $this->asCollege($college, $user)->delete(route('fee-collections.destroy', $payment))->assertRedirect(route('fee-collections.index'));

        $this->assertSoftDeleted('fee_payments', ['id' => $payment->id]);
        $this->assertSame(0.0, (float) $this->ledgerOf($college, $assignment)['paid']);
    }

    public function test_the_module_requires_its_permissions(): void
    {
        [$college, , $assignment] = $this->assignmentFixture('FCOL13');
        $stranger = $this->makeUserWithPermissions($college, ['students.view']);
        $payment = $this->collectFee($college, $this->makeUserWithPermissions($college, ['fee_collections.create']), $assignment, 100);

        $this->asCollege($college, $stranger)->get(route('fee-collections.index'))->assertForbidden();
        $this->asCollege($college, $stranger)
            ->post(route('fee-collections.store'), [
                'student_fee_assignment_id' => $assignment->id,
                'payment_date' => '2026-08-15',
                'payment_mode' => FeePayment::MODE_CASH,
                'amount' => 100,
            ])
            ->assertForbidden();
        $this->asCollege($college, $stranger)->post(route('fee-collections.cancel', $payment))->assertForbidden();
        $this->asCollege($college, $stranger)->delete(route('fee-collections.destroy', $payment))->assertForbidden();
    }
}
