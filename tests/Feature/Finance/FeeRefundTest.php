<?php

namespace Tests\Feature\Finance;

use App\Models\AuditLog;
use App\Models\FeePayment;
use App\Models\FeeRefund;
use App\Models\StudentFeeAssignment;
use Tests\TestCase;

/**
 * Finance / Fees — Refunds.
 *
 * A refund can only ever give back money that was actually collected, so these
 * tests pin the limits: the refund is always tied to a completed payment, it can
 * never exceed what is left refundable on that payment, a cancelled payment is
 * never refundable, approval and processing are separate audited actions, and
 * nothing here is ever deleted.
 */
class FeeRefundTest extends TestCase
{
    use FeeStructureTestHelpers;

    /**
     * @return array{0: \App\Models\College, 1: \App\Models\User, 2: FeePayment}
     */
    private function refundFixture(string $code, array $extra = []): array
    {
        $college = $this->makeCollege($code);
        $user = $this->makeUserWithPermissions($college, array_merge([
            'refunds.view',
            'refunds.create',
            'refunds.update',
            'refunds.approve',
            'student_fee_assignments.create',
            'fee_collections.create',
            'fee_collections.view',
            'fee_collections.update',
        ], $extra));
        $ctx = $this->makeFinanceContext($college, $code);
        $structure = $this->makeFeeStructure($college, $ctx);
        $fixture = $this->makeFinanceEnrollment($college, $ctx, $code);
        $assignment = $this->assignFeeStructure($college, $user, $fixture['enrollment'], $structure);

        return [$college, $user, $this->collectFee($college, $user, $assignment, 10000)];
    }

    public function test_a_refund_is_recorded_against_a_completed_payment(): void
    {
        [$college, $user, $payment] = $this->refundFixture('FREF1');

        $this->asCollege($college, $user)
            ->post(route('refunds.store'), [
                'fee_payment_id' => $payment->id,
                'refund_date' => '2026-08-20',
                'amount' => 2500,
                'reason' => 'Withdrawal adjustment',
                'refund_number' => 'REF-FORGED-1',
                'status' => FeeRefund::STATUS_PROCESSED,
                'approved_by' => 999,
            ])
            ->assertRedirect(route('refunds.index'));

        $refund = $this->withTenant($college, fn () => FeeRefund::query()->firstOrFail());

        $this->assertSame($college->id, $refund->college_id);
        $this->assertSame($payment->id, $refund->fee_payment_id);
        $this->assertNotSame('REF-FORGED-1', $refund->refund_number);
        $this->assertMatchesRegularExpression('/^REF-\d{4}-\d{4}$/', $refund->refund_number);
        $this->assertSame(2500.0, (float) $refund->amount);
        $this->assertSame(FeeRefund::STATUS_PENDING, $refund->status, 'A new refund is never born approved or processed.');
        $this->assertNull($refund->approved_by);
        $this->assertNull($refund->processed_by);
        $this->assertSame($user->id, $refund->created_by);
    }

    public function test_a_refund_can_never_exceed_the_refundable_amount_of_its_payment(): void
    {
        [$college, $user, $payment] = $this->refundFixture('FREF2');

        $this->asCollege($college, $user)
            ->post(route('refunds.store'), [
                'fee_payment_id' => $payment->id,
                'refund_date' => '2026-08-20',
                'amount' => 10000.01,
            ])
            ->assertSessionHasErrors('amount');

        $this->asCollege($college, $user)
            ->post(route('refunds.store'), [
                'fee_payment_id' => $payment->id,
                'refund_date' => '2026-08-20',
                'amount' => 6000,
            ])
            ->assertSessionHasNoErrors();

        // The remaining refundable amount has shrunk accordingly.
        $this->asCollege($college, $user)
            ->post(route('refunds.store'), [
                'fee_payment_id' => $payment->id,
                'refund_date' => '2026-08-21',
                'amount' => 4000.01,
            ])
            ->assertSessionHasErrors('amount');

        $this->asCollege($college, $user)
            ->post(route('refunds.store'), [
                'fee_payment_id' => $payment->id,
                'refund_date' => '2026-08-21',
                'amount' => 4000,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, $this->withTenant($college, fn () => FeeRefund::query()->count()));

        // With the whole payment refunded, nothing is left to give back.
        $this->asCollege($college, $user)
            ->post(route('refunds.store'), [
                'fee_payment_id' => $payment->id,
                'refund_date' => '2026-08-22',
                'amount' => 1,
            ])
            ->assertSessionHasErrors('amount');
    }

    public function test_a_rejected_refund_frees_the_refundable_amount_again(): void
    {
        [$college, $user, $payment] = $this->refundFixture('FREF3');
        $refund = $this->withTenant($college, fn () => app(\App\Domain\Finance\Services\FeeRefundService::class)->create($payment, [
            'refund_date' => '2026-08-20',
            'amount' => 8000,
            'reason' => 'Requested',
        ], $user));

        $this->withTenant($college, fn () => app(\App\Domain\Finance\Services\FeeRefundService::class)->update($refund, [
            'status' => FeeRefund::STATUS_REJECTED,
        ], $user));

        $this->asCollege($college, $user)
            ->post(route('refunds.store'), [
                'fee_payment_id' => $payment->id,
                'refund_date' => '2026-08-21',
                'amount' => 10000,
            ])
            ->assertSessionHasNoErrors();
    }

    public function test_a_cancelled_payment_is_never_refundable(): void
    {
        [$college, $user, $payment] = $this->refundFixture('FREF4');
        $this->asCollege($college, $user)->post(route('fee-collections.cancel', $payment), ['cancellation_reason' => 'Bounced'])->assertRedirect();

        $this->asCollege($college, $user)
            ->post(route('refunds.store'), [
                'fee_payment_id' => $payment->id,
                'refund_date' => '2026-08-20',
                'amount' => 100,
            ])
            ->assertSessionHasErrors('fee_payment_id');

        $this->assertSame(0, $this->withTenant($college, fn () => FeeRefund::query()->count()));

        // …and a refund raised before the reversal cannot be approved afterwards.
        $assignment = $this->withTenant($college, fn () => StudentFeeAssignment::query()->firstOrFail());
        $paymentTwo = $this->collectFee($college, $user, $assignment, 1000, ['payment_date' => '2026-08-16']);
        $refund = $this->withTenant($college, fn () => app(\App\Domain\Finance\Services\FeeRefundService::class)->create($paymentTwo, [
            'refund_date' => '2026-08-17',
            'amount' => 100,
        ], $user));

        $this->asCollege($college, $user)->post(route('fee-collections.cancel', $paymentTwo))->assertRedirect();
        $this->asCollege($college, $user)->post(route('refunds.approve', $refund))->assertSessionHasErrors('fee_payment_id');
    }

    public function test_only_an_approved_refund_can_be_processed(): void
    {
        [$college, $user, $payment] = $this->refundFixture('FREF5');
        $refund = $this->withTenant($college, fn () => app(\App\Domain\Finance\Services\FeeRefundService::class)->create($payment, [
            'refund_date' => '2026-08-20',
            'amount' => 1000,
        ], $user));

        $this->asCollege($college, $user)->post(route('refunds.process', $refund))->assertSessionHasErrors('status');

        $this->asCollege($college, $user)->post(route('refunds.approve', $refund))->assertRedirect();
        $refund->refresh();
        $this->assertSame(FeeRefund::STATUS_APPROVED, $refund->status);
        $this->assertSame($user->id, $refund->approved_by);
        $this->assertNotNull($refund->approved_at);

        $this->asCollege($college, $user)->post(route('refunds.process', $refund))->assertRedirect();
        $refund->refresh();
        $this->assertSame(FeeRefund::STATUS_PROCESSED, $refund->status);
        $this->assertSame($user->id, $refund->processed_by);
        $this->assertNotNull($refund->processed_at);

        $actions = $this->withTenant($college, fn () => AuditLog::query()->pluck('action')->all());
        $this->assertContains('fee_refunds.created', $actions);
        $this->assertContains('fee_refunds.approved', $actions);
        $this->assertContains('fee_refunds.processed', $actions);
    }

    public function test_editing_the_amount_sends_the_refund_back_to_pending(): void
    {
        [$college, $user, $payment] = $this->refundFixture('FREF6');
        $refund = $this->withTenant($college, fn () => app(\App\Domain\Finance\Services\FeeRefundService::class)->create($payment, [
            'refund_date' => '2026-08-20',
            'amount' => 1000,
        ], $user));
        $this->withTenant($college, fn () => app(\App\Domain\Finance\Services\FeeRefundService::class)->approve($refund, $user));

        $this->asCollege($college, $user)
            ->put(route('refunds.update', $refund), ['amount' => 2000])
            ->assertRedirect();

        $refund->refresh();
        $this->assertSame(2000.0, (float) $refund->amount);
        $this->assertSame(FeeRefund::STATUS_PENDING, $refund->status);
        $this->assertNull($refund->approved_by);
        $this->assertNull($refund->approved_at);
    }

    public function test_a_processed_refund_can_no_longer_be_edited(): void
    {
        [$college, $user, $payment] = $this->refundFixture('FREF7');
        $refund = $this->withTenant($college, fn () => app(\App\Domain\Finance\Services\FeeRefundService::class)->create($payment, [
            'refund_date' => '2026-08-20',
            'amount' => 1000,
        ], $user));
        $service = app(\App\Domain\Finance\Services\FeeRefundService::class);
        $this->withTenant($college, fn () => $service->approve($refund, $user));
        $this->withTenant($college, fn () => $service->process($refund->fresh(), $user));

        $this->asCollege($college, $user)
            ->put(route('refunds.update', $refund), ['reason' => 'Too late'])
            ->assertSessionHasErrors('status');

        $this->assertSame(FeeRefund::STATUS_PROCESSED, $refund->fresh()->status);
    }

    public function test_a_refund_moves_the_outstanding_balance_and_the_net_collected_amount(): void
    {
        [$college, $user, $payment] = $this->refundFixture('FREF8');
        $assignment = $this->withTenant($college, fn () => StudentFeeAssignment::query()->firstOrFail());

        $before = $this->ledgerOf($college, $assignment);
        $this->assertSame(10000.0, (float) $before['net_collected']);
        $this->assertSame(16500.0, (float) $before['outstanding']);

        $this->withTenant($college, fn () => app(\App\Domain\Finance\Services\FeeRefundService::class)->create($payment, [
            'refund_date' => '2026-08-20',
            'amount' => 4000,
        ], $user));

        $after = $this->ledgerOf($college, $assignment);
        $this->assertSame(6000.0, (float) $after['net_collected']);
        $this->assertSame(20500.0, (float) $after['outstanding']);
        $this->assertSame(4000.0, (float) $after['refunded']);
    }

    public function test_another_colleges_payment_can_never_be_refunded(): void
    {
        [$college, $user] = $this->refundFixture('FREF9');
        $other = $this->makeCollege('FREF9X');
        $otherCtx = $this->makeFinanceContext($other, 'FREF9X');
        $otherStructure = $this->makeFeeStructure($other, $otherCtx);
        $otherFixture = $this->makeFinanceEnrollment($other, $otherCtx, 'FREF9X');
        $otherAssignment = $this->assignFeeStructure($other, $user, $otherFixture['enrollment'], $otherStructure);
        $foreignPayment = $this->collectFee($other, $user, $otherAssignment, 3000);
        $foreignRefund = $this->withTenant($other, fn () => app(\App\Domain\Finance\Services\FeeRefundService::class)->create($foreignPayment, [
            'refund_date' => '2026-08-20',
            'amount' => 500,
        ], $user));

        $this->asCollege($college, $user)
            ->post(route('refunds.store'), [
                'fee_payment_id' => $foreignPayment->id,
                'refund_date' => '2026-08-20',
                'amount' => 100,
            ])
            ->assertSessionHasErrors('fee_payment_id');

        $this->asCollege($college, $user)->get(route('refunds.index'))->assertOk()->assertDontSee($foreignRefund->refund_number);
        $this->asCollege($college, $user)->get(route('refunds.edit', $foreignRefund))->assertNotFound();
        $this->asCollege($college, $user)->put(route('refunds.update', $foreignRefund), ['amount' => 10])->assertNotFound();
        $this->asCollege($college, $user)->post(route('refunds.approve', $foreignRefund))->assertNotFound();
        $this->asCollege($college, $user)->post(route('refunds.process', $foreignRefund))->assertNotFound();

        $this->assertSame(FeeRefund::STATUS_PENDING, $foreignRefund->fresh()->status);
    }

    public function test_there_is_no_way_to_delete_a_refund(): void
    {
        [$college, $user, $payment] = $this->refundFixture('FREF10');
        $refund = $this->withTenant($college, fn () => app(\App\Domain\Finance\Services\FeeRefundService::class)->create($payment, [
            'refund_date' => '2026-08-20',
            'amount' => 500,
        ], $user));

        $this->asCollege($college, $user)->delete(route('refunds.index').'/'.$refund->id)->assertMethodNotAllowed();
        $this->assertNotNull($refund->fresh());
    }

    public function test_the_module_requires_its_permissions(): void
    {
        [$college, , $payment] = $this->refundFixture('FREF11');
        $stranger = $this->makeUserWithPermissions($college, ['students.view']);

        $this->asCollege($college, $stranger)->get(route('refunds.index'))->assertForbidden();
        $this->asCollege($college, $stranger)
            ->post(route('refunds.store'), [
                'fee_payment_id' => $payment->id,
                'refund_date' => '2026-08-20',
                'amount' => 100,
            ])
            ->assertForbidden();

        // Raising a refund does not imply approving it.
        $clerk = $this->makeUserWithPermissions($college, ['refunds.view', 'refunds.create', 'refunds.update']);
        $refund = $this->withTenant($college, fn () => app(\App\Domain\Finance\Services\FeeRefundService::class)->create($payment, [
            'refund_date' => '2026-08-20',
            'amount' => 100,
        ], $clerk));
        $this->asCollege($college, $clerk)->post(route('refunds.approve', $refund))->assertForbidden();
    }
}
