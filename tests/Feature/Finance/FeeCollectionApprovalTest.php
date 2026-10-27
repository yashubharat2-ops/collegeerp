<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Services\FeeCollectionService;
use App\Domain\Finance\Services\FeeDuesService;
use App\Models\ApprovalRequest;
use App\Models\ApprovalSetting;
use App\Models\College;
use App\Models\FeePayment;
use App\Models\Permission;
use App\Models\StudentFeeAssignment;
use App\Models\StudentTransportAssignment;
use App\Models\StudentTransportFeeAssignment;
use App\Models\TransportFeeStructure;
use App\Models\TransportRoute;
use App\Models\TransportStop;
use App\Models\User;
use Illuminate\Support\Str;
use App\Services\Approval\ApprovalRegistry;
use Tests\TestCase;

/**
 * Approval Management → Fees → Fee Collection (`fees.fee_collection`).
 *
 * The setting is the switch between "a collection is money the moment it is
 * recorded" (the default, and the behaviour when the toggle is off) and "a
 * collection is a request that only becomes money when an authorized approver
 * finalizes it".
 *
 * These tests pin the parts that are easy to get wrong and expensive to get
 * wrong: a pending collection must be invisible to every balance, receipt and
 * report; approval must be the only way for it to become money; rejection must
 * keep the row and never count it; the existing safeguards (self-approval,
 * tenant scoping, permissions, duplicate submission) must still hold; and
 * turning the setting off must restore the previous behaviour exactly.
 */
class FeeCollectionApprovalTest extends TestCase
{
    use FeeStructureTestHelpers;

    /** Permissions an accountant needs to record a collection. */
    private const COLLECTOR = [
        'fee_collections.view',
        'fee_collections.create',
        'fee_collections.update',
        'fee_collections.delete',
        'receipts.view',
        'receipts.print',
    ];

    /** Permissions an approver (College Admin) needs. */
    private const APPROVER = [
        'fee_collections.view',
        'fee_collections.approve',
        'receipts.view',
        'receipts.print',
    ];

    // ---------------------------------------------------------------- fixtures

    /**
     * One college with an accountant and an unrelated approver, plus a payable
     * fee assignment of 26,500.
     *
     * @return array{college: College, collector: User, approver: User, assignment: StudentFeeAssignment}
     */
    private function fixture(string $code, bool $approvalEnabled, float $tuition = 25000, float $library = 1500): array
    {
        $college = $this->makeCollege($code);
        $collector = $this->makeUserWithPermissions($college, self::COLLECTOR);
        $approver = $this->makeUserWithPermissions($college, self::APPROVER);
        $ctx = $this->makeFinanceContext($college, $code);
        $structure = $this->makeFeeStructure($college, $ctx, [], [
            ['name' => 'Tuition Fee', 'amount' => $tuition, 'sort_order' => 1],
            ['name' => 'Library Fee', 'amount' => $library, 'sort_order' => 2],
        ]);
        $fixture = $this->makeFinanceEnrollment($college, $ctx, $code);
        $assignment = $this->assignFeeStructure($college, $collector, $fixture['enrollment'], $structure);

        if ($approvalEnabled) {
            $this->enableApproval($college);
        }

        return compact('college', 'collector', 'approver', 'assignment');
    }

    private function enableApproval(College $college, bool $required = true): ApprovalSetting
    {
        return ApprovalSetting::withoutGlobalScopes()->updateOrCreate([
            'college_id' => $college->id,
            'module' => 'fees',
            'action' => ApprovalRegistry::ACTION_FEE_COLLECTION,
        ], [
            'is_required' => $required,
            'label' => 'Fee Collection',
        ]);
    }

    /** The POST payload the collection form sends. */
    private function collectPayload(StudentFeeAssignment $assignment, float $amount, array $overrides = []): array
    {
        return array_merge([
            'student_fee_assignment_id' => $assignment->id,
            'payment_date' => '2026-08-15',
            'payment_mode' => FeePayment::MODE_CASH,
            'amount' => $amount,
            'remarks' => 'Second instalment',
            'submission_token' => Str::uuid()->toString(),
        ], $overrides);
    }

    /** The single payment row of the fixture, read outside the request cycle. */
    private function paymentOf(College $college): FeePayment
    {
        return $this->withTenant($college, fn () => FeePayment::query()->latest('id')->firstOrFail());
    }

    private function approvalRequestOf(College $college, FeePayment $payment): ?ApprovalRequest
    {
        return ApprovalRequest::withoutGlobalScopes()
            ->where('college_id', $college->id)
            ->where('module', 'fees')
            ->where('action', ApprovalRegistry::ACTION_FEE_COLLECTION)
            ->where('target_type', 'fees.'.ApprovalRegistry::ACTION_FEE_COLLECTION)
            ->where('target_id', $payment->getKey())
            ->first();
    }

    // ------------------------------------------------- supported actions (UI)

    public function test_fee_collection_is_a_supported_approval_action(): void
    {
        $this->assertTrue(ApprovalRegistry::isSupported('fees', ApprovalRegistry::ACTION_FEE_COLLECTION));
        $this->assertSame('Fee Collection', ApprovalRegistry::labelFor('fees', 'fee_collection'));
        $this->assertSame('fee_collections.approve', ApprovalRegistry::permissionFor('fees', 'fee_collection'));
        $this->assertContains(
            'fees.fee_collection',
            array_map(fn (array $row): string => $row['module'].'.'.$row['action'], ApprovalRegistry::allSupported()),
        );
    }

    public function test_the_settings_screen_lists_the_new_action_for_an_administrator(): void
    {
        $college = $this->makeCollege('FCAUI');
        $admin = $this->makeUserWithPermissions($college, ['approvals.view', 'approvals.settings.manage']);

        $this->asCollege($college, $admin)
            ->get(route('approvals.settings.index'))
            ->assertOk()
            ->assertSee('fee_collection')
            ->assertSee('Fee Collection')
            // Module column renders ucfirst('fees') = "Fees".
            ->assertSee('Fees');
    }

    public function test_an_administrator_can_enable_and_disable_the_setting_through_the_existing_screen(): void
    {
        $college = $this->makeCollege('FCASET');
        $admin = $this->makeUserWithPermissions($college, ['approvals.view', 'approvals.settings.manage']);

        $payload = ['settings' => [[
            'module' => 'fees',
            'action' => 'fee_collection',
            'is_required' => 1,
        ]]];

        $this->asCollege($college, $admin)->put(route('approvals.settings.update'), $payload)->assertRedirect();
        $this->assertDatabaseHas('approval_settings', [
            'college_id' => $college->id,
            'module' => 'fees',
            'action' => 'fee_collection',
            'is_required' => true,
            'label' => 'Fee Collection',
        ]);

        $payload['settings'][0]['is_required'] = 0;
        $this->asCollege($college, $admin)->put(route('approvals.settings.update'), $payload)->assertRedirect();
        $this->assertDatabaseHas('approval_settings', [
            'college_id' => $college->id,
            'module' => 'fees',
            'action' => 'fee_collection',
            'is_required' => false,
        ]);

        // The change is audited like every other setting change.
        $this->assertDatabaseHas('audit_logs', ['action' => 'approval_setting.updated']);
    }

    // ------------------------------------------------------- disabled = as before

    public function test_with_approval_disabled_a_collection_is_completed_immediately(): void
    {
        ['college' => $college, 'collector' => $collector, 'assignment' => $assignment]
            = $this->fixture('FCOFF', approvalEnabled: false);

        $this->asCollege($college, $collector)
            ->post(route('fee-collections.store'), $this->collectPayload($assignment, 10000))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('fee-collections.index'));

        $payment = $this->paymentOf($college);
        $this->assertSame(FeePayment::STATUS_COMPLETED, $payment->status);
        $this->assertNotNull($payment->collected_at);
        // No approval machinery was touched.
        $this->assertDatabaseCount('approval_requests', 0);

        $ledger = $this->ledgerOf($college, $assignment);
        $this->assertSame(10000.0, (float) $ledger['paid']);
        $this->assertSame(16500.0, (float) $ledger['outstanding']);

        // Existing downstream behaviour is untouched: the receipt renders.
        $this->asCollege($college, $collector)
            ->get(route('receipts.show', $payment))
            ->assertOk();

        // The collection form does not warn about an approval that will never come.
        $this->asCollege($college, $collector)
            ->get(route('fee-collections.create'))
            ->assertOk()
            ->assertDontSee('Approval required.');
    }

    public function test_an_explicitly_disabled_setting_keeps_collections_immediate(): void
    {
        ['college' => $college, 'collector' => $collector, 'assignment' => $assignment]
            = $this->fixture('FCOFF2', approvalEnabled: false);
        $this->enableApproval($college, false);

        $this->asCollege($college, $collector)
            ->post(route('fee-collections.store'), $this->collectPayload($assignment, 5000))
            ->assertSessionHasNoErrors();

        $this->assertSame(FeePayment::STATUS_COMPLETED, $this->paymentOf($college)->status);
    }

    // -------------------------------------------------------- enabled = pending

    public function test_with_approval_enabled_a_collection_stays_pending_and_is_not_money(): void
    {
        ['college' => $college, 'collector' => $collector, 'assignment' => $assignment]
            = $this->fixture('FCON', approvalEnabled: true);

        $this->asCollege($college, $collector)
            ->post(route('fee-collections.store'), $this->collectPayload($assignment, 10000))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('fee-collections.index'));

        $payment = $this->paymentOf($college);

        $this->assertSame(FeePayment::STATUS_PENDING_APPROVAL, $payment->status);
        // Not money yet: no collection stamp.
        $this->assertNull($payment->collected_at);
        $this->assertSame($collector->id, $payment->collected_by);
        // Server-generated number survives the workflow (it is the receipt number).
        $this->assertMatchesRegularExpression('/^PAY-\d{4}-\d{4}$/', $payment->payment_number);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'fee_payments.submitted_for_approval',
            'subject_id' => $payment->id,
        ]);

        $request = $this->approvalRequestOf($college, $payment);
        $this->assertNotNull($request, 'A pending collection must queue a central approval request.');
        $this->assertSame(ApprovalRequest::STATUS_PENDING, $request->status);
        $this->assertSame($collector->id, $request->requested_by);
        $this->assertSame(10000.0, (float) $request->payload['amount']);

        // Balances ignore it entirely.
        $ledger = $this->ledgerOf($college, $assignment);
        $this->assertSame(0.0, (float) $ledger['paid']);
        $this->assertSame(26500.0, (float) $ledger['outstanding']);

        // So do receipts: no receipt page, not listed as an issued receipt.
        $this->asCollege($college, $collector)->get(route('receipts.show', $payment))->assertForbidden();
        $this->asCollege($college, $collector)
            ->get(route('receipts.index'))
            ->assertOk()
            ->assertDontSee($payment->payment_number);

        // The collection register shows the workflow state honestly.
        $this->asCollege($college, $collector)
            ->get(route('fee-collections.index'))
            ->assertOk()
            ->assertSee($payment->payment_number)
            ->assertSee('Pending approval')
            ->assertSee('Awaiting approval');

        // The accountant is warned before recording the next one.
        $this->asCollege($college, $collector)
            ->get(route('fee-collections.create'))
            ->assertOk()
            ->assertSee('Approval required.');
    }

    public function test_the_collection_report_only_counts_finalized_collections(): void
    {
        ['college' => $college, 'collector' => $collector, 'approver' => $approver, 'assignment' => $assignment]
            = $this->fixture('FCREP', approvalEnabled: true);

        $this->asCollege($college, $collector)
            ->post(route('fee-collections.store'), $this->collectPayload($assignment, 10000))
            ->assertSessionHasNoErrors();

        // Report 1 reads completed payments only, so a pending collection is not
        // "collected" money in any financial summary.
        $summary = $this->withTenant(
            $college,
            fn () => app(\App\Domain\Finance\Services\FeeReportService::class)->collectionSummary([]),
        );
        $this->assertSame(0.0, (float) $summary['total']);
        $this->assertSame(0, (int) $summary['payments']);

        $payment = $this->paymentOf($college);
        $this->asCollege($college, $approver)
            ->post(route('approvals.approve', $this->approvalRequestOf($college, $payment)))
            ->assertSessionHasNoErrors();

        $summary = $this->withTenant(
            $college,
            fn () => app(\App\Domain\Finance\Services\FeeReportService::class)->collectionSummary([]),
        );
        $this->assertSame(10000.0, (float) $summary['total']);
        $this->assertSame(1, (int) $summary['payments']);
    }

    // ------------------------------------------------------------- approval path

    public function test_an_authorized_approver_finalizes_the_collection(): void
    {
        ['college' => $college, 'collector' => $collector, 'approver' => $approver, 'assignment' => $assignment]
            = $this->fixture('FCAPP', approvalEnabled: true);

        $this->asCollege($college, $collector)
            ->post(route('fee-collections.store'), $this->collectPayload($assignment, 10000))
            ->assertSessionHasNoErrors();

        $payment = $this->paymentOf($college);
        $request = $this->approvalRequestOf($college, $payment);

        $this->asCollege($college, $approver)
            ->post(route('approvals.approve', $request), ['decision_reason' => 'Cash verified'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $payment->refresh();
        $request->refresh();

        $this->assertSame(ApprovalRequest::STATUS_APPROVED, $request->status);
        $this->assertSame(FeePayment::STATUS_COMPLETED, $payment->status);
        $this->assertNotNull($payment->collected_at);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'fee_payments.approved',
            'subject_id' => $payment->id,
        ]);

        // Only now does the money appear in the ledger.
        $ledger = $this->ledgerOf($college, $assignment);
        $this->assertSame(10000.0, (float) $ledger['paid']);
        $this->assertSame(16500.0, (float) $ledger['outstanding']);

        // ...and the receipt exists.
        $this->asCollege($college, $collector)
            ->get(route('receipts.show', $payment))
            ->assertOk();
        $this->asCollege($college, $collector)
            ->get(route('receipts.index'))
            ->assertOk()
            ->assertSee($payment->payment_number);
    }

    public function test_rejection_keeps_the_row_and_never_counts_it_as_paid(): void
    {
        ['college' => $college, 'collector' => $collector, 'approver' => $approver, 'assignment' => $assignment]
            = $this->fixture('FCREJ', approvalEnabled: true);

        $this->asCollege($college, $collector)
            ->post(route('fee-collections.store'), $this->collectPayload($assignment, 10000))
            ->assertSessionHasNoErrors();

        $payment = $this->paymentOf($college);
        $request = $this->approvalRequestOf($college, $payment);

        $this->asCollege($college, $approver)
            ->post(route('approvals.reject', $request), ['decision_reason' => 'Cheque bounced'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $payment->refresh();
        $request->refresh();

        $this->assertSame(ApprovalRequest::STATUS_REJECTED, $request->status);
        $this->assertSame(FeePayment::STATUS_REJECTED, $payment->status);
        // The row is preserved for the audit trail, with the reason attached.
        $this->assertNotNull($payment->payment_number);
        $this->assertStringContainsString('Cheque bounced', (string) $payment->remarks);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'fee_payments.rejected',
            'subject_id' => $payment->id,
        ]);

        // Never counted, never refundable, never receipted.
        $this->assertSame(0.0, (float) $this->ledgerOf($college, $assignment)['paid']);
        $this->assertSame(0.0, $payment->refundableAmount());
        $this->asCollege($college, $collector)->get(route('receipts.show', $payment))->assertForbidden();

        // A rejected collection can never be approved afterwards.
        $this->asCollege($college, $approver)
            ->post(route('approvals.approve', $request))
            ->assertSessionHasErrors();
        $this->assertSame(FeePayment::STATUS_REJECTED, $payment->fresh()->status);
    }

    public function test_rejection_requires_a_reason_and_leaves_the_collection_pending(): void
    {
        ['college' => $college, 'collector' => $collector, 'approver' => $approver, 'assignment' => $assignment]
            = $this->fixture('FCREJ2', approvalEnabled: true);

        $this->asCollege($college, $collector)
            ->post(route('fee-collections.store'), $this->collectPayload($assignment, 4000))
            ->assertSessionHasNoErrors();

        $payment = $this->paymentOf($college);
        $request = $this->approvalRequestOf($college, $payment);

        $this->asCollege($college, $approver)
            ->post(route('approvals.reject', $request), [])
            ->assertSessionHasErrors('decision_reason');

        $this->assertSame(FeePayment::STATUS_PENDING_APPROVAL, $payment->fresh()->status);
    }

    public function test_the_submitter_cannot_approve_their_own_collection(): void
    {
        // An accountant who ALSO holds the approval permission still cannot
        // self-approve: the existing self-approval rule wins.
        $college = $this->makeCollege('FCSELF');
        $collector = $this->makeUserWithPermissions($college, array_merge(self::COLLECTOR, ['fee_collections.approve']));
        $ctx = $this->makeFinanceContext($college, 'FCSELF');
        $structure = $this->makeFeeStructure($college, $ctx);
        $fixture = $this->makeFinanceEnrollment($college, $ctx, 'FCSELF');
        $assignment = $this->assignFeeStructure($college, $collector, $fixture['enrollment'], $structure);
        $this->enableApproval($college);

        $this->asCollege($college, $collector)
            ->post(route('fee-collections.store'), $this->collectPayload($assignment, 3000))
            ->assertSessionHasNoErrors();

        $payment = $this->paymentOf($college);
        $request = $this->approvalRequestOf($college, $payment);

        $this->asCollege($college, $collector)
            ->post(route('approvals.approve', $request))
            ->assertSessionHasErrors();

        $this->assertSame(FeePayment::STATUS_PENDING_APPROVAL, $payment->fresh()->status);
        $this->assertSame(ApprovalRequest::STATUS_PENDING, $request->fresh()->status);
    }

    public function test_a_user_without_fee_collection_approval_cannot_decide(): void
    {
        ['college' => $college, 'collector' => $collector, 'assignment' => $assignment]
            = $this->fixture('FC403', approvalEnabled: true);
        $bystander = $this->makeUserWithPermissions($college, ['fee_collections.view', 'approvals.view']);

        $this->asCollege($college, $collector)
            ->post(route('fee-collections.store'), $this->collectPayload($assignment, 2500))
            ->assertSessionHasNoErrors();

        $payment = $this->paymentOf($college);
        $request = $this->approvalRequestOf($college, $payment);

        $this->asCollege($college, $bystander)->post(route('approvals.approve', $request))->assertForbidden();
        $this->asCollege($college, $bystander)->post(route('approvals.reject', $request), ['decision_reason' => 'no'])->assertForbidden();

        $this->assertSame(FeePayment::STATUS_PENDING_APPROVAL, $payment->fresh()->status);
        $this->assertSame(ApprovalRequest::STATUS_PENDING, $request->fresh()->status);
    }

    public function test_another_college_cannot_decide_the_request(): void
    {
        ['college' => $college, 'collector' => $collector, 'assignment' => $assignment]
            = $this->fixture('FCISO', approvalEnabled: true);
        $otherCollege = $this->makeCollege('FCISOB');
        $otherAdmin = $this->makeUserWithPermissions($otherCollege, self::APPROVER);

        $this->asCollege($college, $collector)
            ->post(route('fee-collections.store'), $this->collectPayload($assignment, 2500))
            ->assertSessionHasNoErrors();

        $payment = $this->paymentOf($college);
        $request = $this->approvalRequestOf($college, $payment);

        $this->asCollege($otherCollege, $otherAdmin)
            ->post(route('approvals.approve', $request))
            ->assertNotFound();

        $this->assertSame(FeePayment::STATUS_PENDING_APPROVAL, $payment->fresh()->status);
    }

    public function test_the_submitter_can_withdraw_the_request_and_the_collection_is_reversed(): void
    {
        ['college' => $college, 'collector' => $collector, 'assignment' => $assignment]
            = $this->fixture('FCWD', approvalEnabled: true);

        $this->asCollege($college, $collector)
            ->post(route('fee-collections.store'), $this->collectPayload($assignment, 2500))
            ->assertSessionHasNoErrors();

        $payment = $this->paymentOf($college);
        $request = $this->approvalRequestOf($college, $payment);

        $this->asCollege($college, $collector)
            ->post(route('approvals.cancel', $request))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $payment->refresh();
        $this->assertSame(ApprovalRequest::STATUS_CANCELLED, $request->fresh()->status);
        // No stranded "pending approval" row: it is reversed through the audited
        // cancellation state and still never counted as paid.
        $this->assertSame(FeePayment::STATUS_CANCELLED, $payment->status);
        $this->assertNotNull($payment->cancelled_at);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'fee_payments.approval_withdrawn',
            'subject_id' => $payment->id,
        ]);
        $this->assertSame(0.0, (float) $this->ledgerOf($college, $assignment)['paid']);
    }

    // ------------------------------------------------------- existing safeguards

    public function test_a_pending_collection_cannot_be_edited_cancelled_or_deleted(): void
    {
        ['college' => $college, 'collector' => $collector, 'assignment' => $assignment]
            = $this->fixture('FCLOCK', approvalEnabled: true);

        $this->asCollege($college, $collector)
            ->post(route('fee-collections.store'), $this->collectPayload($assignment, 2500))
            ->assertSessionHasNoErrors();

        $payment = $this->paymentOf($college);

        $this->asCollege($college, $collector)->get(route('fee-collections.edit', $payment))->assertForbidden();

        $this->asCollege($college, $collector)
            ->put(route('fee-collections.update', $payment), [
                'payment_date' => '2026-08-20',
                'payment_mode' => FeePayment::MODE_CASH,
                'remarks' => 'Rewritten while awaiting approval',
            ])
            ->assertSessionHasErrors('payment_date');

        $this->asCollege($college, $collector)
            ->post(route('fee-collections.cancel', $payment), ['cancellation_reason' => 'changed my mind'])
            ->assertSessionHasErrors('status');

        $this->asCollege($college, $collector)
            ->delete(route('fee-collections.destroy', $payment))
            ->assertSessionHasErrors();

        $payment->refresh();
        $this->assertSame(FeePayment::STATUS_PENDING_APPROVAL, $payment->status);
        $this->assertSame('2026-08-15', $payment->payment_date->format('Y-m-d'));
        $this->assertSame('Second instalment', $payment->remarks);
    }

    public function test_a_pending_or_rejected_collection_cannot_be_refunded(): void
    {
        ['college' => $college, 'collector' => $collector, 'approver' => $approver, 'assignment' => $assignment]
            = $this->fixture('FCREF', approvalEnabled: true);
        $collector = $this->makeUserWithPermissions($college, array_merge(self::COLLECTOR, ['refunds.view', 'refunds.create']));

        $this->asCollege($college, $collector)
            ->post(route('fee-collections.store'), $this->collectPayload($assignment, 2500))
            ->assertSessionHasNoErrors();

        $payment = $this->paymentOf($college);

        $this->asCollege($college, $collector)
            ->post(route('refunds.store'), [
                'fee_payment_id' => $payment->id,
                'refund_date' => '2026-08-16',
                'amount' => 500,
                'reason' => 'Not collected yet',
            ])
            ->assertSessionHasErrors('fee_payment_id');

        $this->assertDatabaseCount('fee_refunds', 0);
        $this->assertSame(0.0, $payment->fresh()->refundableAmount());
    }

    public function test_a_duplicate_submission_is_refused(): void
    {
        ['college' => $college, 'collector' => $collector, 'assignment' => $assignment]
            = $this->fixture('FCDUP', approvalEnabled: true);

        $payload = $this->collectPayload($assignment, 2500, ['reference_number' => 'UTR-DUP-1']);

        $this->asCollege($college, $collector)
            ->post(route('fee-collections.store'), $payload)
            ->assertSessionHasNoErrors();

        // The very same form posted twice: the idempotency token refuses it.
        $this->asCollege($college, $collector)
            ->post(route('fee-collections.store'), $payload)
            ->assertSessionHasErrors();

        // A fresh form with the same bank reference is refused as well.
        $this->asCollege($college, $collector)
            ->post(route('fee-collections.store'), $this->collectPayload($assignment, 2500, ['reference_number' => 'UTR-DUP-1']))
            ->assertSessionHasErrors('reference_number');

        $this->assertSame(
            1,
            $this->withTenant($college, fn () => FeePayment::query()->count()),
        );
        $this->assertDatabaseCount('approval_requests', 1);
    }

    public function test_pending_collections_cannot_oversubscribe_the_balance(): void
    {
        ['college' => $college, 'collector' => $collector, 'assignment' => $assignment]
            = $this->fixture('FCAP', approvalEnabled: true);

        $this->asCollege($college, $collector)
            ->post(route('fee-collections.store'), $this->collectPayload($assignment, 20000))
            ->assertSessionHasNoErrors();

        // 6,500 remains on the ledger, but 20,000 is already awaiting a decision,
        // so nothing further may be queued against it.
        $this->asCollege($college, $collector)
            ->post(route('fee-collections.store'), $this->collectPayload($assignment, 7000))
            ->assertSessionHasErrors('amount');

        $this->assertSame(
            1,
            $this->withTenant($college, fn () => FeePayment::query()->count()),
        );
    }

    public function test_the_same_gate_applies_to_transport_fee_collections(): void
    {
        $college = $this->makeCollege('FCTRN');
        $collector = $this->makeUserWithPermissions($college, [
            'transport_fees.view', 'transport_fees.create', 'transport_fees.update', 'transport_fees.delete',
            'fee_collections.view',
        ]);
        $approver = $this->makeUserWithPermissions($college, self::APPROVER);
        $this->enableApproval($college);

        $year = \App\Models\AcademicYear::create([
            'college_id' => $college->id,
            'name' => '2026-27',
            'code' => 'AY-TRN',
            'starts_on' => '2026-07-01',
            'ends_on' => '2027-06-30',
            'status' => 'active',
        ]);
        $student = \App\Models\Student::create([
            'college_id' => $college->id,
            'student_number' => 'STU-TRN-1',
            'first_name' => 'Transport',
            'last_name' => 'Student',
            'status' => 'active',
        ]);
        $enrollment = \App\Models\StudentEnrollment::create([
            'college_id' => $college->id,
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'enrollment_number' => 'ENR-TRN-1',
            'enrollment_date' => '2026-08-05',
            'status' => 'active',
        ]);
        // Transport route / stop models keep college_id and route_id out of
        // $fillable (they are stamped by their own services), so the fixtures are
        // built the way the Transport tests build them.
        $route = new TransportRoute(['name' => 'City', 'code' => 'R1', 'status' => 'active']);
        $route->college_id = $college->id;
        $route->save();

        $stop = new TransportStop(['name' => 'Stop 1', 'code' => 'S1', 'sequence' => 1, 'status' => 'active']);
        $stop->college_id = $college->id;
        $stop->route_id = $route->id;
        $stop->save();

        $transportAssignment = StudentTransportAssignment::create([
            'college_id' => $college->id,
            'student_enrollment_id' => $enrollment->id,
            'academic_year_id' => $year->id,
            'transport_route_id' => $route->id,
            'transport_stop_id' => $stop->id,
            'start_date' => '2026-09-01',
            'status' => 'active',
        ]);
        $structure = TransportFeeStructure::create([
            'college_id' => $college->id,
            'academic_year_id' => $year->id,
            'name' => 'City Route Fee',
            'code' => 'TRF-1',
            'amount' => 1200.50,
            'effective_from' => '2026-07-01',
            'status' => 'active',
        ]);

        $this->asCollege($college, $collector)->post(route('transport-fees.store'), [
            'student_transport_assignment_id' => $transportAssignment->id,
            'transport_fee_structure_id' => $structure->id,
            'effective_from' => '2026-09-01',
            'status' => 'active',
        ])->assertSessionHasNoErrors();

        $fee = StudentTransportFeeAssignment::withoutGlobalScopes()->firstOrFail();

        $this->asCollege($college, $collector)->post(route('transport-fees.collect', $fee->id), [
            'payment_date' => '2026-09-05',
            'payment_mode' => FeePayment::MODE_UPI,
            'amount' => '600.25',
            'reference_number' => 'UTR-TRN-1',
            'submission_token' => Str::uuid()->toString(),
        ])->assertSessionHasNoErrors();

        // The shared payment row is pending, so the transport ledger is untouched.
        $payment = $this->withTenant($college, fn () => FeePayment::query()->latest('id')->firstOrFail());
        $this->assertSame(FeePayment::STATUS_PENDING_APPROVAL, $payment->status);
        $this->assertNotNull($payment->transport_fee_assignment_id);

        $summary = $this->withTenant(
            $college,
            fn () => app(\App\Domain\Transport\Services\TransportFeeService::class)->summaryFor($fee->fresh()),
        );
        $this->assertSame(0.0, (float) $summary['net_collected']);

        // Approving it through the same central framework finalizes it.
        $request = $this->approvalRequestOf($college, $payment);
        $this->assertNotNull($request);

        $this->asCollege($college, $approver)
            ->post(route('approvals.approve', $request))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(FeePayment::STATUS_COMPLETED, $payment->fresh()->status);

        $summary = $this->withTenant(
            $college,
            fn () => app(\App\Domain\Transport\Services\TransportFeeService::class)->summaryFor($fee->fresh()),
        );
        $this->assertSame(600.25, (float) $summary['net_collected']);
    }

    public function test_a_collection_recorded_outside_a_request_still_uses_the_tenant_context(): void
    {
        ['college' => $college, 'collector' => $collector, 'assignment' => $assignment]
            = $this->fixture('FCSVC', approvalEnabled: true);

        $payment = $this->withTenant(
            $college,
            fn () => app(FeeCollectionService::class)->collect($assignment, [
                'payment_date' => '2026-08-15',
                'payment_mode' => FeePayment::MODE_CASH,
                'amount' => 1500,
            ], $collector),
        );

        $this->assertSame(FeePayment::STATUS_PENDING_APPROVAL, $payment->status);
        $this->assertSame($college->id, $payment->college_id);
        $this->assertNotNull($this->approvalRequestOf($college, $payment));
    }
}
