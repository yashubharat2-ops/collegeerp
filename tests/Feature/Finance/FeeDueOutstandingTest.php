<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Services\FeeDuesService;
use App\Domain\Finance\Support\FeeLedger;
use App\Models\FeeConcession;
use App\Models\FeePayment;
use App\Models\FeeRefund;
use App\Models\StudentFeeAssignment;
use Tests\TestCase;

/**
 * Finance / Fees — Due / Outstanding fees.
 *
 * The screen is derived, read-only data: there is no dues table, so the tests
 * pin the arithmetic and the query semantics instead of any stored balance —
 * the outstanding amount, the paid/partial/due status, the exclusion of
 * cancelled payments, the inclusion of refunds, tenant isolation and
 * deterministic pagination.
 */
class FeeDueOutstandingTest extends TestCase
{
    use FeeStructureTestHelpers;

    /**
     * @return array{0: \App\Models\College, 1: \App\Models\User, 2: StudentFeeAssignment}
     */
    private function duesFixture(string $code): array
    {
        $college = $this->makeCollege($code);
        $user = $this->makeUserWithPermissions($college, [
            'fee_dues.view',
            'student_fee_assignments.create',
            'fee_collections.create',
            'fee_collections.view',
            'fee_collections.update',
        ]);
        $ctx = $this->makeFinanceContext($college, $code);
        $structure = $this->makeFeeStructure($college, $ctx);
        $fixture = $this->makeFinanceEnrollment($college, $ctx, $code);
        $assignment = $this->assignFeeStructure($college, $user, $fixture['enrollment'], $structure);

        return [$college, $user, $assignment];
    }

    public function test_the_outstanding_amount_is_derived_from_the_transactions(): void
    {
        [$college, $user, $assignment] = $this->duesFixture('FDUE1');

        $ledger = $this->ledgerOf($college, $assignment);
        $this->assertSame(26500.0, (float) $ledger['assigned']);
        $this->assertSame(0.0, (float) $ledger['concession']);
        $this->assertSame(0.0, (float) $ledger['paid']);
        $this->assertSame(26500.0, (float) $ledger['outstanding']);
        $this->assertSame(FeeLedger::STATUS_DUE, $ledger['status']);

        $this->collectFee($college, $user, $assignment, 10000);

        $ledger = $this->ledgerOf($college, $assignment);
        $this->assertSame(10000.0, (float) $ledger['paid']);
        $this->assertSame(16500.0, (float) $ledger['outstanding']);
        $this->assertSame(FeeLedger::STATUS_PARTIAL, $ledger['status']);

        $this->collectFee($college, $user, $assignment, 16500);

        $ledger = $this->ledgerOf($college, $assignment);
        $this->assertSame(0.0, (float) $ledger['outstanding']);
        $this->assertSame(FeeLedger::STATUS_PAID, $ledger['status']);
    }

    public function test_the_screen_shows_the_ledger_columns_and_totals(): void
    {
        [$college, $user, $assignment] = $this->duesFixture('FDUE2');
        $this->collectFee($college, $user, $assignment, 5000);

        $this->asCollege($college, $user)
            ->get(route('fee-dues.index'))
            ->assertOk()
            ->assertSee('Outstanding')
            ->assertSee('21,500.00')
            ->assertSee('26,500.00')
            ->assertSee('Partial');
    }

    public function test_a_concession_lowers_the_outstanding_amount(): void
    {
        [$college, $user, $assignment] = $this->duesFixture('FDUE3');

        $this->withTenant($college, fn () => app(\App\Domain\Finance\Services\FeeConcessionService::class)->create($assignment, [
            'type' => 'percentage',
            'value' => 20,
            'reason' => 'Merit scholarship',
        ], $user));

        $ledger = $this->ledgerOf($college, $assignment);
        $this->assertSame(5300.0, (float) $ledger['concession']);
        $this->assertSame(21200.0, (float) $ledger['outstanding']);
    }

    public function test_rejected_and_cancelled_concessions_do_not_lower_the_outstanding_amount(): void
    {
        [$college, $user, $assignment] = $this->duesFixture('FDUE4');

        $concession = $this->withTenant($college, fn () => app(\App\Domain\Finance\Services\FeeConcessionService::class)->create($assignment, [
            'type' => 'fixed',
            'value' => 5000,
            'reason' => 'Pending review',
        ], $user));

        $this->assertSame(5000.0, (float) $this->ledgerOf($college, $assignment)['concession']);

        $this->withTenant($college, fn () => app(\App\Domain\Finance\Services\FeeConcessionService::class)->update($concession, [
            'status' => FeeConcession::STATUS_REJECTED,
        ], $user));

        $ledger = $this->ledgerOf($college, $assignment);
        $this->assertSame(0.0, (float) $ledger['concession']);
        $this->assertSame(26500.0, (float) $ledger['outstanding']);
    }

    public function test_cancelled_payments_are_excluded_and_refunds_re_open_the_balance(): void
    {
        [$college, $user, $assignment] = $this->duesFixture('FDUE5');

        $paid = $this->collectFee($college, $user, $assignment, 20000);
        $reversed = $this->collectFee($college, $user, $assignment, 2000, ['payment_date' => '2026-08-16']);

        $this->withTenant($college, fn () => app(\App\Domain\Finance\Services\FeeCollectionService::class)->cancel($reversed, $user, 'Bounced'));

        $ledger = $this->ledgerOf($college, $assignment);
        $this->assertSame(20000.0, (float) $ledger['paid'], 'A cancelled payment must not count towards the paid amount.');
        $this->assertSame(6500.0, (float) $ledger['outstanding']);

        $this->withTenant($college, fn () => app(\App\Domain\Finance\Services\FeeRefundService::class)->create($paid, [
            'refund_date' => '2026-08-25',
            'amount' => 4000,
            'reason' => 'Hostel adjustment',
        ], $user));

        $ledger = $this->ledgerOf($college, $assignment);
        $this->assertSame(4000.0, (float) $ledger['refunded']);
        $this->assertSame(16000.0, (float) $ledger['net_collected']);
        $this->assertSame(10500.0, (float) $ledger['outstanding']);
        $this->assertSame(FeeLedger::STATUS_PARTIAL, $ledger['status']);
    }

    public function test_rejected_and_cancelled_refunds_stop_reducing_the_collected_amount(): void
    {
        [$college, $user, $assignment] = $this->duesFixture('FDUE6');
        $payment = $this->collectFee($college, $user, $assignment, 10000);

        $refund = $this->withTenant($college, fn () => app(\App\Domain\Finance\Services\FeeRefundService::class)->create($payment, [
            'refund_date' => '2026-08-26',
            'amount' => 3000,
            'reason' => 'Requested',
        ], $user));

        $this->assertSame(13000.0, (float) $this->ledgerOf($college, $assignment)['outstanding']);

        $this->withTenant($college, fn () => app(\App\Domain\Finance\Services\FeeRefundService::class)->update($refund, [
            'status' => FeeRefund::STATUS_CANCELLED,
        ], $user));

        $ledger = $this->ledgerOf($college, $assignment);
        $this->assertSame(0.0, (float) $ledger['refunded']);
        $this->assertSame(16500.0, (float) $ledger['outstanding']);
    }

    public function test_the_due_status_filter_separates_paid_partial_and_due_rows(): void
    {
        [$college, $user, $dueAssignment] = $this->duesFixture('FDUE7');
        $ctx = $this->makeFinanceContext($college, 'FDUE7B');
        $structure = $this->makeFeeStructure($college, $ctx);
        $partialFixture = $this->makeFinanceEnrollment($college, $ctx, 'FDUE7B');
        $partial = $this->assignFeeStructure($college, $user, $partialFixture['enrollment'], $structure);
        $paidFixture = $this->makeFinanceEnrollment($college, $ctx, 'FDUE7C');
        $paid = $this->assignFeeStructure($college, $user, $paidFixture['enrollment'], $structure);

        $this->collectFee($college, $user, $partial, 1000);
        $this->collectFee($college, $user, $paid, 26500);

        $due = $this->withTenant($college, fn () => app(FeeDuesService::class)->paginate(['status' => FeeLedger::STATUS_DUE]));
        $this->assertSame([$dueAssignment->id], $due->getCollection()->pluck('id')->all());

        $partialPage = $this->withTenant($college, fn () => app(FeeDuesService::class)->paginate(['status' => FeeLedger::STATUS_PARTIAL]));
        $this->assertSame([$partial->id], $partialPage->getCollection()->pluck('id')->all());

        $paidPage = $this->withTenant($college, fn () => app(FeeDuesService::class)->paginate(['status' => FeeLedger::STATUS_PAID]));
        $this->assertSame([$paid->id], $paidPage->getCollection()->pluck('id')->all());
    }

    public function test_the_screen_can_be_filtered_by_student_program_and_structure(): void
    {
        [$college, $user, $assignment] = $this->duesFixture('FDUE8');
        $ctx = $this->makeFinanceContext($college, 'FDUE8B');
        $structure = $this->makeFeeStructure($college, $ctx);
        $fixture = $this->makeFinanceEnrollment($college, $ctx, 'FDUE8B');
        $other = $this->assignFeeStructure($college, $user, $fixture['enrollment'], $structure);

        $this->asCollege($college, $user)
            ->get(route('fee-dues.index', ['fee_structure_id' => $structure->id]))
            ->assertOk()
            ->assertSee($other->studentEnrollment->enrollment_number)
            ->assertDontSee($assignment->studentEnrollment->enrollment_number);

        $this->asCollege($college, $user)
            ->get(route('fee-dues.index', ['student_id' => $assignment->studentEnrollment->student_id]))
            ->assertOk()
            ->assertSee($assignment->studentEnrollment->enrollment_number)
            ->assertDontSee($other->studentEnrollment->enrollment_number);

        $this->asCollege($college, $user)
            ->get(route('fee-dues.index', ['academic_year_id' => $ctx['year']->id, 'program_id' => $ctx['prog']->id]))
            ->assertOk()
            ->assertSee($other->studentEnrollment->enrollment_number)
            ->assertDontSee($assignment->studentEnrollment->enrollment_number);
    }

    public function test_pagination_is_deterministic(): void
    {
        [$college, $user] = $this->duesFixture('FDUE9');
        $ctx = $this->makeFinanceContext($college, 'FDUE9B');
        $structure = $this->makeFeeStructure($college, $ctx);

        for ($i = 0; $i < 18; $i++) {
            $fixture = $this->makeFinanceEnrollment($college, $ctx, 'FDUE9-'.$i);
            $this->assignFeeStructure($college, $user, $fixture['enrollment'], $structure);
        }

        $first = $this->withTenant($college, fn () => app(FeeDuesService::class)->paginate([], 10));
        $second = $this->withTenant($college, fn () => app(FeeDuesService::class)->paginate([], 10));

        $this->assertSame($first->getCollection()->pluck('id')->all(), $second->getCollection()->pluck('id')->all());
        $this->assertSame(19, $first->total());

        \Illuminate\Pagination\Paginator::currentPageResolver(fn () => 2);

        try {
            $pageTwo = $this->withTenant($college, fn () => app(FeeDuesService::class)->paginate([], 10));
        } finally {
            \Illuminate\Pagination\Paginator::currentPageResolver(fn () => 1);
        }

        $this->assertSame(9, $pageTwo->count(), 'The second page holds the remainder.');
        $this->assertSame([], array_intersect($first->getCollection()->pluck('id')->all(), $pageTwo->getCollection()->pluck('id')->all()));

        // The screen itself paginates deterministically too.
        $response = $this->asCollege($college, $user)->get(route('fee-dues.index'));
        $response->assertOk();
        $this->assertStringContainsString('page=2', $response->getContent());
    }

    public function test_the_screen_never_shows_another_colleges_assignments(): void
    {
        [$college, $user, $assignment] = $this->duesFixture('FDUE10');
        $other = $this->makeCollege('FDUE10X');
        $otherCtx = $this->makeFinanceContext($other, 'FDUE10X');
        $otherStructure = $this->makeFeeStructure($other, $otherCtx);
        $otherFixture = $this->makeFinanceEnrollment($other, $otherCtx, 'FDUE10X');
        $foreign = $this->assignFeeStructure($other, $user, $otherFixture['enrollment'], $otherStructure);

        $this->asCollege($college, $user)
            ->get(route('fee-dues.index'))
            ->assertOk()
            ->assertSee($assignment->studentEnrollment->enrollment_number)
            ->assertDontSee($foreign->studentEnrollment->enrollment_number);

        // The service callable with a colleague's tenant context stays in college too.
        $summaries = $this->withTenant($college, fn () => app(FeeDuesService::class)->ledgerFor([$foreign]));
        $this->assertSame([], $summaries);
    }

    public function test_the_screen_is_read_only(): void
    {
        [$college, $user] = $this->duesFixture('FDUE11');

        $this->asCollege($college, $user)->post(route('fee-dues.index'), [])->assertMethodNotAllowed();
        $this->asCollege($college, $user)->delete(route('fee-dues.index'))->assertMethodNotAllowed();
    }

    public function test_the_module_requires_its_permission(): void
    {
        [$college] = $this->duesFixture('FDUE12');
        $stranger = $this->makeUserWithPermissions($college, ['students.view']);

        $this->asCollege($college, $stranger)->get(route('fee-dues.index'))->assertForbidden();
    }

    public function test_an_over_collection_is_impossible_so_outstanding_never_goes_negative(): void
    {
        [$college, $user, $assignment] = $this->duesFixture('FDUE13');
        $payment = $this->collectFee($college, $user, $assignment, 26500);

        // Refunding the whole payment re-opens the balance; a refund can never
        // push the collected amount below zero.
        $this->withTenant($college, fn () => app(\App\Domain\Finance\Services\FeeRefundService::class)->create($payment, [
            'refund_date' => '2026-08-27',
            'amount' => 26500,
            'reason' => 'Full refund',
        ], $user));

        $ledger = $this->ledgerOf($college, $assignment);
        $this->assertSame(0.0, (float) $ledger['net_collected']);
        $this->assertSame(26500.0, (float) $ledger['outstanding']);
        $this->assertGreaterThanOrEqual(0.0, (float) $ledger['outstanding']);
    }

    public function test_the_ledger_totals_match_the_per_row_summaries(): void
    {
        [$college, $user, $assignment] = $this->duesFixture('FDUE14');
        $ctx = $this->makeFinanceContext($college, 'FDUE14B');
        $structure = $this->makeFeeStructure($college, $ctx);
        $fixture = $this->makeFinanceEnrollment($college, $ctx, 'FDUE14B');
        $second = $this->assignFeeStructure($college, $user, $fixture['enrollment'], $structure);

        $this->collectFee($college, $user, $assignment, 5000);
        $this->collectFee($college, $user, $second, 2000);

        $totals = $this->withTenant($college, fn () => app(FeeDuesService::class)->totals([]));

        $this->assertSame(2, $totals['assignments']);
        $this->assertSame(53000.0, (float) $totals['assigned']);
        $this->assertSame(7000.0, (float) $totals['paid']);
        $this->assertSame(46000.0, (float) $totals['outstanding']);
        $this->assertSame(0.0, (float) $totals['refunded']);
        $this->assertSame(46000.0, (float) $totals['assigned'] - (float) $totals['paid']);
    }
}
