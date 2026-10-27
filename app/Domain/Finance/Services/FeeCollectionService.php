<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Actions\GenerateFeePaymentNumber;
use App\Domain\Finance\Support\FeeLedger;
use App\Domain\Hostel\Services\HostelFeeService;
use App\Domain\Transport\Services\TransportFeeService;
use App\Models\FeePayment;
use App\Models\FeeRefund;
use App\Models\HostelFeeAssignment;
use App\Models\StudentFeeAssignment;
use App\Models\StudentTransportFeeAssignment;
use App\Models\User;
use App\Services\Approval\ApprovalRegistry;
use App\Services\Approval\ApprovalService;
use App\Services\Audit\AuditLogService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * FeeCollectionService — records and reverses student fee collections.
 *
 * This is the ONLY place a collected amount is ever written: tuition fee
 * collections (against a StudentFeeAssignment), TRANSPORT fee collections
 * (against a StudentTransportFeeAssignment) and HOSTEL fee collections
 * (against a HostelFeeAssignment) share these same payment rows, the
 * same server-generated payment number series and the same receipt projection,
 * so no second payment system exists. Exactly one of
 * `student_fee_assignment_id` / `transport_fee_assignment_id` / `hostel_fee_assignment_id`
 * is ever set — stamped server-side here, never from the request.
 *
 * Every write happens inside a transaction on a LOCKED assignment row, so the
 * two monetary invariants cannot be broken by concurrency:
 *
 *   amount > 0
 *   amount ≤ the outstanding balance (assigned − concessions − paid + refunds)
 *
 * The outstanding balance is recomputed server-side from the transaction rows
 * while the lock is held; a browser-supplied balance is never read. Payment
 * numbers are generated server-side by GenerateFeePaymentNumber.
 *
 * Cancelled ("reversed") payments stay in the table for the audit trail but stop
 * counting towards the paid amount — nothing is ever deleted or zeroed.
 *
 * APPROVAL GATE: when the college enables Approval Management → Fees → Fee
 * Collection (`fees.fee_collection`), a collection is created with status
 * `pending_approval` and a central approval request is queued in the SAME
 * transaction. A pending collection is not money: every balance, receipt,
 * report and financial summary reads completed rows only, so nothing downstream
 * needs to know about the workflow. Approval finalizes the row; rejection keeps
 * it as `rejected` and it never counts. With the setting disabled (the default)
 * a collection is completed immediately, exactly as before.
 */
class FeeCollectionService
{
    private const AUDITED = [
        'student_fee_assignment_id',
        'student_enrollment_id',
        'fee_structure_id',
        'transport_fee_assignment_id',
        'hostel_fee_assignment_id',
        'payment_number',
        'payment_date',
        'payment_mode',
        'amount',
        'reference_number',
        'status',
        'remarks',
        'collected_by',
        'collected_at',
        'cancelled_by',
        'cancelled_at',
        'cancellation_reason',
    ];

    public function __construct(
        private readonly FeeDuesService $dues,
        private readonly GenerateFeePaymentNumber $numbers,
        private readonly AuditLogService $audit,
        private readonly ApprovalService $approvals,
    ) {
    }

    /**
     * Record a collection against an assignment.
     *
     * @param  array{payment_date: string, payment_mode: string, amount: mixed, reference_number?: string|null, submission_token?: string|null, remarks?: string|null}  $data
     */
    public function collect(StudentFeeAssignment $assignment, array $data, User $actor): FeePayment
    {
        $this->assertTenant($assignment);

        return DB::transaction(function () use ($assignment, $data, $actor): FeePayment {
            /** @var StudentFeeAssignment $locked */
            $locked = StudentFeeAssignment::withoutGlobalScopes()
                ->whereKey($assignment->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->isPayable()) {
                throw ValidationException::withMessages([
                    'student_fee_assignment_id' => 'This fee assignment is cancelled and cannot be collected against.',
                ]);
            }

            $amount = FeeLedger::money($data['amount']);

            if ($amount <= 0) {
                throw ValidationException::withMessages(['amount' => 'The amount must be greater than zero.']);
            }

            if ($amount > FeePayment::MAX_AMOUNT) {
                throw ValidationException::withMessages(['amount' => 'The amount exceeds the maximum supported value.']);
            }

            // Recomputed here, under the lock: never trusted from the browser.
            $summary = $this->dues->summaryFor($locked);

            $this->assertWithinOutstanding(
                $amount,
                $summary['outstanding'],
                $this->pendingApprovalSum((int) $locked->college_id, 'student_fee_assignment_id', (int) $locked->getKey()),
            );

            $this->assertNoDuplicateReference($locked->college_id, 'student_fee_assignment_id', $locked->getKey(), $data, $amount);

            $token = trim((string) ($data['submission_token'] ?? ''));

            // Decided under the same lock as the amount: with approval enabled a
            // collection is born pending and becomes money only when approved.
            $status = $this->statusFor((int) $locked->college_id, $actor);

            $payment = $this->createPaymentRecord([
                'college_id' => $locked->college_id,
                'student_fee_assignment_id' => $locked->getKey(),
                'student_enrollment_id' => $locked->student_enrollment_id,
                'fee_structure_id' => $locked->fee_structure_id,
                'transport_fee_assignment_id' => null,
                'hostel_fee_assignment_id' => null,
                'payment_number' => $this->numbers->execute((int) $locked->college_id),
                'payment_date' => $data['payment_date'],
                'payment_mode' => $data['payment_mode'],
                'amount' => $amount,
                'reference_number' => ($data['reference_number'] ?? null) ?: null,
                'submission_token' => $token !== '' ? $token : null,
                'status' => $status,
                'remarks' => ($data['remarks'] ?? null) ?: null,
                'collected_by' => $actor->getKey(),
                'collected_at' => $status === FeePayment::STATUS_COMPLETED ? now() : null,
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ], $token);

            return $this->settleOrQueue($payment, $actor);
        });
    }

    /**
     * Record a TRANSPORT fee collection against a student transport fee
     * assignment.
     *
     * The same single payment row, payment number series, outstanding cap and
     * receipt projection as a tuition collection — there is no second money
     * path. Transport fees have no concessions, so their outstanding balance is
     * `amount − valid payments + valid refunds`, derived with the shared
     * FeeLedger arithmetic while the row lock is held.
     *
     * @param  array{payment_date: string, payment_mode: string, amount: mixed, reference_number?: string|null, submission_token?: string|null, remarks?: string|null}  $data
     */
    public function collectTransportFee(StudentTransportFeeAssignment $assignment, array $data, User $actor): FeePayment
    {
        $this->assertTenant($assignment);

        return DB::transaction(function () use ($assignment, $data, $actor): FeePayment {
            /** @var StudentTransportFeeAssignment $locked */
            $locked = StudentTransportFeeAssignment::withoutGlobalScopes()
                ->whereKey($assignment->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->isPayable()) {
                throw ValidationException::withMessages([
                    'student_transport_fee_assignment_id' => 'This transport fee assignment is cancelled and cannot be collected against.',
                ]);
            }

            $amount = FeeLedger::money($data['amount']);

            if ($amount <= 0) {
                throw ValidationException::withMessages(['amount' => 'The amount must be greater than zero.']);
            }

            if ($amount > FeePayment::MAX_AMOUNT) {
                throw ValidationException::withMessages(['amount' => 'The amount exceeds the maximum supported value.']);
            }

            // Recomputed here, under the lock: never trusted from the browser.
            $summary = app(TransportFeeService::class)->summaryFor($locked);

            $this->assertWithinOutstanding(
                $amount,
                $summary['outstanding'],
                $this->pendingApprovalSum((int) $locked->college_id, 'transport_fee_assignment_id', (int) $locked->getKey()),
            );

            $this->assertNoDuplicateReference((int) $locked->college_id, 'transport_fee_assignment_id', $locked->getKey(), $data, $amount);

            $token = trim((string) ($data['submission_token'] ?? ''));
            $status = $this->statusFor((int) $locked->college_id, $actor);

            $payment = $this->createPaymentRecord([
                'college_id' => $locked->college_id,
                'student_fee_assignment_id' => null,
                'student_enrollment_id' => $locked->studentTransportAssignment->student_enrollment_id,
                'fee_structure_id' => null,
                'transport_fee_assignment_id' => $locked->getKey(),
                'hostel_fee_assignment_id' => null,
                'payment_number' => $this->numbers->execute((int) $locked->college_id),
                'payment_date' => $data['payment_date'],
                'payment_mode' => $data['payment_mode'],
                'amount' => $amount,
                'reference_number' => ($data['reference_number'] ?? null) ?: null,
                'submission_token' => $token !== '' ? $token : null,
                'status' => $status,
                'remarks' => ($data['remarks'] ?? null) ?: null,
                'collected_by' => $actor->getKey(),
                'collected_at' => $status === FeePayment::STATUS_COMPLETED ? now() : null,
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ], $token);

            return $this->settleOrQueue($payment, $actor);
        });
    }


    /**
     * Record a HOSTEL fee collection against a hostel fee assignment.
     *
     * Same single payment row, payment number series, outstanding cap and
     * receipt projection as tuition/transport collections — no second money
     * path. Hostel fees have no concessions, so outstanding is
     * amount − valid payments + valid refunds, derived with shared FeeLedger
     * arithmetic while row lock is held.
     *
     * @param  array{payment_date: string, payment_mode: string, amount: mixed, reference_number?: string|null, submission_token?: string|null, remarks?: string|null}  $data
     */
    public function collectHostelFee(HostelFeeAssignment $assignment, array $data, User $actor): FeePayment
    {
        $this->assertTenant($assignment);

        return DB::transaction(function () use ($assignment, $data, $actor): FeePayment {
            /** @var HostelFeeAssignment $locked */
            $locked = HostelFeeAssignment::withoutGlobalScopes()
                ->whereKey($assignment->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->isPayable()) {
                throw ValidationException::withMessages([
                    'hostel_fee_assignment_id' => 'This hostel fee assignment is cancelled and cannot be collected against.',
                ]);
            }

            $amount = FeeLedger::money($data['amount']);

            if ($amount <= 0) {
                throw ValidationException::withMessages(['amount' => 'The amount must be greater than zero.']);
            }

            if ($amount > FeePayment::MAX_AMOUNT) {
                throw ValidationException::withMessages(['amount' => 'The amount exceeds the maximum supported value.']);
            }

            $summary = app(HostelFeeService::class)->summaryFor($locked);

            $this->assertWithinOutstanding(
                $amount,
                $summary['outstanding'],
                $this->pendingApprovalSum((int) $locked->college_id, 'hostel_fee_assignment_id', (int) $locked->getKey()),
            );

            $this->assertNoDuplicateReference((int) $locked->college_id, 'hostel_fee_assignment_id', $locked->getKey(), $data, $amount);

            $token = trim((string) ($data['submission_token'] ?? ''));
            $status = $this->statusFor((int) $locked->college_id, $actor);

            $payment = $this->createPaymentRecord([
                'college_id' => $locked->college_id,
                'student_fee_assignment_id' => null,
                'student_enrollment_id' => $locked->hostelAllocation->student_enrollment_id,
                'fee_structure_id' => null,
                'transport_fee_assignment_id' => null,
                'hostel_fee_assignment_id' => $locked->getKey(),
                'payment_number' => $this->numbers->execute((int) $locked->college_id),
                'payment_date' => $data['payment_date'],
                'payment_mode' => $data['payment_mode'],
                'amount' => $amount,
                'reference_number' => ($data['reference_number'] ?? null) ?: null,
                'submission_token' => $token !== '' ? $token : null,
                'status' => $status,
                'remarks' => ($data['remarks'] ?? null) ?: null,
                'collected_by' => $actor->getKey(),
                'collected_at' => $status === FeePayment::STATUS_COMPLETED ? now() : null,
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ], $token);

            return $this->settleOrQueue($payment, $actor);
        });
    }

    /**
     * The status a brand-new collection is born with.
     *
     * `completed` when either:
     *   * the college has not enabled Approval Management → Fees → Fee
     *     Collection (the default, so existing behaviour is untouched), or
     *   * the actor is a TRUSTED AUTHORITY for this college — the platform Super
     *     Admin or this college's College Admin. They are the two reserved
     *     system roles and are allowed to perform their own fee collections
     *     without a second approver.
     *
     * Every other user's collection is born `pending_approval` when the setting
     * is on, whatever permissions they hold — holding `fee_collections.approve`
     * makes a user an approver of OTHER people's collections, not of their own.
     */
    private function statusFor(int $collegeId, User $actor): string
    {
        if ($actor->isTrustedAuthority($collegeId)) {
            return FeePayment::STATUS_COMPLETED;
        }

        return $this->approvals->isApprovalRequired($collegeId, 'fees', ApprovalRegistry::ACTION_FEE_COLLECTION)
            ? FeePayment::STATUS_PENDING_APPROVAL
            : FeePayment::STATUS_COMPLETED;
    }

    /**
     * Either record the collection as money (no approval required) or queue the
     * central approval request, inside the caller's transaction.
     *
     * Both branches audit the submission; the approval request itself adds the
     * `approval.requested` entry through the shared ApprovalService.
     */
    private function settleOrQueue(FeePayment $payment, User $actor): FeePayment
    {
        if ($payment->isPendingApproval()) {
            $this->audit->record('fee_payments.submitted_for_approval', $payment, [], $payment->only(self::AUDITED));
            $this->queueApproval($payment, $actor);

            return $payment->refresh();
        }

        $this->audit->record('fee_payments.collected', $payment, [], $payment->only(self::AUDITED));

        return $payment->refresh();
    }

    /**
     * Submit the pending collection to the existing approval framework.
     *
     * The request targets the payment row itself (`fees.fee_collection`), so
     * approving or rejecting it is decided by ApprovalService against this exact
     * collection — there is no parallel money workflow.
     */
    private function queueApproval(FeePayment $payment, User $actor): void
    {
        $payment->loadMissing('studentEnrollment.student');
        $student = $payment->studentEnrollment?->student;

        $this->approvals->submitRequest([
            'module' => 'fees',
            'action' => ApprovalRegistry::ACTION_FEE_COLLECTION,
            'target_type' => 'fees.'.ApprovalRegistry::ACTION_FEE_COLLECTION,
            'target_id' => $payment->getKey(),
            'title' => 'Fee collection '.$payment->payment_number.' — '.number_format((float) $payment->amount, 2),
            'description' => 'Collection recorded by '.$actor->name
                .($student ? ' for '.$student->fullName() : '')
                .'. It is not counted as collected until an authorized approver finalizes it.',
            'payload' => [
                'payment_number' => $payment->payment_number,
                'amount' => (float) $payment->amount,
                'payment_date' => $payment->payment_date?->format('Y-m-d'),
                'payment_mode' => $payment->payment_mode,
                'reference_number' => $payment->reference_number,
                'student_fee_assignment_id' => $payment->student_fee_assignment_id,
                'transport_fee_assignment_id' => $payment->transport_fee_assignment_id,
                'hostel_fee_assignment_id' => $payment->hostel_fee_assignment_id,
            ],
            'request_reason' => $payment->remarks,
        ], (int) $payment->college_id, (int) $actor->getKey());
    }

    /**
     * Amount already submitted against one assignment and still awaiting an
     * approval decision.
     *
     * It reduces what may be submitted again (so a double submission cannot
     * reserve the same balance twice) WITHOUT ever being counted as collected:
     * balances, receipts and reports read completed rows only.
     */
    private function pendingApprovalSum(int $collegeId, string $targetColumn, int $targetId): float
    {
        return FeeLedger::money((float) FeePayment::withoutGlobalScopes()
            ->where('college_id', $collegeId)
            ->where($targetColumn, $targetId)
            ->whereNull('deleted_at')
            ->where('status', FeePayment::STATUS_PENDING_APPROVAL)
            ->sum('amount'));
    }

    /**
     * The monetary cap, stated once for all three collection paths.
     *
     * A collection awaiting approval is not counted as paid, so the ledger's
     * `outstanding` still contains it. It IS however committed money-in-waiting
     * for a specific student, so it has to be reserved: the cap is
     *
     *     this amount + everything already awaiting approval <= outstanding
     *
     * Without the pending term, a second submission would be validated against
     * the full balance and the same money could be queued twice (or three
     * times). Approving such a queue would then push the ledger past the
     * assigned amount, or force the later approvals to fail one by one.
     */
    private function assertWithinOutstanding(float $amount, float $outstanding, float $pendingApproval): void
    {
        $committed = FeeLedger::money($amount + $pendingApproval);

        if ($committed <= FeeLedger::money($outstanding) + FeeLedger::TOLERANCE) {
            return;
        }

        $available = FeeLedger::money(max(0.0, $outstanding - $pendingApproval));

        $message = 'The amount exceeds the outstanding balance of '.number_format($outstanding, 2).'.'
            .' Only '.number_format($available, 2).' is still available';

        $message .= $pendingApproval > 0
            ? ' after '.number_format($pendingApproval, 2).' already awaiting approval.'
            : '.';

        throw ValidationException::withMessages(['amount' => $message]);
    }

    /**
     * A collection that is still awaiting an approval decision is not money and
     * must not be rewritten or reversed directly: an approver decides it, or the
     * submitter withdraws the request and it is reversed through the audited
     * cancellation path (see ApprovalService::withdrawFeeCollection()).
     */
    private function assertNotAwaitingApproval(FeePayment $payment, string $field = 'status'): void
    {
        if (! $payment->isPendingApproval()) {
            return;
        }

        throw ValidationException::withMessages([
            $field => 'This collection is awaiting approval. An approver must approve or reject it before it can be changed.',
        ]);
    }

    /**
     * Create the payment row (shared by every collection path) and translate a

     * double-submission collision into a friendly validation error.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function createPaymentRecord(array $attributes, string $token): FeePayment
    {
        try {
            return FeePayment::create($attributes);
        } catch (UniqueConstraintViolationException $exception) {
            // Only the double-submission guard is translated: a payment-number
            // collision (which cannot happen, the number is generated under
            // the same lock) keeps bubbling up.
            if ($token === '' || ! str_contains($exception->getMessage(), 'submission')) {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'amount' => 'This collection was already recorded — the form appears to have been submitted twice.',
            ]);
        }
    }

    /**
     * Correct the descriptive fields of a completed collection.
     *
     * The amount is deliberately immutable — money that was received is never
     * rewritten. Cancel the payment (which audits the reversal) and collect the
     * correct amount instead.
     *
     * @param  array{payment_date?: string, payment_mode?: string, reference_number?: string|null, remarks?: string|null}  $data
     */
    public function update(FeePayment $payment, array $data, User $actor): FeePayment
    {
        $this->assertTenant($payment);

        return DB::transaction(function () use ($payment, $data, $actor): FeePayment {
            $this->assertNotAwaitingApproval($payment, 'payment_date');

            if ($payment->isCancelled()) {
                throw ValidationException::withMessages([
                    'payment_date' => 'A cancelled payment can no longer be edited.',
                ]);
            }

            $old = $payment->only(self::AUDITED);

            foreach (['payment_date', 'payment_mode', 'reference_number', 'remarks'] as $field) {
                if (! array_key_exists($field, $data)) {
                    continue;
                }

                $payment->{$field} = in_array($field, ['reference_number', 'remarks'], true)
                    ? ($data[$field] ?: null)
                    : $data[$field];
            }

            $targetColumn = $payment->student_fee_assignment_id !== null ? 'student_fee_assignment_id' : ($payment->transport_fee_assignment_id !== null ? 'transport_fee_assignment_id' : 'hostel_fee_assignment_id');
            $targetId = (int) ($payment->student_fee_assignment_id ?? $payment->transport_fee_assignment_id ?? $payment->hostel_fee_assignment_id);
            $this->assertNoDuplicateReference(
                (int) $payment->college_id,
                $targetColumn,
                $targetId,
                [
                    'payment_date' => $payment->payment_date?->format('Y-m-d'),
                    'payment_mode' => $payment->payment_mode,
                    'reference_number' => $payment->reference_number,
                ],
                (float) $payment->amount,
                $payment->getKey(),
            );

            $payment->updated_by = $actor->getKey();
            $payment->save();

            $this->audit->record('fee_payments.updated', $payment, $old, $payment->only(self::AUDITED));

            return $payment->refresh();
        });
    }

    /**
     * Reverse a collection.
     *
     * The row is kept and flagged cancelled, so the audit trail survives and the
     * money immediately stops counting towards the paid amount. A payment that
     * has already been (partly) refunded cannot be cancelled — return the money
     * through the Refunds module instead.
     */
    public function cancel(FeePayment $payment, User $actor, ?string $reason): FeePayment
    {
        $this->assertTenant($payment);

        return DB::transaction(function () use ($payment, $actor, $reason): FeePayment {
            if ($payment->isCancelled()) {
                throw ValidationException::withMessages([
                    'status' => 'This payment is already cancelled.',
                ]);
            }

            $this->assertNotAwaitingApproval($payment);

            $refunded = (float) FeeRefund::withoutGlobalScopes()
                ->where('fee_payment_id', $payment->getKey())
                ->where('college_id', $payment->college_id)
                ->whereNotIn('status', FeeRefund::INVALID_STATUSES)
                ->sum('amount');

            if ($refunded > 0) {
                throw ValidationException::withMessages([
                    'status' => 'This payment has refunds recorded against it and cannot be cancelled.',
                ]);
            }

            $old = $payment->only(self::AUDITED);

            $payment->status = FeePayment::STATUS_CANCELLED;
            $payment->cancelled_by = $actor->getKey();
            $payment->cancelled_at = now();
            $payment->cancellation_reason = $reason ?: null;
            $payment->updated_by = $actor->getKey();
            $payment->save();

            $this->audit->record('fee_payments.cancelled', $payment, $old, $payment->only(self::AUDITED));

            return $payment->refresh();
        });
    }

    /**
     * Soft delete a collection (data-entry correction).
     *
     * A deleted payment stops counting towards the paid amount exactly like a
     * cancelled one; the difference is only that the row is hidden from the
     * collection list while the audit trail keeps it. Payments carrying refunds
     * can never be deleted.
     */
    public function delete(FeePayment $payment, User $actor): void
    {
        $this->assertTenant($payment);

        DB::transaction(function () use ($payment): void {
            $this->assertNotAwaitingApproval($payment);

            $refunds = FeeRefund::withoutGlobalScopes()
                ->where('fee_payment_id', $payment->getKey())
                ->where('college_id', $payment->college_id)
                ->exists();

            if ($refunds) {
                throw ValidationException::withMessages([
                    'status' => 'This payment has refunds recorded against it and cannot be deleted.',
                ]);
            }

            $snapshot = $payment->only(self::AUDITED);
            $payment->delete();

            $this->audit->record('fee_payments.deleted', $payment, $snapshot, []);
        });
    }

    /**
     * Duplicate-submission guard.
     *
     * Instalments paid in cash have no reference number, and several genuine
     * collections on the same day are legitimate — those are only bounded by the
     * outstanding balance. A payment WITH a reference (cheque / UTR / gateway
     * reference) is a bank-side identifier, so the same reference, mode, amount
     * and date against the same assignment (tuition OR transport) is a
     * re-submitted form, not a second payment. The check runs while the
     * assignment row is locked, so two concurrent submissions cannot both pass
     * it.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertNoDuplicateReference(
        int $collegeId,
        string $targetColumn,
        int $targetId,
        array $data,
        float $amount,
        ?int $ignorePaymentId = null,
    ): void {
        $reference = trim((string) ($data['reference_number'] ?? ''));

        if ($reference === '') {
            return;
        }

        $duplicate = FeePayment::withoutGlobalScopes()
            ->where('college_id', $collegeId)
            ->where($targetColumn, $targetId)
            // Completed AND pending rows: the same bank reference cannot be
            // queued twice, and cannot be queued while it is already money.
            ->whereIn('status', [FeePayment::STATUS_COMPLETED, FeePayment::STATUS_PENDING_APPROVAL])
            ->whereNull('deleted_at')
            ->where('payment_mode', $data['payment_mode'] ?? null)
            ->where('reference_number', $reference)
            ->where('amount', $amount)
            ->whereDate('payment_date', $data['payment_date'] ?? null)
            ->when($ignorePaymentId !== null, fn ($query) => $query->whereKeyNot($ignorePaymentId))
            ->first();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'reference_number' => "Payment {$duplicate->payment_number} already records this reference, amount and date. Cancel that entry first if it was a mistake.",
            ]);
        }
    }

    /**
     * The guard states its own college explicitly, so it also works when the
     * service is called outside an HTTP request. Both the assignment (create
     * path — tuition or transport) and the payment itself (read/mutate paths)
     * are guarded.
     */
    private function assertTenant(FeePayment|StudentFeeAssignment|StudentTransportFeeAssignment|HostelFeeAssignment $record): void
    {
        $collegeId = app(TenantContext::class)->id();

        abort_unless($collegeId !== null && (int) $record->college_id === (int) $collegeId, 403);
    }
}
