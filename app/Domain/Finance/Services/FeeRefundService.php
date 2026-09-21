<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Actions\GenerateFeeRefundNumber;
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
 * FeeRefundService — money returned against an actual, non-cancelled collection.
 *
 * Invariants, always evaluated under an assignment row lock so concurrent
 * requests cannot over-refund:
 *
 *   amount > 0
 *   amount ≤ payment.amount − Σ (this payment's valid refunds)
 *   the referenced payment is completed (a cancelled/reversed payment is
 *   refundable never — it was never money)
 *
 * The refund lifecycle is status-driven:
 *
 *   pending → approved → processed        (processed = money handed back)
 *   pending/approved → rejected | cancelled
 *
 * Only approved refunds can be processed, and approval metadata is written only
 * by approve(). Rejected and cancelled refunds stop reducing the net collected
 * amount; every row stays in the database forever.
 */
class FeeRefundService
{
    private const AUDITED = [
        'fee_payment_id',
        'refund_number',
        'refund_date',
        'amount',
        'reason',
        'status',
        'approved_by',
        'approved_at',
        'processed_by',
        'processed_at',
    ];

    /** Statuses a user may set directly through an update. */
    private const SETTABLE_STATUSES = [
        FeeRefund::STATUS_PENDING,
        FeeRefund::STATUS_REJECTED,
        FeeRefund::STATUS_CANCELLED,
    ];

    public function __construct(
        private readonly GenerateFeeRefundNumber $numbers,
        private readonly AuditLogService $audit,
    ) {
    }

    /**
     * @param  array{refund_date: string, amount: mixed, reason?: string|null}  $data
     */
    public function create(FeePayment $payment, array $data, User $actor): FeeRefund
    {
        $this->assertTenant($payment);

        return DB::transaction(function () use ($payment, $data, $actor): FeeRefund {
            $locked = $this->lockPayment($payment);

            $amount = FeeLedger::money($data['amount']);
            $this->assertRefundable($locked, $amount);

            $refund = FeeRefund::create([
                'college_id' => $locked->college_id,
                'fee_payment_id' => $locked->getKey(),
                'refund_number' => $this->numbers->execute((int) $locked->college_id),
                'refund_date' => $data['refund_date'],
                'amount' => $amount,
                'reason' => ($data['reason'] ?? null) ?: null,
                'status' => FeeRefund::STATUS_PENDING,
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);

            $this->audit->record('fee_refunds.created', $refund, [], $refund->only(self::AUDITED));

            return $refund->refresh();
        });
    }

    /**
     * @param  array{refund_date?: string, amount?: mixed, reason?: string|null, status?: string}  $data
     */
    public function update(FeeRefund $refund, array $data, User $actor): FeeRefund
    {
        $this->assertTenant($refund);

        return DB::transaction(function () use ($refund, $data, $actor): FeeRefund {
            if ($refund->isProcessed() || in_array($refund->status, FeeRefund::INVALID_STATUSES, true)) {
                throw ValidationException::withMessages([
                    'status' => 'A processed, rejected or cancelled refund can no longer be edited.',
                ]);
            }

            $old = $refund->only(self::AUDITED);
            $payment = $this->lockPayment($refund->payment()->firstOrFail());

            $amountChanged = array_key_exists('amount', $data);

            if ($amountChanged) {
                $refund->amount = FeeLedger::money($data['amount']);
                $this->assertRefundable($payment, (float) $refund->amount, $refund->getKey());

                // The approval was for a specific amount.
                $refund->status = FeeRefund::STATUS_PENDING;
                $refund->approved_by = null;
                $refund->approved_at = null;
            }

            if (array_key_exists('refund_date', $data)) {
                $refund->refund_date = $data['refund_date'];
            }

            if (array_key_exists('reason', $data)) {
                $refund->reason = ($data['reason'] ?? null) ?: null;
            }

            if (array_key_exists('status', $data) && in_array($data['status'], self::SETTABLE_STATUSES, true)) {
                $refund->status = $data['status'];
            }

            $refund->updated_by = $actor->getKey();
            $refund->save();

            $this->audit->record('fee_refunds.updated', $refund, $old, $refund->only(self::AUDITED));

            return $refund->refresh();
        });
    }

    /**
     * Approve a refund. The approval metadata is written only here.
     */
    public function approve(FeeRefund $refund, User $actor): FeeRefund
    {
        $this->assertTenant($refund);

        return DB::transaction(function () use ($refund, $actor): FeeRefund {
            if ($refund->status !== FeeRefund::STATUS_PENDING) {
                throw ValidationException::withMessages([
                    'status' => 'Only a pending refund can be approved.',
                ]);
            }

            $old = $refund->only(self::AUDITED);
            $payment = $this->lockPayment($refund->payment()->firstOrFail());

            // The refundable amount is re-checked at approval time.
            $this->assertRefundable($payment, (float) $refund->amount, $refund->getKey());

            $refund->status = FeeRefund::STATUS_APPROVED;
            $refund->approved_by = $actor->getKey();
            $refund->approved_at = now();
            $refund->updated_by = $actor->getKey();
            $refund->save();

            $this->audit->record('fee_refunds.approved', $refund, $old, $refund->only(self::AUDITED));

            return $refund->refresh();
        });
    }

    /**
     * Mark an approved refund as paid out.
     *
     * The refund has reduced the net collected amount since it was recorded, so
     * processing it changes no balance — it records that the money has actually
     * left the college, with who did it and when.
     */
    public function process(FeeRefund $refund, User $actor): FeeRefund
    {
        $this->assertTenant($refund);

        return DB::transaction(function () use ($refund, $actor): FeeRefund {
            if (! $refund->isApproved()) {
                throw ValidationException::withMessages([
                    'status' => 'Only an approved refund can be processed.',
                ]);
            }

            $old = $refund->only(self::AUDITED);

            $refund->status = FeeRefund::STATUS_PROCESSED;
            $refund->processed_by = $actor->getKey();
            $refund->processed_at = now();
            $refund->updated_by = $actor->getKey();
            $refund->save();

            $this->audit->record('fee_refunds.processed', $refund, $old, $refund->only(self::AUDITED));

            return $refund->refresh();
        });
    }

    // ------------------------------------------------------------- helpers

    /**
     * Lock the assignment row (serializing every money movement on it) and return
     * the payment, re-read through the tenant-scoped model.
     */
    private function lockPayment(FeePayment $payment): FeePayment
    {
        /** @var FeePayment $locked */
        $locked = FeePayment::withoutGlobalScopes()
            ->whereKey($payment->getKey())
            ->whereNull('deleted_at')
            ->lockForUpdate()
            ->firstOrFail();

        if ($locked->isCancelled()) {
            throw ValidationException::withMessages([
                'fee_payment_id' => 'A cancelled payment cannot be refunded.',
            ]);
        }

        StudentFeeAssignment::withoutGlobalScopes()
            ->whereKey($locked->student_fee_assignment_id)
            ->lockForUpdate()
            ->first();

        return $locked;
    }

    private function assertRefundable(FeePayment $payment, float $amount, ?int $ignoreRefundId = null): void
    {
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'The refund amount must be greater than zero.']);
        }

        if ($amount > FeeRefund::MAX_AMOUNT) {
            throw ValidationException::withMessages(['amount' => 'The amount exceeds the maximum supported value.']);
        }

        $alreadyRefunded = (float) FeeRefund::withoutGlobalScopes()
            ->where('fee_payment_id', $payment->getKey())
            ->where('college_id', $payment->college_id)
            ->whereNotIn('status', FeeRefund::INVALID_STATUSES)
            ->when($ignoreRefundId !== null, fn ($query) => $query->whereKeyNot($ignoreRefundId))
            ->sum('amount');

        $refundable = FeeLedger::money((float) $payment->amount - $alreadyRefunded);

        if ($amount > $refundable + FeeLedger::TOLERANCE) {
            throw ValidationException::withMessages([
                'amount' => 'The refund exceeds the refundable amount of '.number_format(max(0, $refundable), 2).'.',
            ]);
        }
    }

    private function assertTenant(FeeRefund $refund): void
    {
        $collegeId = app(TenantContext::class)->id();

        abort_unless($collegeId !== null && (int) $refund->college_id === (int) $collegeId, 403);
    }
}
