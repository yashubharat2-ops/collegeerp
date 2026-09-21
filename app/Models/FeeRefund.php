<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

/**
 * FeeRefund — money returned against an actual, non-cancelled FeePayment.
 *
 * A refund never exists on its own: it always references the payment it reverses
 * part of, so no second, independent amount is ever introduced. The refundable
 * amount is computed live from the payment minus its valid refunds, while the
 * assignment row is locked — concurrent requests therefore cannot over-refund.
 *
 * Refunds carry no soft deletes: `status` carries the lifecycle
 * (pending → approved → processed, or rejected / cancelled) and every row is
 * kept forever. A cancelled or rejected refund simply stops reducing the net
 * collected amount.
 */
class FeeRefund extends Model
{
    use HasFactory, BelongsToCollege;

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_PROCESSED = 'processed';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_PROCESSED,
        self::STATUS_REJECTED,
        self::STATUS_CANCELLED,
    ];

    /** Statuses that stop reducing the net collected amount. */
    public const INVALID_STATUSES = [
        self::STATUS_REJECTED,
        self::STATUS_CANCELLED,
    ];

    /** The largest amount the decimal(12,2) column can hold. */
    public const MAX_AMOUNT = 9999999999.99;

    protected $fillable = [
        'college_id',
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
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'refund_date' => 'date',
            'approved_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function countsTowardsRefunded(): bool
    {
        return ! in_array($this->status, self::INVALID_STATUSES, true);
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isProcessed(): bool
    {
        return $this->status === self::STATUS_PROCESSED;
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(FeePayment::class, 'fee_payment_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    protected static function booted(): void
    {
        static::saving(function (self $refund): void {
            if ($refund->amount !== null && (float) $refund->amount <= 0) {
                throw ValidationException::withMessages([
                    'amount' => 'The refund amount must be greater than zero.',
                ]);
            }
        });
    }
}
