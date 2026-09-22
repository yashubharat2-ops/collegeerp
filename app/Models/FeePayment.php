<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * FeePayment — one recorded fee collection (Finance / Fees).
 *
 * This is the ONLY row in the system that carries a collected amount: receipts
 * are a printable projection of it and never store a second amount, so there is
 * exactly one financial fact per collection.
 *
 * Cancelled payments ("reversed") stay in the table for the audit trail but are
 * excluded from every balance calculation — the payment row is never deleted or
 * rewritten to zero.
 */
class FeePayment extends Model
{
    use HasFactory, SoftDeletes, BelongsToCollege;

    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    /**
     * Payment modes are extensible strings, not an enum: a college may add
     * modes without a migration, and the application never enumerates them
     * beyond suggesting the conventional set below.
     */
    public const MODE_CASH = 'cash';
    public const MODE_CHEQUE = 'cheque';
    public const MODE_BANK_TRANSFER = 'bank_transfer';
    public const MODE_ONLINE = 'online';
    public const MODE_UPI = 'upi';
    public const MODE_OTHER = 'other';

    public const MODES = [
        self::MODE_CASH,
        self::MODE_CHEQUE,
        self::MODE_BANK_TRANSFER,
        self::MODE_ONLINE,
        self::MODE_UPI,
        self::MODE_OTHER,
    ];

    /** The largest amount the decimal(12,2) column can hold. */
    public const MAX_AMOUNT = 9999999999.99;

    protected $fillable = [
        'college_id',
        'student_fee_assignment_id',
        'student_enrollment_id',
        'fee_structure_id',
        // Exactly one of student_fee_assignment_id / transport_fee_assignment_id
        // is set (transport fee collections reuse this same payment row).
        'transport_fee_assignment_id',
        'payment_number',
        'payment_date',
        'payment_mode',
        'amount',
        'reference_number',
        // Idempotency token: the form's double-submission guard (per college).
        'submission_token',
        'status',
        'remarks',
        'collected_by',
        'collected_at',
        'cancelled_by',
        'cancelled_at',
        'cancellation_reason',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'payment_date' => 'date',
            'collected_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    /** Only a completed collection counts towards the paid amount. */
    public function countsTowardsPaid(): bool
    {
        return $this->status === self::STATUS_COMPLETED && $this->deleted_at === null;
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(StudentFeeAssignment::class, 'student_fee_assignment_id');
    }

    public function studentFeeAssignment(): BelongsTo
    {
        return $this->belongsTo(StudentFeeAssignment::class, 'student_fee_assignment_id');
    }

    /** The transport fee assignment, for transport fee collections. */
    public function transportFeeAssignment(): BelongsTo
    {
        return $this->belongsTo(StudentTransportFeeAssignment::class, 'transport_fee_assignment_id');
    }

    public function studentEnrollment(): BelongsTo
    {
        return $this->belongsTo(StudentEnrollment::class);
    }

    public function feeStructure(): BelongsTo
    {
        return $this->belongsTo(FeeStructure::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(FeeRefund::class, 'fee_payment_id');
    }

    /** Refunds that reduce the net collected amount. */
    public function validRefunds(): HasMany
    {
        return $this->hasMany(FeeRefund::class, 'fee_payment_id')
            ->whereNotIn('status', FeeRefund::INVALID_STATUSES);
    }

    public function collector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collected_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** Server-side helper: how much of this payment may still be refunded. */
    public function refundableAmount(): float
    {
        if ($this->isCancelled()) {
            return 0.0;
        }

        $refunded = (float) $this->validRefunds()->sum('amount');

        return round((float) $this->amount - $refunded, 2);
    }
}
