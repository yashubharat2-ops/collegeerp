<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * StudentFeeAssignment — one FeeStructure assigned to one StudentEnrollment.
 *
 * The student, academic year, program and academic term are NOT copied here:
 * they are reached through the enrollment (student_enrollment_id), which is the
 * single source of truth for the student's academic context. Only the fee plan
 * reference and the amount snapshot are stored.
 *
 * `assigned_amount` is captured once, server-side, from the fee structure's
 * active components when the assignment is created, and is immutable: editing
 * the fee structure later never re-prices an existing assignment. Every balance
 * (concession cap, collected, outstanding) is derived live from this snapshot
 * plus the transaction rows — there is no balance table anywhere.
 *
 * Tenant isolation: BelongsToCollege + CollegeScope.
 */
class StudentFeeAssignment extends Model
{
    use HasFactory, SoftDeletes, BelongsToCollege;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    /** Statuses that still carry a payable balance. */
    public const PAYABLE_STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_COMPLETED,
    ];

    protected $fillable = [
        'college_id',
        'student_enrollment_id',
        'fee_structure_id',
        'assigned_amount',
        'assigned_at',
        'status',
        'remarks',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'assigned_amount' => 'decimal:2',
            'assigned_at' => 'date',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /** A cancelled assignment carries no payable balance. */
    public function isPayable(): bool
    {
        return in_array($this->status, self::PAYABLE_STATUSES, true);
    }

    public function studentEnrollment(): BelongsTo
    {
        return $this->belongsTo(StudentEnrollment::class);
    }

    public function feeStructure(): BelongsTo
    {
        return $this->belongsTo(FeeStructure::class);
    }

    /** All collections recorded against this assignment. */
    public function payments(): HasMany
    {
        return $this->hasMany(FeePayment::class);
    }

    /** Collections that count towards the paid amount (cancellations excluded). */
    public function validPayments(): HasMany
    {
        return $this->hasMany(FeePayment::class)
            ->where('status', FeePayment::STATUS_COMPLETED);
    }

    /** All concessions granted on this assignment. */
    public function concessions(): HasMany
    {
        return $this->hasMany(FeeConcession::class);
    }

    /** Concessions that reduce the payable amount (rejected/cancelled excluded). */
    public function validConcessions(): HasMany
    {
        return $this->hasMany(FeeConcession::class)
            ->whereNotIn('status', FeeConcession::INVALID_STATUSES);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
