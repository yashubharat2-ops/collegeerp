<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Actions\GenerateFeePaymentNumber;
use App\Domain\Finance\Support\FeeLedger;
use App\Models\FeePayment;
use App\Models\FeeRefund;
use App\Models\StudentFeeAssignment;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * FeeCollectionService — records and reverses student fee collections.
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
 */
class FeeCollectionService
{
    private const AUDITED = [
        'student_fee_assignment_id',
        'student_enrollment_id',
        'fee_structure_id',
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
    ) {
    }

    /**
     * Record a collection against an assignment.
     *
     * @param  array{payment_date: string, payment_mode: string, amount: mixed, reference_number?: string|null, remarks?: string|null}  $data
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

            if ($amount > $summary['outstanding'] + FeeLedger::TOLERANCE) {
                throw ValidationException::withMessages([
                    'amount' => 'The amount exceeds the outstanding balance of '.number_format($summary['outstanding'], 2).'.',
                ]);
            }

            $this->assertNoDuplicateReference($locked, $data, $amount);

            $payment = FeePayment::create([
                'college_id' => $locked->college_id,
                'student_fee_assignment_id' => $locked->getKey(),
                'student_enrollment_id' => $locked->student_enrollment_id,
                'fee_structure_id' => $locked->fee_structure_id,
                'payment_number' => $this->numbers->execute((int) $locked->college_id),
                'payment_date' => $data['payment_date'],
                'payment_mode' => $data['payment_mode'],
                'amount' => $amount,
                'reference_number' => ($data['reference_number'] ?? null) ?: null,
                'status' => FeePayment::STATUS_COMPLETED,
                'remarks' => ($data['remarks'] ?? null) ?: null,
                'collected_by' => $actor->getKey(),
                'collected_at' => now(),
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);

            $this->audit->record('fee_payments.collected', $payment, [], $payment->only(self::AUDITED));

            return $payment->refresh();
        });
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

            $this->assertNoDuplicateReference($payment->assignment()->firstOrFail(), [
                'payment_date' => $payment->payment_date?->format('Y-m-d'),
                'payment_mode' => $payment->payment_mode,
                'reference_number' => $payment->reference_number,
            ], (float) $payment->amount, $payment->getKey());

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
     * and date against the same assignment is a re-submitted form, not a second
     * payment. The check runs while the assignment row is locked, so two
     * concurrent submissions cannot both pass it.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertNoDuplicateReference(
        StudentFeeAssignment $assignment,
        array $data,
        float $amount,
        ?int $ignorePaymentId = null,
    ): void {
        $reference = trim((string) ($data['reference_number'] ?? ''));

        if ($reference === '') {
            return;
        }

        $duplicate = FeePayment::withoutGlobalScopes()
            ->where('college_id', $assignment->college_id)
            ->where('student_fee_assignment_id', $assignment->getKey())
            ->where('status', FeePayment::STATUS_COMPLETED)
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

    private function assertTenant(FeePayment $payment): void
    {
        $collegeId = app(TenantContext::class)->id();

        abort_unless($collegeId !== null && (int) $payment->college_id === (int) $collegeId, 403);
    }
}
