<?php

namespace Tests\Feature\Finance;

use App\Models\FeePayment;
use App\Models\FeeReceipt;
use Tests\TestCase;

/**
 * Finance / Fees — Receipts.
 *
 * A receipt is a printable projection of a successful collection: it has no
 * table, no second amount and no second numbering series. The guards that matter
 * are therefore: only completed payments are ever listed or rendered (a reversed
 * payment must never produce a receipt), the receipt number is the payment's own
 * server-generated number, and viewing/printing are separate permissions.
 */
class FeeReceiptTest extends TestCase
{
    use FeeStructureTestHelpers;

    /**
     * @return array{0: \App\Models\College, 1: \App\Models\User, 2: FeePayment}
     */
    private function receiptFixture(string $code, array $permissions = ['receipts.view', 'receipts.print']): array
    {
        $college = $this->makeCollege($code);
        $user = $this->makeUserWithPermissions($college, array_merge($permissions, [
            'student_fee_assignments.create',
            'fee_collections.create',
        ]));
        $ctx = $this->makeFinanceContext($college, $code);
        $structure = $this->makeFeeStructure($college, $ctx);
        $fixture = $this->makeFinanceEnrollment($college, $ctx, $code);
        $assignment = $this->assignFeeStructure($college, $user, $fixture['enrollment'], $structure);

        return [$college, $user, $this->collectFee($college, $user, $assignment, 10000, [
            'payment_mode' => FeePayment::MODE_CHEQUE,
            'reference_number' => 'CHQ-'.strtoupper(substr($code, 0, 6)),
        ])];
    }

    public function test_a_receipt_is_rendered_for_a_successful_collection(): void
    {
        [$college, $user, $payment] = $this->receiptFixture('FREC1');

        $this->asCollege($college, $user)
            ->get(route('receipts.show', $payment))
            ->assertOk()
            ->assertSee($payment->payment_number)
            ->assertSee('10,000.00')
            ->assertSee('Fee Receipt')
            ->assertSee($college->name);
    }

    public function test_the_receipt_number_is_the_payment_number(): void
    {
        [, $user, $payment] = $this->receiptFixture('FREC2');

        $receipt = FeeReceipt::fromPayment($payment);

        $this->assertSame($payment->payment_number, $receipt->number());
        $this->assertSame($payment->id, $receipt->getKey());

        // Nothing else in the database pretends to be a receipt store.
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('receipts'));
    }

    public function test_a_cancelled_payment_never_produces_a_receipt(): void
    {
        [$college, $user, $payment] = $this->receiptFixture('FREC3', ['receipts.view', 'receipts.print', 'fee_collections.update']);

        $this->asCollege($college, $user)->post(route('fee-collections.cancel', $payment), ['cancellation_reason' => 'Reversed'])->assertRedirect();

        $this->asCollege($college, $user)->get(route('receipts.show', $payment))->assertForbidden();
        $this->asCollege($college, $user)->get(route('receipts.print', $payment))->assertForbidden();

        $this->asCollege($college, $user)
            ->get(route('receipts.index'))
            ->assertOk()
            ->assertDontSee($payment->payment_number);
    }

    public function test_printing_is_a_separate_permission(): void
    {
        [$college, $user, $payment] = $this->receiptFixture('FREC4', ['receipts.view']);

        $this->asCollege($college, $user)->get(route('receipts.show', $payment))->assertOk();
        $this->asCollege($college, $user)->get(route('receipts.print', $payment))->assertForbidden();
    }

    public function test_the_print_page_is_print_friendly(): void
    {
        [$college, $user, $payment] = $this->receiptFixture('FREC5');

        $this->asCollege($college, $user)
            ->get(route('receipts.print', $payment))
            ->assertOk()
            ->assertSee('Print now')
            ->assertSee('no-print', false)
            ->assertSee('print-area', false);
    }

    public function test_the_receipt_list_only_shows_successful_collections(): void
    {
        [$college, $user, $payment] = $this->receiptFixture('FREC6', ['receipts.view', 'fee_collections.update', 'student_fee_assignments.create', 'fee_collections.create']);

        $this->asCollege($college, $user)->get(route('receipts.index'))->assertOk()->assertSee($payment->payment_number);

        $this->asCollege($college, $user)->post(route('fee-collections.cancel', $payment))->assertRedirect();

        // The "Payment … cancelled." flash echoes the number; clear it so the
        // assertion is about the receipt list itself.
        $this->flushSession();

        $this->asCollege($college, $user)->get(route('receipts.index'))->assertOk()->assertDontSee($payment->payment_number);
    }

    public function test_another_colleges_payment_never_renders_a_receipt(): void
    {
        [$college, $user, $payment] = $this->receiptFixture('FREC7');
        $other = $this->makeCollege('FREC7X');
        $otherCtx = $this->makeFinanceContext($other, 'FREC7X');
        $otherStructure = $this->makeFeeStructure($other, $otherCtx);
        $otherFixture = $this->makeFinanceEnrollment($other, $otherCtx, 'FREC7X');
        $otherAssignment = $this->assignFeeStructure($other, $user, $otherFixture['enrollment'], $otherStructure);
        $foreignPayment = $this->collectFee($other, $user, $otherAssignment, 700);
        // Receipt numbers are the (per-college) payment numbers, so the first
        // collection of each college shares a number: the second foreign one is
        // the only fair "must not appear" needle.
        $foreignSecond = $this->collectFee($other, $user, $otherAssignment, 300);

        $this->asCollege($college, $user)->get(route('receipts.show', $foreignPayment))->assertNotFound();
        $this->asCollege($college, $user)->get(route('receipts.print', $foreignPayment))->assertNotFound();

        $this->asCollege($college, $user)
            ->get(route('receipts.index'))
            ->assertOk()
            ->assertSee($payment->payment_number)
            ->assertDontSee($foreignSecond->payment_number);
    }

    public function test_the_module_requires_its_permissions(): void
    {
        [$college, , $payment] = $this->receiptFixture('FREC8');
        $stranger = $this->makeUserWithPermissions($college, ['students.view']);

        $this->asCollege($college, $stranger)->get(route('receipts.index'))->assertForbidden();
        $this->asCollege($college, $stranger)->get(route('receipts.show', $payment))->assertForbidden();
        $this->asCollege($college, $stranger)->get(route('receipts.print', $payment))->assertForbidden();
    }
}
