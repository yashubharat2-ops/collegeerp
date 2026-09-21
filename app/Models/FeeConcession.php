<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;

/**
 * FeeConcession — a discount / concession on one StudentFeeAssignment.
 *
 * Two kinds, expressed by `type` and `value`:
 *
 *   fixed       → value is money, amount = value
 *   percentage  → value is 0–100, amount = round(assigned_amount × value ÷ 100, 2)
 *
 * `amount` is always computed server-side by the service from the assignment's
 * snapshot; the browser can never submit it.
 *
 * Approval is server-controlled through approved_by / approved_at. Until a
 * concession is APPROVED its approval fields stay null; the amount still counts
 * towards the student's balance unless the concession is rejected or cancelled
 * (`INVALID_STATUSES`), which keeps a college's concession policy from being
 * silently ignored.
 */
class FeeConcession extends Model
{
    use HasFactory, SoftDeletes, BelongsToCollege;

    public const TYPE_FIXED = 'fixed';
    public const TYPE_PERCENTAGE = 'percentage';

    public const TYPES = [
        self::TYPE_FIXED,
        self::TYPE_PERCENTAGE,
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_CANCELLED,
    ];

    /** Statuses that stop reducing the payable amount. */
    public const INVALID_STATUSES = [
        self::STATUS_REJECTED,
        self::STATUS_CANCELLED,
    ];

    /** The largest amount the decimal(12,2) columns can hold. */
    public const MAX_AMOUNT = 9999999999.99;

    protected $fillable = [
        'college_id',
        'student_fee_assignment_id',
        'type',
        'value',
        'amount',
        'reason',
        'status',
        'approved_by',
        'approved_at',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'amount' => 'decimal:2',
            'approved_at' => 'datetime',
        ];
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED && $this->approved_by !== null;
    }

    public function countsTowardsBalance(): bool
    {
        return ! in_array($this->status, self::INVALID_STATUSES, true);
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(StudentFeeAssignment::class, 'student_fee_assignment_id');
    }

    public function studentFeeAssignment(): BelongsTo
    {
        return $this->belongsTo(StudentFeeAssignment::class, 'student_fee_assignment_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
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
        static::saving(function (self $concession): void {
            if ($concession->amount !== null && (float) $concession->amount < 0) {
                throw ValidationException::withMessages([
                    'amount' => 'The concession amount must not be negative.',
                ]);
            }

            if ($concession->type === self::TYPE_PERCENTAGE
                && $concession->value !== null
                && ((float) $concession->value < 0 || (float) $concession->value > 100)) {
                throw ValidationException::withMessages([
                    'value' => 'A percentage concession must be between 0 and 100.',
                ]);
            }
        });
    }
}
